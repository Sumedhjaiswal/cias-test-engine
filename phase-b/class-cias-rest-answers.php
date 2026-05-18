<?php
/**
 * CIAS Phase B – REST: Answer Submission Endpoints
 *
 * POST /wp-json/cias/v1/answer/submit        Submit answer metadata (after R2 upload)
 * GET  /wp-json/cias/v1/answer/{id}          Get single submission
 * GET  /wp-json/cias/v1/answer/{id}/confirm  Confirm OCR text
 * POST /wp-json/cias/v1/answer/{id}/confirm  Submit OCR confirmation
 * GET  /wp-json/cias/v1/answers              List user's submissions (paginated)
 *
 * The endpoint NEVER calls Claude.
 * After inserting the DB record it pushes an OCR job and returns 202.
 *
 * @package CIAS\PhaseB
 * @since   3.19.0
 */
defined( 'ABSPATH' ) || exit;

class CIAS_REST_Answers {

    const NAMESPACE = 'cias/v1';

    public static function register_routes(): void {
        register_rest_route( self::NAMESPACE, '/answer/submit', [
            'methods'             => 'POST',
            'callback'            => [ __CLASS__, 'submit' ],
            'permission_callback' => [ __CLASS__, 'is_logged_in' ],
            'args'                => self::submit_args(),
        ] );

        register_rest_route( self::NAMESPACE, '/answer/(?P<id>\d+)', [
            [
                'methods'             => 'GET',
                'callback'            => [ __CLASS__, 'get_submission' ],
                'permission_callback' => [ __CLASS__, 'can_view_submission' ],
            ],
        ] );

        register_rest_route( self::NAMESPACE, '/answer/(?P<id>\d+)/confirm', [
            'methods'             => 'POST',
            'callback'            => [ __CLASS__, 'confirm_ocr' ],
            'permission_callback' => [ __CLASS__, 'can_view_submission' ],
            'args'                => [
                'confirmed_text' => [ 'required' => true, 'type' => 'string', 'sanitize_callback' => 'sanitize_textarea_field' ],
            ],
        ] );

        register_rest_route( self::NAMESPACE, '/answers', [
            'methods'             => 'GET',
            'callback'            => [ __CLASS__, 'list_submissions' ],
            'permission_callback' => [ __CLASS__, 'is_logged_in' ],
            'args'                => [
                'page'    => [ 'type' => 'integer', 'default' => 1, 'minimum' => 1 ],
                'per_page'=> [ 'type' => 'integer', 'default' => 20, 'maximum' => 50 ],
                'status'  => [ 'type' => 'string',  'default' => '' ],
            ],
        ] );
    }

    // ── POST /answer/submit ────────────────────────────────────────────────────

