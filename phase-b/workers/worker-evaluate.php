#!/usr/bin/env php
<?php
/**
 * CIAS Phase B – Evaluation + Guru Chat Worker
 *
 * Processes:
 *   'evaluate'       → single answer evaluation via Claude
 *   'evaluate_batch' → batch evaluation (multiple answers, one API call)
 *   'guru_chat'      → AI Guru chat response
 *
 * Cron entry (every minute):
 *   * * * * * /usr/bin/php /var/.../worker-evaluate.php >> /var/log/cias-eval.log 2>&1
 *
 * @package CIAS\PhaseB
 */

require_once __DIR__ . '/bootstrap.php';

// ── Evaluation worker ─────────────────────────────────────────────────────────

class CIAS_Worker_Evaluate extends CIAS_Worker_Base {

    public function __construct() {
        parent::__construct( 'evaluate' );
        $this->max_runtime = 50;
        $this->max_jobs    = 8;
    }

    protected function process_job( array $payload ): array {
        if ( empty( $payload['submission_id'] ) ) {
            throw new \InvalidArgumentException( 'Missing submission_id in evaluate job payload.' );
        }
        $this->log( "Evaluating submission #{$payload['submission_id']}" );
        return CIAS_Evaluator::evaluate_single( $payload );
    }
}

// ── Batch evaluation worker ───────────────────────────────────────────────────

class CIAS_Worker_EvaluateBatch extends CIAS_Worker_Base {

    public function __construct() {
        parent::__construct( 'evaluate_batch' );
        $this->max_runtime = 50;
        $this->max_jobs    = 2; // Each batch job processes 5-8 submissions
    }

    protected function process_job( array $payload ): array {
        $submissions = $payload['submissions'] ?? [];
        if ( empty( $submissions ) ) {
            throw new \InvalidArgumentException( 'Empty submissions array in evaluate_batch payload.' );
        }
        $this->log( "Batch evaluating " . count($submissions) . " submissions." );
        $results = CIAS_Evaluator::evaluate_batch( $submissions );
        return [ 'evaluated' => count($results), 'results' => $results ];
    }
}

// ── AI Guru Chat worker ───────────────────────────────────────────────────────

class CIAS_Worker_GuruChat extends CIAS_Worker_Base {

    public function __construct() {
        parent::__construct( 'guru_chat' );
        $this->max_runtime = 50;
        $this->max_jobs    = 15; // Chat responses are fast
    }

    protected function process_job( array $payload ): array {
        $user_id    = (int) ( $payload['user_id']    ?? 0 );
        $session_id = $payload['session_id'] ?? '';
        $message    = $payload['message']    ?? '';
        $img_key    = $payload['image_r2_key'] ?? null;
        $img_mime   = $payload['image_mime']   ?? 'image/jpeg';

        if ( ! $user_id || ! $message ) {
            throw new \InvalidArgumentException( 'Missing user_id or message in guru_chat payload.' );
        }

        $this->log( "Guru chat for user #{$user_id} session={$session_id}" );

        // Build profile and get AI response
        $profile  = CAIG_Data::get_profile( $user_id );

        // If image present, include it in the chat call
        if ( $img_key ) {
            $image_bytes = CIAS_R2::get_object( $img_key );
            $response    = $this->guru_chat_with_image( $profile, $message, $image_bytes, $img_mime, $user_id );
        } else {
            // Load last 6 messages from DB for context
            $history  = $this->load_chat_history( $user_id, $session_id );
            $response = CAIG_AI::guru_chat( $profile, $message, $history );
        }

        // Persist assistant message to chat history
        do_action( 'cias_guru_assistant_message', [
            'session_id' => $session_id,
            'user_id'    => $user_id,
            'body'       => $response,
            'tokens'     => null,
        ] );

        return [
            'response'   => $response,
            'session_id' => $session_id,
            'user_id'    => $user_id,
        ];
    }

    /**
     * Load last N messages as history array for multi-turn context.
     */
    private function load_chat_history( int $user_id, string $session_id, int $limit = 6 ): array {
        global $wpdb;
        $rows = $wpdb->get_results( $wpdb->prepare(
            "SELECT role, body AS content FROM {$wpdb->prefix}cias_chat_messages
             WHERE user_id = %d AND session_id = %s
             ORDER BY created_at DESC LIMIT %d",
            $user_id, $session_id, $limit
        ) );
        // Reverse so oldest is first
        return array_reverse( array_map( fn($r) => [ 'role' => $r->role, 'content' => $r->content ], $rows ) );
    }

    /**
     * Chat with image context (multimodal).
     */
    private function guru_chat_with_image( array $profile, string $message, ?string $image_bytes, string $mime, int $user_id ): string {
        $key = CIAS_AI_Utils::get_api_key();
        if ( ! $key || ! $image_bytes ) return CAIG_AI::guru_chat( $profile, $message, [] );

        $ctx    = CAIG_Data::profile_to_context( $profile );
        $system = "You are CIAS AI Guru — a UPSC mentor. Student context: {$ctx} Answer questions about the uploaded image/document. Max 300 words.";

        $response = wp_remote_post( 'https://api.anthropic.com/v1/messages', [
            'timeout' => 45,
            'headers' => [ 'Content-Type'=>'application/json', 'x-api-key'=>$key, 'anthropic-version'=>'2023-06-01' ],
            'body'    => wp_json_encode( [
                'model'      => CAIG_AI::MODEL,
                'max_tokens' => 600,
                'system'     => $system,
                'messages'   => [ [
                    'role'    => 'user',
                    'content' => [
                        [ 'type'=>'image', 'source'=>[ 'type'=>'base64', 'media_type'=>$mime, 'data'=>base64_encode($image_bytes) ] ],
                        [ 'type'=>'text', 'text'=>$message ],
                    ],
                ] ],
            ] ),
        ] );

        if ( is_wp_error($response) ) return 'Could not process image. Please try again.';
        $body = json_decode( wp_remote_retrieve_body($response), true );
        $text = trim( $body['content'][0]['text'] ?? '' );
        CIAS_AI_Utils::log_usage( CAIG_AI::MODEL, (int)($body['usage']['input_tokens']??0), (int)($body['usage']['output_tokens']??0), 'guru_vision', $user_id );
        return $text ?: 'Could not generate a response.';
    }
}

// ── Run all three ─────────────────────────────────────────────────────────────
// Each checks its own queue. First two will be fast since most runs have no batch jobs.

( new CIAS_Worker_GuruChat() )->run();

// Only proceed to evaluate if we have time left (started within last 45s)
$started_at = time();
if ( time() - $started_at < 45 ) {
    ( new CIAS_Worker_Evaluate() )->run();
}
if ( time() - $started_at < 50 ) {
    ( new CIAS_Worker_EvaluateBatch() )->run();
}
