<?php
/**
 * CIAS Phase B – OCR Pipeline
 *
 * Called ONLY by the OCR worker (not from web requests).
 * Downloads image from R2 → calls Claude Vision → applies confidence gate.
 *
 * Confidence gate:
 *   ≥ HIGH_THRESHOLD (0.85)   → auto-queue evaluation
 *   MEDIUM–HIGH (0.60–0.84)  → save text, set status='needs_confirmation', notify student
 *   < MEDIUM (0.60)           → route to teacher review queue
 *
 * @package CIAS\PhaseB
 * @since   3.19.0
 */
defined( 'ABSPATH' ) || exit;

class CIAS_OCR {

    // Tunable via WP options
    const DEFAULT_HIGH   = 0.85;
    const DEFAULT_MEDIUM = 0.60;
    const MODEL          = 'claude-opus-4-6'; // Vision-capable model

    // ── Main entry point (called by OCR worker) ────────────────────────────────

    /**
     * Run OCR on a submission. Updates DB + pushes next job.
     *
     * @param array $payload  From job queue: submission_id, r2_key, mime_type, ...
     * @return array          Result summary (stored in job result_json)
     */
    public static function process( array $payload ): array {
        $submission_id = (int) $payload['submission_id'];
        $user_id       = (int) $payload['user_id'];
        $r2_key        = $payload['r2_key'];
        $mime_type     = $payload['mime_type'] ?? 'image/jpeg';
        $question_id   = $payload['question_id'] ?? null;

        // ── Update status: processing ──────────────────────────────────────
        self::update_submission_status( $submission_id, 'ocr_processing' );

        // ── Download image from R2 ─────────────────────────────────────────
        $image_bytes = CIAS_R2::get_object( $r2_key );
        if ( ! $image_bytes ) {
            self::update_submission_status( $submission_id, 'ocr_failed' );
            return [ 'error' => 'Could not download image from storage.' ];
        }

        // ── Call Claude Vision ─────────────────────────────────────────────
        $ocr_result = self::call_claude_vision( $image_bytes, $mime_type, $user_id );

        if ( isset( $ocr_result['error'] ) ) {
            self::update_submission_status( $submission_id, 'ocr_failed' );
            return $ocr_result;
        }

        // ── Store OCR result ───────────────────────────────────────────────
        global $wpdb;
        $wpdb->insert( CIAS_OCR_RESULTS, [
            'submission_id' => $submission_id,
            'user_id'       => $user_id,
            'raw_text'      => $ocr_result['text'],
            'confidence'    => $ocr_result['confidence'],
            'legibility'    => $ocr_result['legibility'],
            'word_count'    => str_word_count( $ocr_result['text'] ),
            'model_used'    => self::MODEL,
            'input_tokens'  => $ocr_result['input_tokens'],
            'output_tokens' => $ocr_result['output_tokens'],
            'created_at'    => current_time('mysql'),
        ] );
        $ocr_id = (int) $wpdb->insert_id;

        // Link OCR result to submission
        $wpdb->update( CIAS_SUBMISSIONS, [ 'ocr_result_id' => $ocr_id ], [ 'id' => $submission_id ] );

        // Log AI usage
        CIAS_AI_Utils::log_usage( self::MODEL, $ocr_result['input_tokens'], $ocr_result['output_tokens'], 'ocr', $user_id );

        // ── Apply confidence gate ──────────────────────────────────────────
        $high_threshold   = (float) get_option( 'cias_ocr_high_threshold',   self::DEFAULT_HIGH );
        $medium_threshold = (float) get_option( 'cias_ocr_medium_threshold', self::DEFAULT_MEDIUM );
        $confidence       = $ocr_result['confidence'];

        if ( $confidence >= $high_threshold ) {
            // HIGH confidence → auto-evaluate
            self::update_submission_status( $submission_id, 'ocr_done' );
            self::push_evaluation( $submission_id, $user_id, $ocr_result['text'], $ocr_id, $payload );

            return [
                'submission_id'    => $submission_id,
                'ocr_id'           => $ocr_id,
                'confidence'       => $confidence,
                'gate'             => 'high',
                'raw_text'         => $ocr_result['text'],
                'needs_confirmation'=> false,
                'teacher_review'   => false,
                'auto_evaluating'  => true,
            ];

        } elseif ( $confidence >= $medium_threshold ) {
            // MEDIUM confidence → ask student to confirm
            self::update_submission_status( $submission_id, 'needs_confirmation' );

            // Notify student via chat session if available
            if ( ! empty( $payload['session_id'] ) ) {
                self::notify_student_confirm( $user_id, $payload['session_id'], $ocr_result['text'], $submission_id, $confidence );
            }

            return [
                'submission_id'    => $submission_id,
                'ocr_id'           => $ocr_id,
                'confidence'       => $confidence,
                'gate'             => 'medium',
                'raw_text'         => $ocr_result['text'],
                'needs_confirmation'=> true,
                'teacher_review'   => false,
            ];

        } else {
            // LOW confidence → teacher review
            self::update_submission_status( $submission_id, 'teacher_review' );
            self::push_teacher_review( $submission_id, $user_id, 'low_ocr_confidence', $ocr_id );

            if ( ! empty( $payload['session_id'] ) ) {
                self::notify_student_teacher( $user_id, $payload['session_id'], $submission_id );
            }

            return [
                'submission_id'    => $submission_id,
                'ocr_id'           => $ocr_id,
                'confidence'       => $confidence,
                'gate'             => 'low',
                'raw_text'         => $ocr_result['text'],
                'needs_confirmation'=> false,
                'teacher_review'   => true,
            ];
        }
    }

