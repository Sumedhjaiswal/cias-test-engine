<?php
/**
 * CIAS Phase B – REST: Job Status Polling
 *
 * GET /wp-json/cias/v1/job/{id}/status
 *
 * Frontend polls this every 2–3 seconds after submitting a message or answer.
 * Response includes status + result when done.
 *
 * GET /wp-json/cias/v1/jobs?type=ocr&status=pending  (admin/teacher only)
 *
 * @package CIAS\PhaseB
 * @since   3.19.0
 */
defined( 'ABSPATH' ) || exit;

class CIAS_REST_Status {

    const NAMESPACE = 'cias/v1';

    public static function register_routes(): void {
        register_rest_route( self::NAMESPACE, '/job/(?P<id>\d+)/status', [
            'methods'             => 'GET',
            'callback'            => [ __CLASS__, 'job_status' ],
            'permission_callback' => [ __CLASS__, 'is_logged_in' ],
        ] );

        register_rest_route( self::NAMESPACE, '/jobs', [
            'methods'             => 'GET',
            'callback'            => [ __CLASS__, 'list_jobs' ],
            'permission_callback' => [ __CLASS__, 'is_admin' ],
            'args'                => [
                'type'    => [ 'type' => 'string', 'default' => '' ],
                'status'  => [ 'type' => 'string', 'default' => '' ],
                'page'    => [ 'type' => 'integer', 'default' => 1 ],
                'per_page'=> [ 'type' => 'integer', 'default' => 50 ],
            ],
        ] );

        // Student's own submission status (convenience alias)
        register_rest_route( self::NAMESPACE, '/submission/(?P<id>\d+)/status', [
            'methods'             => 'GET',
            'callback'            => [ __CLASS__, 'submission_status' ],
            'permission_callback' => [ __CLASS__, 'is_logged_in' ],
        ] );
    }

    // ── GET /job/{id}/status ──────────────────────────────────────────────────

    public static function job_status( WP_REST_Request $request ): WP_REST_Response {
        $job_id  = (int) $request->get_param('id');
        $user_id = get_current_user_id();

        global $wpdb;
        $job = $wpdb->get_row( $wpdb->prepare(
            "SELECT id, type, status, result_json, error_message, attempts, max_attempts,
                    created_at, started_at, finished_at, available_at
             FROM " . CIAS_JOB_QUEUE . " WHERE id = %d",
            $job_id
        ) );

        if ( ! $job ) {
            return new WP_REST_Response( [ 'error' => 'Job not found.' ], 404 );
        }

        // Decode result if done
        $result = null;
        if ( $job->status === 'done' && $job->result_json ) {
            $result = json_decode( $job->result_json, true );
        }

        // Estimate time remaining
        $elapsed   = $job->started_at ? time() - strtotime( $job->started_at ) : 0;
        $estimates = [ 'ocr' => 20, 'evaluate' => 30, 'guru_chat' => 8, 'analytics' => 60 ];
        $est_total = $estimates[ $job->type ] ?? 20;
        $remaining = max( 0, $est_total - $elapsed );

        // Friendly status message
        $messages = [
            'pending'    => 'Waiting in queue...',
            'processing' => self::processing_message( $job->type ),
            'done'       => self::done_message( $job->type ),
            'failed'     => 'Processing failed. Retrying...',
            'dead'       => 'Processing failed after multiple attempts. Please try again or contact admin.',
        ];

        // Check if result contains a submission_id we should redirect to
        $next_action = null;
        if ( $job->status === 'done' && $result ) {
            $next_action = self::determine_next_action( $job->type, $result, $user_id );
        }

        return new WP_REST_Response( [
            'job_id'           => $job->id,
            'type'             => $job->type,
            'status'           => $job->status,
            'message'          => $messages[ $job->status ] ?? 'Processing...',
            'result'           => $result,
            'next_action'      => $next_action,
            'elapsed_seconds'  => $elapsed,
            'estimated_remaining' => $job->status === 'processing' ? $remaining : null,
            'attempts'         => (int) $job->attempts,
            'created_at'       => $job->created_at,
            'finished_at'      => $job->finished_at,
        ], 200 );
    }

    // ── GET /submission/{id}/status ───────────────────────────────────────────

