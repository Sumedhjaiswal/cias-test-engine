<?php
/**
 * CIAS Phase B – AI Answer Evaluator
 *
 * Evaluates student answers using Claude with:
 * - Structured rubric-based scoring
 * - Prompt caching (system prompt + rubric cached across evaluations)
 * - Batch evaluation (5-10 answers per API call when queue builds up)
 *
 * Called ONLY by the evaluation worker — never from web requests.
 *
 * UPSC Mains evaluation criteria (7 PM rubric):
 *   Introduction (10%)  · Content/Arguments (50%)  · Structure (20%)
 *   Conclusion (10%)    · Language (10%)
 *
 * @package CIAS\PhaseB
 * @since   3.19.0
 */
defined( 'ABSPATH' ) || exit;

class CIAS_Evaluator {

    const MODEL      = 'claude-sonnet-4-6';   // Sonnet for quality evaluation
    const MAX_TOKENS = 1500;

    // Cache TTL for rubric/system prompt hash (24 hours)
    const PROMPT_CACHE_TTL = 86400;

    // ── Single evaluation ─────────────────────────────────────────────────────

    /**
     * Evaluate one student answer.
     *
     * @param array $payload  From job queue
     * @return array          Result stored in job + DB
     */
    public static function evaluate_single( array $payload ): array {
        $submission_id  = (int) $payload['submission_id'];
        $user_id        = (int) $payload['user_id'];
        $answer_text    = $payload['confirmed_text'] ?? '';
        $question_id    = $payload['question_id'] ?? null;
        $question_text  = $payload['question_text'] ?? null;
        $ocr_result_id  = $payload['ocr_result_id'] ?? null;

        // ── Update status ──────────────────────────────────────────────────
        self::update_status( $submission_id, 'evaluating' );

        // ── Get question text if not in payload ────────────────────────────
        if ( ! $question_text && $question_id ) {
            global $wpdb;
            $question_text = $wpdb->get_var( $wpdb->prepare(
                "SELECT question_text FROM " . CIAS_QUESTIONS . " WHERE id = %d", $question_id
            ) );
        }

        if ( ! $answer_text ) {
            self::update_status( $submission_id, 'eval_failed' );
            return [ 'error' => 'No answer text to evaluate.' ];
        }

        // ── Call Claude ────────────────────────────────────────────────────
        $result = self::call_claude_evaluate( $question_text ?: 'General UPSC Mains answer', $answer_text, $user_id );

        if ( isset( $result['error'] ) ) {
            self::update_status( $submission_id, 'eval_failed' );
            return $result;
        }

        // ── Store evaluation ───────────────────────────────────────────────
        global $wpdb;
        $wpdb->insert( CIAS_AI_EVALUATIONS, [
            'submission_id'    => $submission_id,
            'user_id'          => $user_id,
            'question_id'      => $question_id,
            'ocr_result_id'    => $ocr_result_id,
            'score'            => $result['score'],
            'max_score'        => 100,
            'criterion_scores' => wp_json_encode( $result['criterion_scores'] ),
            'feedback_json'    => wp_json_encode( $result['feedback'] ),
            'improvement_points'=> wp_json_encode( $result['improvement_points'] ),
            'model_used'       => self::MODEL,
            'input_tokens'     => $result['input_tokens'],
            'output_tokens'    => $result['output_tokens'],
            'cost_usd'         => $result['cost_usd'],
            'cache_hit'        => $result['cache_hit'] ? 1 : 0,
            'evaluated_at'     => current_time('mysql'),
        ] );
        $eval_id = (int) $wpdb->insert_id;

        // ── Link to submission ─────────────────────────────────────────────
        $wpdb->update( CIAS_SUBMISSIONS, [
            'eval_id' => $eval_id,
            'status'  => 'evaluated',
            'updated_at' => current_time('mysql'),
        ], [ 'id' => $submission_id ] );

        // ── Log AI usage ───────────────────────────────────────────────────
        CIAS_AI_Utils::log_usage( self::MODEL, $result['input_tokens'], $result['output_tokens'], 'evaluation', $user_id );

        // ── Update topic performance (pre-aggregated table) ────────────────
        if ( ! empty( $payload['subject_id'] ) ) {
            self::update_topic_performance( $user_id, (int)$payload['subject_id'], (int)($payload['topic_id'] ?? 0), $result['score'] );
        }

        // ── Notify via chat session ────────────────────────────────────────
        if ( ! empty( $payload['session_id'] ) ) {
            self::notify_student_result( $user_id, $payload['session_id'], $result, $submission_id );
        }

        return [
            'submission_id' => $submission_id,
            'eval_id'       => $eval_id,
            'score'         => $result['score'],
            'max_score'     => 100,
        ];
    }