    public static function submit( WP_REST_Request $request ): WP_REST_Response {
        $user_id   = get_current_user_id();

        // ── Rate limit ─────────────────────────────────────────────────────
        $rl = CIAS_Queue::rate_check( $user_id, 'answer_submit' );
        if ( ! $rl['allowed'] ) {
            return new WP_REST_Response( [ 'error' => 'Too many submissions. Please wait.' ], 429 );
        }

        $object_key      = $request->get_param('object_key');
        $mime_type       = $request->get_param('mime_type');
        $file_size       = (int) $request->get_param('file_size');
        $submission_type = $request->get_param('submission_type');
        $question_id     = (int) $request->get_param('question_id');
        $question_text   = $request->get_param('question_text');
        $subject_id      = (int) $request->get_param('subject_id');
        $topic_id        = (int) $request->get_param('topic_id');
        $session_id      = $request->get_param('session_id') ?: '';

        // ── Validate R2 key ────────────────────────────────────────────────
        if ( ! $object_key || ! str_starts_with( $object_key, 'answers/' ) ) {
            return new WP_REST_Response( [ 'error' => 'Invalid object key. Upload the file first.' ], 422 );
        }
        if ( ! in_array( $mime_type, ['image/jpeg','image/png','image/webp','image/gif','application/pdf'], true ) ) {
            return new WP_REST_Response( [ 'error' => 'Unsupported file type.' ], 422 );
        }

        // ── Insert submission record ───────────────────────────────────────
        global $wpdb;
        $inserted = $wpdb->insert( CIAS_SUBMISSIONS, [
            'user_id'         => $user_id,
            'session_id'      => sanitize_text_field( $session_id ),
            'question_id'     => $question_id ?: null,
            'question_text'   => $question_text ? sanitize_textarea_field( $question_text ) : null,
            'subject_id'      => $subject_id ?: null,
            'topic_id'        => $topic_id ?: null,
            'submission_type' => $submission_type,
            'r2_key'          => $object_key,
            'r2_bucket'       => CIAS_R2::bucket(),
            'file_mime'       => $mime_type,
            'file_size_bytes' => $file_size ?: null,
            'status'          => 'pending',
            'created_at'      => current_time('mysql'),
            'updated_at'      => current_time('mysql'),
        ] );

        if ( ! $inserted ) {
            return new WP_REST_Response( [ 'error' => 'Could not save submission. Try again.' ], 500 );
        }

        $submission_id = (int) $wpdb->insert_id;

        // ── Push OCR job ───────────────────────────────────────────────────
        // RULE: Never call Claude here. Push to queue and return immediately.
        $job_id = CIAS_DB_Phase_B::push_job( 'ocr', [
            'submission_id' => $submission_id,
            'user_id'       => $user_id,
            'r2_key'        => $object_key,
            'r2_bucket'     => CIAS_R2::bucket(),
            'mime_type'     => $mime_type,
            'question_id'   => $question_id ?: null,
            'subject_id'    => $subject_id ?: null,
            'topic_id'      => $topic_id ?: null,
        ], priority: 3 );

        // Link job ID to submission
        if ( $job_id ) {
            $wpdb->update( CIAS_SUBMISSIONS, [ 'job_id' => $job_id ], [ 'id' => $submission_id ] );
        }

        // ── Also record in chat session if submitted from AI Guru chat ────
        if ( $session_id ) {
            do_action( 'cias_guru_user_message', [
                'session_id'  => $session_id,
                'user_id'     => $user_id,
                'body'        => '[Answer Image Submitted — waiting for OCR]',
                'message_type'=> 'image_query',
                'media_url'   => CIAS_R2::public_url_for( $object_key ),
                'tokens'      => null,
                'credits'     => null,
            ] );
        }

        return new WP_REST_Response( [
            'submission_id' => $submission_id,
            'job_id'        => $job_id,
            'status'        => 'pending',
            'status_url'    => rest_url( "cias/v1/job/{$job_id}/status" ),
            'message'       => 'Submission received. OCR processing will begin shortly.',
            'estimated_seconds' => 15,
        ], 202 );
    }

    // ── GET /answer/{id} ──────────────────────────────────────────────────────

    public static function get_submission( WP_REST_Request $request ): WP_REST_Response {
        $id = (int) $request->get_param('id');
        global $wpdb;

        $row = $wpdb->get_row( $wpdb->prepare(
            "SELECT s.*,
                    ocr.raw_text, ocr.confirmed_text, ocr.confidence, ocr.legibility, ocr.confirmed,
                    ev.score, ev.max_score, ev.feedback_json, ev.criterion_scores, ev.improvement_points,
                    ev.evaluated_at,
                    rv.status AS review_status, rv.teacher_notes, rv.override_score, rv.override_feedback
             FROM " . CIAS_SUBMISSIONS . " s
             LEFT JOIN " . CIAS_OCR_RESULTS . "    ocr ON ocr.submission_id = s.id
             LEFT JOIN " . CIAS_AI_EVALUATIONS . " ev  ON ev.submission_id  = s.id
             LEFT JOIN " . CIAS_TEACHER_REVIEWS . " rv ON rv.submission_id  = s.id
             WHERE s.id = %d",
            $id
        ) );

        if ( ! $row ) {
            return new WP_REST_Response( [ 'error' => 'Submission not found.' ], 404 );
        }

        // Decode JSON fields
        $row->feedback_json       = $row->feedback_json       ? json_decode( $row->feedback_json, true )       : null;
        $row->criterion_scores    = $row->criterion_scores    ? json_decode( $row->criterion_scores, true )    : null;
        $row->improvement_points  = $row->improvement_points  ? json_decode( $row->improvement_points, true )  : null;
        $row->image_url           = CIAS_R2::public_url_for( $row->r2_key );

        return new WP_REST_Response( $row, 200 );
    }

    // ── POST /answer/{id}/confirm ─────────────────────────────────────────────

