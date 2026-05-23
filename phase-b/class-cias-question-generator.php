<?php
/**
 * CIAS Phase B – AI Question Generator
 *
 * Generates UPSC-style MCQ practice questions via Claude when the question
 * bank runs short for a given subject/topic. Questions are saved with
 * source='ai' and status='ai_pending_review' so they stay HIDDEN from
 * students until a teacher approves them in the admin Questions list.
 *
 * Architecture compliance:
 *   - Runs ONLY inside the async worker (never in a web request).
 *   - WordPress/MySQL used for durable storage of the generated questions.
 *   - No live aggregation; pure insert of reviewed-pending rows.
 *
 * @package CIAS\PhaseB
 */

if ( ! defined( 'ABSPATH' ) ) exit;

class CIAS_Question_Generator {

    /** Model used for generation — Sonnet for question quality. */
    const MODEL = 'claude-sonnet-4-5-20250929';

    /** Hard ceiling on how many questions one job may generate. */
    const MAX_PER_JOB = 15;

    /**
     * Generate questions and persist them as pending-review.
     *
     * @param array $payload {
     *   @type int    $subject_id   Required.
     *   @type int    $topic_id     Optional (0 = whole subject).
     *   @type int    $subtopic_id  Optional.
     *   @type int    $count        How many to generate (clamped 1..MAX_PER_JOB).
     *   @type int    $difficulty_easy   Optional split hint.
     *   @type int    $difficulty_medium Optional split hint.
     *   @type int    $difficulty_hard   Optional split hint.
     * }
     * @return array Result summary for the job record.
     */
    public static function generate( array $payload ): array {
        global $wpdb;

        $subject_id  = (int) ( $payload['subject_id']  ?? 0 );
        $topic_id    = (int) ( $payload['topic_id']     ?? 0 );
        $subtopic_id = (int) ( $payload['subtopic_id']  ?? 0 );
        $count       = (int) ( $payload['count']        ?? 0 );

        if ( $subject_id <= 0 || $count <= 0 ) {
            return [ 'generated' => 0, 'reason' => 'Missing subject_id or count.' ];
        }
        $count = max( 1, min( self::MAX_PER_JOB, $count ) );

        // Resolve names for prompt context (read-only).
        $db          = new CIAS_DB();
        $subject     = $db->get_by_id( 'subjects',  $subject_id );
        $topic       = $topic_id    ? $db->get_by_id( 'topics',    $topic_id )    : null;
        $subtopic    = $subtopic_id ? $db->get_by_id( 'subtopics', $subtopic_id ) : null;

        if ( ! $subject ) {
            return [ 'generated' => 0, 'reason' => 'Subject not found.' ];
        }

        $subject_name  = $subject->name;
        $topic_name    = $topic    ? $topic->name    : '';
        $subtopic_name = $subtopic ? $subtopic->name : '';

        // De-dup guard: collect existing question texts for this scope so the
        // model is told what already exists (keeps the bank from filling with
        // near-duplicates).
        $existing = self::existing_question_texts( $subject_id, $topic_id, $subtopic_id );

        $key = CIAS_AI_Utils::get_api_key();
        if ( empty( $key ) ) {
            return [ 'generated' => 0, 'reason' => 'No API key configured.' ];
        }

        $system = self::build_system_prompt();
        $user   = self::build_user_prompt( $subject_name, $topic_name, $subtopic_name, $count, $existing );

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
                        'type'          => 'text',
                        'text'          => $system,
                        'cache_control' => [ 'type' => 'ephemeral' ],
                    ],
                ],
                'messages'   => [ [ 'role' => 'user', 'content' => $user ] ],
            ] ),
        ] );

        if ( is_wp_error( $response ) ) {
            return [ 'generated' => 0, 'reason' => 'API error: ' . $response->get_error_message() ];
        }

        $body = json_decode( wp_remote_retrieve_body( $response ), true );
        $raw  = trim( $body['content'][0]['text'] ?? '' );
        $raw  = preg_replace( '/^```json?\s*|\s*```$/m', '', $raw );
        $items = json_decode( trim( $raw ), true );

        if ( ! is_array( $items ) ) {
            return [ 'generated' => 0, 'reason' => 'Model returned unparseable JSON.' ];
        }

        // Token usage logging (best-effort).
        $in_tokens  = (int) ( $body['usage']['input_tokens']  ?? 0 );
        $out_tokens = (int) ( $body['usage']['output_tokens'] ?? 0 );
        if ( class_exists( 'CIAS_AI_Utils' ) ) {
            CIAS_AI_Utils::log_usage( self::MODEL, $in_tokens, $out_tokens, 'question_generation', 0 );
        }

        $inserted = 0;
        $skipped  = 0;
        foreach ( $items as $item ) {
            $clean = self::sanitize_question( $item );
            if ( ! $clean ) { $skipped++; continue; }

            $row = [
                'subject_id'     => $subject_id,
                'topic_id'       => $topic_id,
                'subtopic_id'    => $subtopic_id,
                'question_type'  => $clean['question_type'],
                'question_text'  => $clean['question_text'],
                'statements'     => $clean['statements'],   // JSON string or null
                'question_tags'  => $clean['question_tags'],
                'year_asked'     => null,
                'option_a'       => $clean['option_a'],
                'option_b'       => $clean['option_b'],
                'option_c'       => $clean['option_c'],
                'option_d'       => $clean['option_d'],
                'correct_option' => $clean['correct_option'],
                'explanation'    => $clean['explanation'],
                'difficulty'     => $clean['difficulty'],
                'source'         => 'ai',
                'created_by'     => 0,
                'status'         => 'ai_pending_review',
                'created_at'     => current_time( 'mysql' ),
            ];

            $ok = $wpdb->insert( CIAS_QUESTIONS, $row );
            if ( $ok ) { $inserted++; } else { $skipped++; }
        }

        if ( $inserted > 0 ) {
            wp_cache_flush();
        }

        return [
            'generated'    => $inserted,
            'skipped'      => $skipped,
            'requested'    => $count,
            'subject_id'   => $subject_id,
            'topic_id'     => $topic_id,
            'subtopic_id'  => $subtopic_id,
            'input_tokens' => $in_tokens,
            'output_tokens'=> $out_tokens,
        ];
    }

    /** Existing question texts for the scope (caps at 40 for prompt size). */
    private static function existing_question_texts( int $subject_id, int $topic_id, int $subtopic_id ): array {
        global $wpdb;
        $where  = $wpdb->prepare( 'subject_id=%d', $subject_id );
        if ( $topic_id )    $where .= $wpdb->prepare( ' AND topic_id=%d', $topic_id );
        if ( $subtopic_id ) $where .= $wpdb->prepare( ' AND subtopic_id=%d', $subtopic_id );
        $rows = $wpdb->get_col(
            "SELECT question_text FROM " . CIAS_QUESTIONS . " WHERE {$where} ORDER BY id DESC LIMIT 40"
        );
        return is_array( $rows ) ? $rows : [];
    }

    /** System prompt — defines the role, format, and quality bar. */
    private static function build_system_prompt(): string {
        return
"You are a senior UPSC (Indian Civil Services) exam question setter. You write "
. "original, factually accurate, exam-grade multiple-choice questions in the style "
. "of the UPSC Prelims General Studies paper.\n\n"
. "STRICT OUTPUT RULES:\n"
. "1. Return ONLY a JSON array. No prose, no markdown, no code fences.\n"
. "2. Each element is an object with EXACTLY these keys:\n"
. "   - \"question_type\": either \"standard\" or \"statement\"\n"
. "   - \"question_text\": the question stem (string)\n"
. "   - \"statements\": for \"statement\" type, an array of statement strings; for \"standard\", null\n"
. "   - \"option_a\", \"option_b\", \"option_c\", \"option_d\": the four options (strings)\n"
. "   - \"correct_option\": one of \"a\", \"b\", \"c\", \"d\" (lowercase)\n"
. "   - \"explanation\": 2-4 sentence explanation of why the answer is correct\n"
. "   - \"difficulty\": one of \"easy\", \"medium\", \"hard\"\n"
. "   - \"tags\": array of 1-3 short topic tags\n"
. "3. For \"statement\" type questions, the options must reference the statements "
. "(e.g. \"1 and 2 only\", \"2 and 3 only\", \"1, 2 and 3\", \"None\").\n"
. "4. Exactly one option must be correct. Make distractors plausible.\n"
. "5. Be factually rigorous. Do NOT invent fake facts, dates, or articles.\n"
. "6. Do NOT duplicate any question the user lists as already existing.";
    }

    /** User prompt — the concrete generation request. */
    private static function build_user_prompt( string $subject, string $topic, string $subtopic, int $count, array $existing ): string {
        $scope = "Subject: {$subject}";
        if ( $topic )    $scope .= "\nTopic: {$topic}";
        if ( $subtopic ) $scope .= "\nSubtopic: {$subtopic}";

        $avoid = '';
        if ( ! empty( $existing ) ) {
            $list = array_map(
                function ( $t ) { return '- ' . mb_substr( (string) $t, 0, 140 ); },
                array_slice( $existing, 0, 40 )
            );
            $avoid = "\n\nDo NOT duplicate these existing questions:\n" . implode( "\n", $list );
        }

        // Difficulty spread roughly mirrors the adaptive 70-20-10 spirit:
        // a healthy bank skews easier with fewer hard items.
        return
"Generate {$count} original UPSC Prelims-style MCQs for:\n{$scope}\n\n"
. "Aim for a spread of difficulty (more easy/medium than hard). Mix \"standard\" "
. "and \"statement\" types where natural for the topic.{$avoid}\n\n"
. "Return ONLY the JSON array.";
    }

    /**
     * Validate + sanitize one model-produced question object.
     * Returns a clean row-ready array, or null if the item is invalid.
     */
    private static function sanitize_question( $item ): ?array {
        if ( ! is_array( $item ) ) return null;

        $qtext = trim( (string) ( $item['question_text'] ?? '' ) );
        $oa    = trim( (string) ( $item['option_a'] ?? '' ) );
        $ob    = trim( (string) ( $item['option_b'] ?? '' ) );
        $oc    = trim( (string) ( $item['option_c'] ?? '' ) );
        $od    = trim( (string) ( $item['option_d'] ?? '' ) );
        $corr  = strtolower( trim( (string) ( $item['correct_option'] ?? '' ) ) );

        // Required fields present?
        if ( $qtext === '' || $oa === '' || $ob === '' || $oc === '' || $od === '' ) return null;
        if ( ! in_array( $corr, [ 'a', 'b', 'c', 'd' ], true ) ) return null;

        $type = ( ( $item['question_type'] ?? 'standard' ) === 'statement' ) ? 'statement' : 'standard';

        // Statements → JSON or null
        $statements_json = null;
        if ( $type === 'statement' && ! empty( $item['statements'] ) && is_array( $item['statements'] ) ) {
            $stmts = array_values( array_filter( array_map(
                function ( $s ) { return sanitize_text_field( (string) $s ); },
                $item['statements']
            ) ) );
            if ( ! empty( $stmts ) ) {
                $statements_json = wp_json_encode( $stmts );
            } else {
                $type = 'standard';
            }
        }

        $diff = strtolower( (string) ( $item['difficulty'] ?? 'medium' ) );
        if ( ! in_array( $diff, [ 'easy', 'medium', 'hard' ], true ) ) $diff = 'medium';

        $tags = '';
        if ( ! empty( $item['tags'] ) && is_array( $item['tags'] ) ) {
            $tags = implode( ',', array_slice( array_map(
                function ( $t ) { return sanitize_text_field( (string) $t ); },
                $item['tags']
            ), 0, 3 ) );
        }

        return [
            'question_type'  => $type,
            'question_text'  => wp_kses_post( $qtext ),
            'statements'     => $statements_json,
            'question_tags'  => $tags,
            'option_a'       => sanitize_text_field( $oa ),
            'option_b'       => sanitize_text_field( $ob ),
            'option_c'       => sanitize_text_field( $oc ),
            'option_d'       => sanitize_text_field( $od ),
            'correct_option' => $corr,
            'explanation'    => sanitize_textarea_field( (string) ( $item['explanation'] ?? '' ) ),
            'difficulty'     => $diff,
        ];
    }
}