    public static function submission_status( WP_REST_Request $request ): WP_REST_Response {
        $submission_id = (int) $request->get_param('id');
        $user_id       = get_current_user_id();

        global $wpdb;
        $sub = $wpdb->get_row( $wpdb->prepare(
            "SELECT s.id, s.status, s.job_id, s.r2_key,
                    ocr.confidence, ocr.raw_text, ocr.confirmed, ocr.legibility,
                    ev.score, ev.max_score, ev.feedback_json,
                    rv.status AS review_status
             FROM " . CIAS_SUBMISSIONS . " s
             LEFT JOIN " . CIAS_OCR_RESULTS . "    ocr ON ocr.submission_id = s.id
             LEFT JOIN " . CIAS_AI_EVALUATIONS . " ev  ON ev.submission_id  = s.id
             LEFT JOIN " . CIAS_TEACHER_REVIEWS . " rv ON rv.submission_id  = s.id
             WHERE s.id = %d AND s.user_id = %d",
            $submission_id, $user_id
        ) );

        if ( ! $sub ) {
            return new WP_REST_Response( [ 'error' => 'Submission not found.' ], 404 );
        }

        if ( $sub->feedback_json ) {
            $sub->feedback = json_decode( $sub->feedback_json, true );
            unset( $sub->feedback_json );
        }

        $sub->image_url = CIAS_R2::public_url_for( $sub->r2_key );

        // Needs student action?
        $needs_action = null;
        if ( $sub->status === 'needs_confirmation' ) {
            $needs_action = [
                'type'    => 'confirm_ocr',
                'url'     => rest_url("cias/v1/answer/{$submission_id}/confirm"),
                'message' => 'Please confirm the extracted text before evaluation.',
                'text'    => $sub->raw_text,
            ];
        }

        return new WP_REST_Response( [
            'submission'   => $sub,
            'needs_action' => $needs_action,
        ], 200 );
    }

    // ── GET /jobs (admin) ─────────────────────────────────────────────────────

    public static function list_jobs( WP_REST_Request $request ): WP_REST_Response {
        global $wpdb;
        $type     = sanitize_key( $request->get_param('type') );
        $status   = sanitize_key( $request->get_param('status') );
        $page     = (int) $request->get_param('page');
        $per_page = (int) $request->get_param('per_page');
        $offset   = ( $page - 1 ) * $per_page;

        $where  = '1=1';
        $args   = [];
        if ( $type )   { $where .= ' AND type=%s';   $args[] = $type; }
        if ( $status ) { $where .= ' AND status=%s'; $args[] = $status; }

        $total = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM " . CIAS_JOB_QUEUE . " WHERE {$where}", ...$args ) );
        $jobs  = $wpdb->get_results( $wpdb->prepare(
            "SELECT id, type, status, priority, attempts, max_attempts, error_message, created_at, started_at, finished_at
             FROM " . CIAS_JOB_QUEUE . " WHERE {$where} ORDER BY created_at DESC LIMIT %d OFFSET %d",
            ...[...$args, $per_page, $offset]
        ) );

        // Summary stats
        $stats = $wpdb->get_results(
            "SELECT type, status, COUNT(*) AS cnt FROM " . CIAS_JOB_QUEUE . "
             WHERE created_at >= DATE_SUB(NOW(), INTERVAL 24 HOUR)
             GROUP BY type, status"
        );

        return new WP_REST_Response( [
            'jobs'        => $jobs,
            'total'       => $total,
            'page'        => $page,
            'total_pages' => (int) ceil( $total / $per_page ),
            'stats_24h'   => $stats,
        ], 200 );
    }

    // ── Helpers ───────────────────────────────────────────────────────────────

    private static function processing_message( string $type ): string {
        return match ( $type ) {
            'ocr'            => '🔍 Reading your handwriting...',
            'evaluate'       => '📝 AI Guru is evaluating your answer...',
            'guru_chat'      => '🧠 AI Guru is thinking...',
            'analytics'      => '📊 Crunching your stats...',
            default          => 'Processing...',
        };
    }

    private static function done_message( string $type ): string {
        return match ( $type ) {
            'ocr'            => '✅ Text extracted! Routing for evaluation.',
            'evaluate'       => '✅ Evaluation complete!',
            'guru_chat'      => '✅ Response ready!',
            'analytics'      => '✅ Analytics updated.',
            default          => '✅ Done.',
        };
    }

    private static function determine_next_action( string $type, array $result, int $user_id ): ?array {
        switch ( $type ) {
            case 'ocr':
                if ( ($result['needs_confirmation'] ?? false) ) {
                    return [
                        'type'    => 'confirm_ocr',
                        'url'     => rest_url("cias/v1/answer/{$result['submission_id']}/confirm"),
                        'message' => 'Please confirm the extracted text.',
                        'text'    => $result['raw_text'] ?? '',
                    ];
                }
                if ( ($result['teacher_review'] ?? false) ) {
                    return [ 'type' => 'teacher_review', 'message' => 'Your answer has been sent for teacher review.' ];
                }
                return null;

            case 'guru_chat':
                // Return the AI response for the chat UI to render
                return [
                    'type'     => 'chat_response',
                    'response' => $result['response'] ?? '',
                    'session_id' => $result['session_id'] ?? '',
                ];

            case 'evaluate':
                return [
                    'type'    => 'evaluation_done',
                    'url'     => rest_url("cias/v1/answer/{$result['submission_id']}"),
                    'score'   => $result['score'] ?? null,
                ];

            default:
                return null;
        }
    }

    public static function is_logged_in(): bool { return is_user_logged_in(); }
    public static function is_admin(): bool     { return current_user_can('manage_options') || current_user_can('cias_view_reports'); }
}