    // ── Claude Vision call ─────────────────────────────────────────────────────

    /**
     * Send image to Claude Vision for OCR + confidence scoring.
     *
     * Prompt engineering choices:
     * - Asks for JSON → no markdown fences
     * - Asks for confidence 0-1 and legibility bucket
     * - Asks Claude to preserve line breaks and paragraph structure
     */
    private static function call_claude_vision( string $image_bytes, string $mime_type, int $user_id ): array {
        $key = CIAS_AI_Utils::get_api_key();
        if ( ! $key ) return [ 'error' => 'API key not configured.' ];

        $base64 = base64_encode( $image_bytes );
        $prompt = <<<PROMPT
Extract all handwritten text from this answer sheet image.

Return a JSON object with EXACTLY these fields:
{
  "text": "full extracted text preserving paragraph breaks",
  "confidence": 0.92,
  "legibility": "clear",
  "notes": "any extraction caveats"
}

Rules:
- confidence: float 0.0-1.0 (how sure you are the text is correctly extracted)
- legibility: "clear" (>85% readable) | "partial" (50-85%) | "unclear" (<50%)
- Preserve paragraph structure with double line breaks between paragraphs
- If a word is illegible, write [illegible] in brackets
- Return ONLY valid JSON, no prose, no markdown fences
PROMPT;

        $response = wp_remote_post( 'https://api.anthropic.com/v1/messages', [
            'timeout' => 60,
            'headers' => [
                'Content-Type'      => 'application/json',
                'x-api-key'         => $key,
                'anthropic-version' => '2023-06-01',
            ],
            'body' => wp_json_encode( [
                'model'      => self::MODEL,
                'max_tokens' => 2000,
                'messages'   => [ [
                    'role'    => 'user',
                    'content' => [
                        [ 'type' => 'image', 'source' => [ 'type' => 'base64', 'media_type' => $mime_type, 'data' => $base64 ] ],
                        [ 'type' => 'text',  'text'   => $prompt ],
                    ],
                ] ],
            ] ),
        ] );

        if ( is_wp_error( $response ) ) {
            return [ 'error' => 'Claude Vision API error: ' . $response->get_error_message() ];
        }

        $body   = json_decode( wp_remote_retrieve_body( $response ), true );
        $raw    = trim( $body['content'][0]['text'] ?? '' );

        // Strip any accidental fences
        $clean  = preg_replace( '/^```json?\s*|\s*```$/m', '', $raw );
        $parsed = json_decode( trim( $clean ), true );

        if ( ! $parsed || ! isset( $parsed['text'] ) ) {
            return [ 'error' => 'Could not parse OCR response.', 'raw' => substr( $raw, 0, 300 ) ];
        }

        return [
            'text'          => sanitize_textarea_field( $parsed['text'] ),
            'confidence'    => (float) max( 0, min( 1, $parsed['confidence'] ?? 0.5 ) ),
            'legibility'    => in_array( $parsed['legibility'] ?? '', ['clear','partial','unclear'], true )
                               ? $parsed['legibility'] : 'partial',
            'input_tokens'  => (int) ( $body['usage']['input_tokens']  ?? 0 ),
            'output_tokens' => (int) ( $body['usage']['output_tokens'] ?? 0 ),
        ];
    }

    // ── Confidence gate helpers ────────────────────────────────────────────────

    private static function push_evaluation( int $submission_id, int $user_id, string $text, int $ocr_id, array $original_payload ): void {
        CIAS_DB_Phase_B::push_job( 'evaluate', [
            'submission_id'  => $submission_id,
            'user_id'        => $user_id,
            'ocr_result_id'  => $ocr_id,
            'confirmed_text' => $text,
            'question_id'    => $original_payload['question_id'] ?? null,
            'subject_id'     => $original_payload['subject_id'] ?? null,
            'topic_id'       => $original_payload['topic_id'] ?? null,
        ], priority: 4 );
    }

    private static function push_teacher_review( int $submission_id, int $user_id, string $reason, int $ocr_id ): void {
        global $wpdb;
        $wpdb->insert( CIAS_TEACHER_REVIEWS, [
            'submission_id' => $submission_id,
            'user_id'       => $user_id,
            'status'        => 'pending',
            'priority'      => 5,
            'queue_reason'  => $reason,
            'created_at'    => current_time('mysql'),
        ] );
    }

    private static function notify_student_confirm( int $user_id, string $session_id, string $text, int $submission_id, float $confidence ): void {
        $pct = round( $confidence * 100 );
        do_action( 'cias_guru_assistant_message', [
            'session_id' => $session_id,
            'user_id'    => $user_id,
            'body'       => "📋 I extracted your handwritten text ({$pct}% confidence). Please review and confirm it's correct before I evaluate:\n\n---\n{$text}\n---\n\nClick **Confirm** if this is correct, or edit the text before confirming.",
            'tokens'     => null,
        ] );
    }

    private static function notify_student_teacher( int $user_id, string $session_id, int $submission_id ): void {
        do_action( 'cias_guru_assistant_message', [
            'session_id' => $session_id,
            'user_id'    => $user_id,
            'body'       => "📋 Your handwriting was difficult to read accurately. I've added this to the teacher review queue — your teacher will evaluate this directly. You'll be notified when feedback is ready.",
            'tokens'     => null,
        ] );
    }

    private static function update_submission_status( int $submission_id, string $status ): void {
        global $wpdb;
        $wpdb->update( CIAS_SUBMISSIONS,
            [ 'status' => $status, 'updated_at' => current_time('mysql') ],
            [ 'id' => $submission_id ]
        );
    }
}
