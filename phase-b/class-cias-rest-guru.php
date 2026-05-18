<?php
/**
 * CIAS Phase B – REST: AI Guru Chat (Async)
 *
 * Replaces the synchronous admin-ajax.php caig_guru_chat handler.
 * Claude is NEVER called inside this endpoint.
 *
 * Endpoints:
 *   POST /wp-json/cias/v1/guru/chat           Submit a message → queue job → 202
 *   GET  /wp-json/cias/v1/guru/history        Chat history for current user
 *   GET  /wp-json/cias/v1/guru/session/{id}   Single session messages
 *   POST /wp-json/cias/v1/guru/session/start  Start new session, return session_id
 *
 * Client flow:
 *   1. POST /guru/chat { message, session_id?, image_object_key? }
 *   2. Server saves user message to DB → queues 'guru_chat' job → returns { job_id, session_id }
 *   3. Client polls GET /job/{job_id}/status every 2s
 *   4. When done: status.result.response contains AI reply
 *
 * Backward compat: The old AJAX action 'caig_guru_chat' still works for non-upgraded clients.
 *
 * @package CIAS\PhaseB
 * @since   3.19.0
 */
defined( 'ABSPATH' ) || exit;

class CIAS_REST_Guru {

    const NAMESPACE = 'cias/v1';

    public static function register_routes(): void {
        register_rest_route( self::NAMESPACE, '/guru/chat', [
            'methods'             => 'POST',
            'callback'            => [ __CLASS__, 'submit_message' ],
            'permission_callback' => [ __CLASS__, 'is_logged_in' ],
            'args'                => [
                'message'          => [ 'required' => true,  'type' => 'string', 'sanitize_callback' => 'sanitize_textarea_field' ],
                'session_id'       => [ 'required' => false, 'type' => 'string', 'sanitize_callback' => 'sanitize_text_field', 'default' => '' ],
                'image_object_key' => [ 'required' => false, 'type' => 'string', 'sanitize_callback' => 'sanitize_text_field', 'default' => '' ],
                'image_mime'       => [ 'required' => false, 'type' => 'string', 'sanitize_callback' => 'sanitize_text_field', 'default' => 'image/jpeg' ],
            ],
        ] );

        register_rest_route( self::NAMESPACE, '/guru/history', [
            'methods'             => 'GET',
            'callback'            => [ __CLASS__, 'get_history' ],
            'permission_callback' => [ __CLASS__, 'is_logged_in' ],
            'args'                => [
                'page'        => [ 'type' => 'integer', 'default' => 1 ],
                'per_page'    => [ 'type' => 'integer', 'default' => 20, 'maximum' => 50 ],
                'session_id'  => [ 'type' => 'string', 'default' => '' ],
            ],
        ] );

        register_rest_route( self::NAMESPACE, '/guru/session/start', [
            'methods'             => 'POST',
            'callback'            => [ __CLASS__, 'start_session' ],
            'permission_callback' => [ __CLASS__, 'is_logged_in' ],
        ] );

        register_rest_route( self::NAMESPACE, '/guru/session/(?P<session_id>[a-zA-Z0-9_-]+)', [
            'methods'             => 'GET',
            'callback'            => [ __CLASS__, 'get_session' ],
            'permission_callback' => [ __CLASS__, 'is_logged_in' ],
        ] );
    }

    // ── POST /guru/chat ────────────────────────────────────────────────────────

    public static function submit_message( WP_REST_Request $request ): WP_REST_Response {
        $user_id    = get_current_user_id();
        $message    = $request->get_param('message');
        $session_id = $request->get_param('session_id') ?: self::new_session_id( $user_id );
        $img_key    = $request->get_param('image_object_key');
        $img_mime   = $request->get_param('image_mime');

        // ── Credit check (AI Bot credit system) ────────────────────────────
        $can = CIAS_AI_Bot::can_ask( $user_id );
        if ( ! $can['allowed'] ) {
            return new WP_REST_Response( [
                'error'       => $can['reason'],
                'show_upgrade'=> $can['show_upgrade'] ?? false,
            ], 402 );
        }

        // ── Rate limit ─────────────────────────────────────────────────────
        $rl = CIAS_Queue::rate_check( $user_id, 'guru_chat' );
        if ( ! $rl['allowed'] ) {
            return new WP_REST_Response( [ 'error' => 'Too many messages. Please wait a moment.' ], 429 );
        }

        // ── Persist user message immediately (Phase A handler fires here) ──
        $has_image = (bool) $img_key;
        do_action( 'cias_guru_user_message', [
            'session_id' => $session_id,
            'user_id'    => $user_id,
            'body'       => $message,
            'image_data' => null, // Image already in R2 — pass URL instead
            'image_mime' => $img_mime,
            'image_name' => $img_key ? basename( $img_key ) : null,
            'media_url'  => $img_key ? CIAS_R2::public_url_for( $img_key ) : null,
            'tokens'     => null,
            'credits'    => null,
        ] );

        // ── Detect if this is an answer evaluation submission ──────────────
        // Auto-route: if message has image AND looks like answer writing → submit flow
        $is_answer_eval = $has_image && self::detect_answer_writing( $message );

        // ── Push async job ─────────────────────────────────────────────────
        $job_type = $is_answer_eval ? 'ocr' : 'guru_chat';
        $payload  = [
            'user_id'          => $user_id,
            'session_id'       => $session_id,
            'message'          => $message,
            'has_image'        => $has_image,
            'image_r2_key'     => $img_key ?: null,
            'image_mime'       => $img_mime,
            'credit_type'      => $can['type'],
            'is_answer_eval'   => $is_answer_eval,
        ];

        $job_id = CIAS_DB_Phase_B::push_job( $job_type, $payload, priority: 2 );

        // ── Deduct credit now (deduction happens even if job fails later) ──
        CIAS_AI_Bot::deduct_credit( $user_id, $can['type'], $session_id );

        return new WP_REST_Response( [
            'job_id'           => $job_id,
            'session_id'       => $session_id,
            'status'           => 'queued',
            'status_url'       => $job_id ? rest_url("cias/v1/job/{$job_id}/status") : null,
            'is_answer_eval'   => $is_answer_eval,
            'message'          => $is_answer_eval
                ? 'Answer image received. OCR processing in progress...'
                : 'Message received. AI Guru is thinking...',
            'estimated_seconds'=> $has_image ? 20 : 8,
        ], 202 );
    }