    public static function confirm_ocr( WP_REST_Request $request ): WP_REST_Response {
        $submission_id  = (int) $request->get_param('id');
        $confirmed_text = $request->get_param('confirmed_text');
        $user_id        = get_current_user_id();

        global $wpdb;

        // Update OCR result with confirmed text
        $wpdb->query( $wpdb->prepare(
            "UPDATE " . CIAS_OCR_RESULTS . "
             SET confirmed = 1, confirmed_text = %s, confirmed_at = NOW()
             WHERE submission_id = %d AND user_id = %d",
            $confirmed_text, $submission_id, $user_id
        ) );

        // Update submission status
        $wpdb->update( CIAS_SUBMISSIONS,
            [ 'status' => 'ocr_confirmed', 'updated_at' => current_time('mysql') ],
            [ 'id' => $submission_id, 'user_id' => $user_id ]
        );

        // Push evaluation job
        $submission = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM " . CIAS_SUBMISSIONS . " WHERE id=%d", $submission_id ) );

        $job_id = CIAS_DB_Phase_B::push_job( 'evaluate', [
            'submission_id'  => $submission_id,
            'user_id'        => $user_id,
            'confirmed_text' => $confirmed_text,
            'question_id'    => $submission->question_id ?? null,
            'question_text'  => $submission->question_text ?? null,
            'subject_id'     => $submission->subject_id ?? null,
            'topic_id'       => $submission->topic_id ?? null,
        ], priority: 4 );

        return new WP_REST_Response( [
            'success'       => true,
            'submission_id' => $submission_id,
            'job_id'        => $job_id,
            'status'        => 'evaluating',
            'status_url'    => $job_id ? rest_url("cias/v1/job/{$job_id}/status") : null,
            'message'       => 'Text confirmed. Evaluation in progress.',
        ], 202 );
    }

    // ── GET /answers ──────────────────────────────────────────────────────────

    public static function list_submissions( WP_REST_Request $request ): WP_REST_Response {
        $user_id  = get_current_user_id();
        $page     = (int) $request->get_param('page');
        $per_page = (int) $request->get_param('per_page');
        $status   = sanitize_key( $request->get_param('status') );
        $offset   = ( $page - 1 ) * $per_page;

        global $wpdb;
        $where = 's.user_id = %d';
        $args  = [ $user_id ];

        if ( $status ) {
            $where .= ' AND s.status = %s';
            $args[] = $status;
        }

        $total = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM " . CIAS_SUBMISSIONS . " s WHERE {$where}", ...$args ) );

        $rows = $wpdb->get_results( $wpdb->prepare(
            "SELECT s.id, s.status, s.submission_type, s.file_mime, s.created_at, s.r2_key,
                    s.question_id, s.subject_id, s.topic_id,
                    ocr.confidence, ocr.confirmed,
                    ev.score, ev.max_score
             FROM " . CIAS_SUBMISSIONS . " s
             LEFT JOIN " . CIAS_OCR_RESULTS . "    ocr ON ocr.submission_id = s.id
             LEFT JOIN " . CIAS_AI_EVALUATIONS . " ev  ON ev.submission_id = s.id
             WHERE {$where}
             ORDER BY s.created_at DESC
             LIMIT %d OFFSET %d",
            ...[...$args, $per_page, $offset]
        ) );

        foreach ( $rows as $row ) {
            $row->image_url = CIAS_R2::public_url_for( $row->r2_key );
        }

        return new WP_REST_Response( [
            'submissions' => $rows,
            'total'       => $total,
            'page'        => $page,
            'total_pages' => (int) ceil( $total / $per_page ),
        ], 200 );
    }

    // ── Permissions ───────────────────────────────────────────────────────────

    public static function is_logged_in(): bool {
        return is_user_logged_in();
    }

    public static function can_view_submission( WP_REST_Request $request ): bool {
        if ( ! is_user_logged_in() ) return false;
        $id      = (int) $request->get_param('id');
        $user_id = get_current_user_id();

        if ( current_user_can('manage_options') || current_user_can('cias_view_reports') ) return true;

        global $wpdb;
        $owner = (int) $wpdb->get_var( $wpdb->prepare(
            "SELECT user_id FROM " . CIAS_SUBMISSIONS . " WHERE id = %d", $id
        ) );
        return $owner === $user_id;
    }

    // ── Arg schema ────────────────────────────────────────────────────────────

    private static function submit_args(): array {
        return [
            'object_key'      => [ 'required' => true,  'type' => 'string',  'sanitize_callback' => 'sanitize_text_field' ],
            'mime_type'       => [ 'required' => true,  'type' => 'string',  'sanitize_callback' => 'sanitize_text_field' ],
            'file_size'       => [ 'required' => false, 'type' => 'integer', 'default' => 0 ],
            'submission_type' => [ 'required' => false, 'type' => 'string',  'default' => 'answer_writing', 'sanitize_callback' => 'sanitize_key' ],
            'question_id'     => [ 'required' => false, 'type' => 'integer', 'default' => 0 ],
            'question_text'   => [ 'required' => false, 'type' => 'string',  'sanitize_callback' => 'sanitize_textarea_field', 'default' => '' ],
            'subject_id'      => [ 'required' => false, 'type' => 'integer', 'default' => 0 ],
            'topic_id'        => [ 'required' => false, 'type' => 'integer', 'default' => 0 ],
            'session_id'      => [ 'required' => false, 'type' => 'string',  'sanitize_callback' => 'sanitize_text_field', 'default' => '' ],
        ];
    }
}