    // ── Batch evaluation (up to 8 answers in one API call) ────────────────────

    /**
     * Evaluate multiple answers for the same question in one Claude call.
     * Called by the evaluate_batch job type.
     */
    public static function evaluate_batch( array $submissions ): array {
        if ( empty( $submissions ) ) return [];

        // All submissions must share the same question
        $question_text = $submissions[0]['question_text'] ?? 'General UPSC Mains answer';
        $key           = CIAS_AI_Utils::get_api_key();
        if ( ! $key ) return [];

        $system = self::build_system_prompt( $question_text );

        // Build batch prompt
        $batch_entries = [];
        foreach ( $submissions as $i => $sub ) {
            $batch_entries[] = "ANSWER " . ($i+1) . " [submission_id={$sub['submission_id']}]:\n" . mb_substr( $sub['confirmed_text'], 0, 2000 );
        }
        $user_prompt = "Evaluate each of the following " . count($submissions) . " student answers.\n\n"
                     . implode( "\n\n---\n\n", $batch_entries )
                     . "\n\nReturn a JSON array with one evaluation object per answer, in the same order.";

        $response = wp_remote_post( 'https://api.anthropic.com/v1/messages', [
            'timeout' => 120,
            'headers' => [
                'Content-Type'      => 'application/json',
                'x-api-key'         => $key,
                'anthropic-version' => '2023-06-01',
            ],
            'body' => wp_json_encode( [
                'model'      => self::MODEL,
                'max_tokens' => 4000,
                'system'     => [
                    [
                        'type' => 'text',
                        'text' => $system,
                        // Prompt caching: the system prompt + rubric stays cached between calls
                        'cache_control' => [ 'type' => 'ephemeral' ],
                    ]
                ],
                'messages'   => [ [ 'role' => 'user', 'content' => $user_prompt ] ],
            ] ),
        ] );

        if ( is_wp_error( $response ) ) return [];

        $body      = json_decode( wp_remote_retrieve_body( $response ), true );
        $raw       = preg_replace( '/^```json?\s*|\s*```$/m', '', trim( $body['content'][0]['text'] ?? '' ) );
        $results   = json_decode( trim( $raw ), true );

        if ( ! is_array( $results ) ) return [];

        $in_tokens  = (int)($body['usage']['input_tokens']  ?? 0);
        $out_tokens = (int)($body['usage']['output_tokens'] ?? 0);

        // Store each result
        $output = [];
        foreach ( $submissions as $i => $sub ) {
            $eval = $results[$i] ?? null;
            if ( ! $eval ) continue;

            $score = self::normalize_score( $eval );
            global $wpdb;

            $wpdb->insert( CIAS_AI_EVALUATIONS, [
                'submission_id'     => $sub['submission_id'],
                'user_id'           => $sub['user_id'],
                'question_id'       => $sub['question_id'] ?? null,
                'score'             => $score,
                'max_score'         => 100,
                'criterion_scores'  => wp_json_encode( $eval['criterion_scores'] ?? [] ),
                'feedback_json'     => wp_json_encode( $eval['feedback'] ?? [] ),
                'improvement_points'=> wp_json_encode( $eval['improvement_points'] ?? [] ),
                'model_used'        => self::MODEL,
                'input_tokens'      => (int)( $in_tokens / count($submissions) ), // pro-rate
                'output_tokens'     => (int)( $out_tokens / count($submissions) ),
                'is_batch'          => 1,
                'evaluated_at'      => current_time('mysql'),
            ] );
            $eval_id = (int) $wpdb->insert_id;

            $wpdb->update( CIAS_SUBMISSIONS,
                [ 'eval_id' => $eval_id, 'status' => 'evaluated', 'updated_at' => current_time('mysql') ],
                [ 'id' => $sub['submission_id'] ]
            );

            $output[] = [ 'submission_id' => $sub['submission_id'], 'eval_id' => $eval_id, 'score' => $score ];
        }

        CIAS_AI_Utils::log_usage( self::MODEL, $in_tokens, $out_tokens, 'evaluation_batch', 0 );
        return $output;
    }