    // ── POST /guru/session/start ───────────────────────────────────────────────

    public static function start_session( WP_REST_Request $request ): WP_REST_Response {
        $user_id    = get_current_user_id();
        $session_id = self::new_session_id( $user_id );

        // Store initial session state in Redis (optional, for context persistence)
        CIAS_Queue::session_set( $session_id, [
            'user_id'    => $user_id,
            'started_at' => time(),
            'messages'   => 0,
        ], 7200 ); // 2 hours

        do_action( 'cias_guru_session_start', $user_id, $session_id );

        return new WP_REST_Response( [
            'session_id' => $session_id,
            'expires_in' => 7200,
        ], 200 );
    }

    // ── GET /guru/history ─────────────────────────────────────────────────────

    public static function get_history( WP_REST_Request $request ): WP_REST_Response {
        $user_id    = get_current_user_id();
        $page       = (int) $request->get_param('page');
        $per_page   = (int) $request->get_param('per_page');
        $session_id = sanitize_text_field( $request->get_param('session_id') );
        $offset     = ( $page - 1 ) * $per_page;

        global $wpdb;
        $table = $wpdb->prefix . 'cias_chat_messages';

        $where  = 'user_id = %d';
        $args   = [ $user_id ];

        if ( $session_id ) {
            $where .= ' AND session_id = %s';
            $args[] = $session_id;
        }

        $total = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM $table WHERE $where AND role='user'", ...$args ) );

        // Return sessions (grouped) when no session_id filter
        if ( ! $session_id ) {
            $sessions = $wpdb->get_results( $wpdb->prepare(
                "SELECT session_id,
                        MIN(created_at) AS started_at,
                        MAX(created_at) AS last_msg_at,
                        COUNT(*) AS message_count,
                        SUM(role='user') AS user_messages
                 FROM $table
                 WHERE $where
                 GROUP BY session_id
                 ORDER BY last_msg_at DESC
                 LIMIT %d OFFSET %d",
                ...[...$args, $per_page, $offset]
            ) );

            return new WP_REST_Response( [
                'sessions'    => $sessions,
                'total'       => $total,
                'page'        => $page,
                'total_pages' => (int) ceil( $total / $per_page ),
            ], 200 );
        }

        // Return messages for a specific session
        $messages = $wpdb->get_results( $wpdb->prepare(
            "SELECT id, role, body, message_type, media_url, created_at
             FROM $table
             WHERE $where
             ORDER BY created_at ASC
             LIMIT %d OFFSET %d",
            ...[...$args, $per_page, $offset]
        ) );

        return new WP_REST_Response( [
            'messages'    => $messages,
            'session_id'  => $session_id,
            'total'       => $total,
            'page'        => $page,
            'total_pages' => (int) ceil( $total / $per_page ),
        ], 200 );
    }

    // ── GET /guru/session/{session_id} ────────────────────────────────────────

    public static function get_session( WP_REST_Request $request ): WP_REST_Response {
        $user_id    = get_current_user_id();
        $session_id = sanitize_text_field( $request->get_param('session_id') );
        global $wpdb;

        $messages = $wpdb->get_results( $wpdb->prepare(
            "SELECT id, role, body, message_type, media_url, tokens_used, created_at
             FROM {$wpdb->prefix}cias_chat_messages
             WHERE user_id = %d AND session_id = %s
             ORDER BY created_at ASC",
            $user_id, $session_id
        ) );

        return new WP_REST_Response( [
            'session_id' => $session_id,
            'messages'   => $messages,
        ], 200 );
    }

    // ── Helpers ───────────────────────────────────────────────────────────────

    private static function new_session_id( int $user_id ): string {
        return 'ses_' . $user_id . '_' . substr( wp_generate_uuid4(), 0, 12 );
    }

    /**
     * Detect if a message is likely an answer evaluation submission.
     * Triggers answer-writing pipeline instead of general Guru chat.
     */
    private static function detect_answer_writing( string $message ): bool {
        $lower    = mb_strtolower( $message );
        $keywords = [ 'evaluate', 'check my answer', 'my answer', 'mains answer', 'answer writing',
                      'review my', 'score this', 'feedback on my', 'assess my', 'mark this' ];
        foreach ( $keywords as $kw ) {
            if ( str_contains( $lower, $kw ) ) return true;
        }
        return false;
    }

    public static function is_logged_in(): bool {
        return is_user_logged_in();
    }
}