    // ── Claude API call (single) ───────────────────────────────────────────────

    private static function call_claude_evaluate( string $question, string $answer, int $user_id ): array {
        $key = CIAS_AI_Utils::get_api_key();
        if ( ! $key ) return [ 'error' => 'API key not configured.' ];

        $system      = self::build_system_prompt( $question );
        $cache_key   = hash( 'sha256', $system );
        $cache_hit   = false;

        // Check if we have a cached prompt result (Claude prompt caching)
        // This is tracked in our DB but actual caching is handled by Claude API automatically
        // when we send cache_control: ephemeral on the system prompt

        $user_prompt = "Evaluate this student answer:\n\n" . mb_substr( $answer, 0, 3000 );

        $response = wp_remote_post( 'https://api.anthropic.com/v1/messages', [
            'timeout' => 60,
            'headers' => [
                'Content-Type'         => 'application/json',
                'x-api-key'            => $key,
                'anthropic-version'    => '2023-06-01',
                'anthropic-beta'       => 'prompt-caching-2024-07-31',
            ],
            'body' => wp_json_encode( [
                'model'      => self::MODEL,
                'max_tokens' => self::MAX_TOKENS,
                'system'     => [
                    [
                        'type' => 'text',
                        'text' => $system,
                        'cache_control' => [ 'type' => 'ephemeral' ], // Prompt caching
                    ]
                ],
                'messages'   => [ [ 'role' => 'user', 'content' => $user_prompt ] ],
            ] ),
        ] );

        if ( is_wp_error( $response ) ) {
            return [ 'error' => $response->get_error_message() ];
        }

        $body = json_decode( wp_remote_retrieve_body( $response ), true );
        if ( empty( $body['content'][0]['text'] ) ) {
            return [ 'error' => 'Empty Claude response.', 'raw' => substr( wp_remote_retrieve_body($response), 0, 200 ) ];
        }

        // Detect cache hit via usage.cache_read_input_tokens
        $cache_hit   = (int)( $body['usage']['cache_read_input_tokens'] ?? 0 ) > 0;
        $in_tokens   = (int)( $body['usage']['input_tokens']  ?? 0 );
        $out_tokens  = (int)( $body['usage']['output_tokens'] ?? 0 );

        // Cost calculation (Sonnet pricing, with cache discount)
        $in_cost    = ( $cache_hit ? $in_tokens * 0.30 : $in_tokens * 3.00 ) / 1_000_000;
        $out_cost   = $out_tokens * 15.00 / 1_000_000;
        $cost_usd   = round( $in_cost + $out_cost, 6 );

        $raw    = preg_replace( '/^```json?\s*|\s*```$/m', '', trim( $body['content'][0]['text'] ) );
        $parsed = json_decode( trim( $raw ), true );

        if ( ! is_array( $parsed ) ) {
            return [ 'error' => 'Could not parse evaluation JSON.', 'raw' => substr($raw, 0, 300) ];
        }

        return [
            'score'             => self::normalize_score( $parsed ),
            'criterion_scores'  => $parsed['criterion_scores'] ?? [],
            'feedback'          => $parsed['feedback'] ?? [],
            'improvement_points'=> $parsed['improvement_points'] ?? [],
            'input_tokens'      => $in_tokens,
            'output_tokens'     => $out_tokens,
            'cost_usd'          => $cost_usd,
            'cache_hit'         => $cache_hit,
            'cache_key'         => $cache_key,
        ];
    }

    // ── System prompt with rubric (cached) ────────────────────────────────────

    private static function build_system_prompt( string $question ): string {
        return <<<SYSTEM
You are an expert UPSC Mains examiner evaluating a student's handwritten answer.

Question: {$question}

Evaluation Rubric (UPSC 7 PM format, 100 points total):
- Introduction (10 pts): Hook, context, thesis clarity
- Content & Arguments (50 pts): Accuracy, depth, multiple dimensions, examples, current affairs
- Structure & Flow (20 pts): Logical organization, paragraph transitions, headings if appropriate
- Conclusion (10 pts): Summary, way forward, balanced view
- Language & Presentation (10 pts): Grammar, vocabulary, conciseness, clarity

Return ONLY valid JSON (no prose, no markdown):
{
  "score": 72,
  "criterion_scores": {
    "introduction": 7,
    "content": 38,
    "structure": 15,
    "conclusion": 7,
    "language": 5
  },
  "feedback": {
    "introduction": "Good hook but thesis could be sharper.",
    "content": "Strong factual grounding. Missing constitutional angle.",
    "structure": "Clear paragraphs but conclusion is abrupt.",
    "conclusion": "Way forward is generic. Add specific policy recommendations.",
    "language": "Minor grammar issues. Overall readable."
  },
  "improvement_points": [
    "Add constitutional/legal dimension to content",
    "Use the 'Way Forward' structure for conclusion",
    "Include at least one relevant government scheme or committee"
  ],
  "word_count_assessment": "Within limit",
  "overall_comment": "A competent answer that shows understanding but lacks depth on constitutional aspects."
}
SYSTEM;
    }

    // ── Helpers ───────────────────────────────────────────────────────────────

    private static function normalize_score( array $parsed ): int {
        $score = (int)( $parsed['score'] ?? 0 );
        // Fallback: sum criterion scores if total score missing
        if ( ! $score && isset( $parsed['criterion_scores'] ) ) {
            $score = (int) array_sum( array_values( $parsed['criterion_scores'] ) );
        }
        return min( 100, max( 0, $score ) );
    }

    private static function update_status( int $submission_id, string $status ): void {
        global $wpdb;
        $wpdb->update( CIAS_SUBMISSIONS,
            [ 'status' => $status, 'updated_at' => current_time('mysql') ],
            [ 'id' => $submission_id ]
        );
    }

    private static function update_topic_performance( int $user_id, int $subject_id, int $topic_id, int $score ): void {
        global $wpdb;
        $wpdb->query( $wpdb->prepare(
            "INSERT INTO " . CIAS_TOPIC_PERF . "
             (user_id, subject_id, topic_id, submissions, evaluations, avg_score, best_score, last_submission)
             VALUES (%d, %d, %d, 1, 1, %d, %d, CURDATE())
             ON DUPLICATE KEY UPDATE
               submissions   = submissions + 1,
               evaluations   = evaluations + 1,
               avg_score     = ROUND((avg_score * (evaluations - 1) + VALUES(avg_score)) / evaluations, 2),
               best_score    = GREATEST(best_score, VALUES(best_score)),
               last_submission = CURDATE()",
            $user_id, $subject_id, $topic_id, $score, $score
        ) );
    }

    private static function notify_student_result( int $user_id, string $session_id, array $result, int $submission_id ): void {
        $score    = $result['score'];
        $overall  = $result['feedback']['content'] ?? '';
        $bullets  = array_map( fn($p) => "• {$p}", array_slice($result['improvement_points'], 0, 3) );

        $message = "📝 **Evaluation Complete — Score: {$score}/100**\n\n"
                 . ( $overall ? "{$overall}\n\n" : '' )
                 . "**Key improvements:**\n" . implode("\n", $bullets) . "\n\n"
                 . "Full feedback → [View Submission](##submission:{$submission_id})";

        do_action( 'cias_guru_assistant_message', [
            'session_id' => $session_id,
            'user_id'    => $user_id,
            'body'       => $message,
            'tokens'     => null,
        ] );
    }
}
