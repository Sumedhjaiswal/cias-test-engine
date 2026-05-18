<?php
/**
 * CIAS Phase B – Database Schema
 *
 * New tables:
 *   cias_answer_submissions   Core record for every answer upload
 *   cias_ocr_results          OCR output, confidence score, extracted text
 *   cias_ai_evaluations       Structured AI evaluation per submission
 *   cias_teacher_reviews      Teacher override / manual annotation
 *   cias_job_queue            Async job tracking (OCR, eval, analytics)
 *   cias_analytics_daily      Pre-aggregated daily stats per student
 *   cias_topic_performance    Pre-aggregated per-topic writing performance
 *
 * All tables carry proper indexes. No full-table scans on dashboard load.
 *
 * @package CIAS\PhaseB
 * @since   3.19.0
 */
defined( 'ABSPATH' ) || exit;

class CIAS_DB_Phase_B {

    const DB_VERSION_OPTION = 'cias_phase_b_db_version';

    public static function install(): void {
        global $wpdb;
        require_once ABSPATH . 'wp-admin/includes/upgrade.php';
        $c = $wpdb->get_charset_collate();

        // ── Answer submissions ───────────────────────────────────────────────
        // One row per upload.  r2_key = R2 object key (not full URL, so bucket can change).
        // submission_type: 'answer_writing' | 'mains_practice' | 'chat_image'
        // status lifecycle: pending → ocr_processing → ocr_done | ocr_failed
        //                              → needs_confirmation | teacher_review
        //                              → evaluating → evaluated | eval_failed
        dbDelta( "CREATE TABLE IF NOT EXISTS " . CIAS_SUBMISSIONS . " (
            id              BIGINT UNSIGNED  NOT NULL AUTO_INCREMENT,
            user_id         BIGINT UNSIGNED  NOT NULL,
            session_id      VARCHAR(64)      NOT NULL DEFAULT '',
            question_id     INT              DEFAULT NULL,
            question_text   TEXT             DEFAULT NULL,
            subject_id      INT              DEFAULT NULL,
            topic_id        INT              DEFAULT NULL,
            submission_type VARCHAR(32)      NOT NULL DEFAULT 'answer_writing',
            r2_key          VARCHAR(500)     NOT NULL,
            r2_bucket       VARCHAR(100)     NOT NULL DEFAULT '',
            file_mime       VARCHAR(50)      NOT NULL DEFAULT 'image/jpeg',
            file_size_bytes INT              DEFAULT NULL,
            status          VARCHAR(32)      NOT NULL DEFAULT 'pending',
            ocr_result_id   BIGINT UNSIGNED  DEFAULT NULL,
            eval_id         BIGINT UNSIGNED  DEFAULT NULL,
            review_id       BIGINT UNSIGNED  DEFAULT NULL,
            job_id          BIGINT UNSIGNED  DEFAULT NULL,
            created_at      DATETIME         NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at      DATETIME         NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            KEY idx_user_status    (user_id, status),
            KEY idx_session        (session_id),
            KEY idx_question       (question_id),
            KEY idx_subject_topic  (subject_id, topic_id),
            KEY idx_created        (created_at),
            KEY idx_status         (status)
        ) $c;" );

        // ── OCR results ──────────────────────────────────────────────────────
        // confidence: 0.00–1.00   legibility: clear | partial | unclear
        // confirmed: 1 when student confirmed text is correct
        dbDelta( "CREATE TABLE IF NOT EXISTS " . CIAS_OCR_RESULTS . " (
            id              BIGINT UNSIGNED  NOT NULL AUTO_INCREMENT,
            submission_id   BIGINT UNSIGNED  NOT NULL,
            user_id         BIGINT UNSIGNED  NOT NULL,
            raw_text        LONGTEXT         NOT NULL,
            confirmed_text  LONGTEXT         DEFAULT NULL,
            confidence      DECIMAL(4,3)     NOT NULL DEFAULT 0.000,
            legibility      VARCHAR(16)      NOT NULL DEFAULT 'clear',
            confirmed       TINYINT(1)       NOT NULL DEFAULT 0,
            confirmed_at    DATETIME         DEFAULT NULL,
            word_count      SMALLINT         DEFAULT NULL,
            model_used      VARCHAR(80)      DEFAULT NULL,
            input_tokens    INT              DEFAULT NULL,
            output_tokens   INT              DEFAULT NULL,
            created_at      DATETIME         NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            KEY idx_submission (submission_id),
            KEY idx_user       (user_id),
            KEY idx_confidence (confidence)
        ) $c;" );

        // ── AI evaluations ───────────────────────────────────────────────────
        // score: 0–100   criterion_scores: JSON array of per-criterion scores
        // feedback_json: structured {introduction, arguments, conclusion, suggestions[]}
        // prompt_cache_key: SHA256 of system prompt for cache hit tracking
        dbDelta( "CREATE TABLE IF NOT EXISTS " . CIAS_AI_EVALUATIONS . " (
            id                 BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            submission_id      BIGINT UNSIGNED NOT NULL,
            user_id            BIGINT UNSIGNED NOT NULL,
            question_id        INT             DEFAULT NULL,
            ocr_result_id      BIGINT UNSIGNED DEFAULT NULL,
            score              TINYINT UNSIGNED NOT NULL DEFAULT 0,
            max_score          TINYINT UNSIGNED NOT NULL DEFAULT 100,
            criterion_scores   JSON            DEFAULT NULL,
            feedback_json      JSON            DEFAULT NULL,
            improvement_points JSON            DEFAULT NULL,
            model_used         VARCHAR(80)     NOT NULL DEFAULT '',
            input_tokens       INT             DEFAULT NULL,
            output_tokens      INT             DEFAULT NULL,
            cost_usd           DECIMAL(10,6)   DEFAULT NULL,
            prompt_cache_key   VARCHAR(64)     DEFAULT NULL,
            cache_hit          TINYINT(1)      DEFAULT 0,
            is_batch           TINYINT(1)      DEFAULT 0,
            batch_job_id       VARCHAR(64)     DEFAULT NULL,
            evaluated_at       DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            KEY idx_submission (submission_id),
            KEY idx_user_q     (user_id, question_id),
            KEY idx_evaluated  (evaluated_at),
            KEY idx_score      (score)
        ) $c;" );

        // ── Teacher reviews ──────────────────────────────────────────────────
        // override_score: teacher manually sets score (overrides AI)
        // status: pending | in_review | reviewed | escalated
        dbDelta( "CREATE TABLE IF NOT EXISTS " . CIAS_TEACHER_REVIEWS . " (
            id              BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            submission_id   BIGINT UNSIGNED NOT NULL,
            user_id         BIGINT UNSIGNED NOT NULL,
            teacher_id      BIGINT UNSIGNED DEFAULT NULL,
            eval_id         BIGINT UNSIGNED DEFAULT NULL,
            status          VARCHAR(20)     NOT NULL DEFAULT 'pending',
            priority        TINYINT         NOT NULL DEFAULT 5,
            queue_reason    VARCHAR(64)     DEFAULT NULL,
            teacher_notes   TEXT            DEFAULT NULL,
            override_score  TINYINT UNSIGNED DEFAULT NULL,
            override_feedback TEXT          DEFAULT NULL,
            assigned_at     DATETIME        DEFAULT NULL,
            reviewed_at     DATETIME        DEFAULT NULL,
            created_at      DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            KEY idx_status_priority  (status, priority),
            KEY idx_teacher          (teacher_id),
            KEY idx_user             (user_id),
            KEY idx_submission       (submission_id)
        ) $c;" );

        // ── Job queue ────────────────────────────────────────────────────────
        // type: ocr | evaluate | evaluate_batch | analytics | notify
        // status: pending | processing | done | failed | dead
        // payload_json: all data the worker needs (no extra DB lookups required)
        // attempts: retry count, max_attempts: give up after this many
        // worker_id: which worker instance claimed this job (for dedup)
        dbDelta( "CREATE TABLE IF NOT EXISTS " . CIAS_JOB_QUEUE . " (
            id              BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            type            VARCHAR(32)     NOT NULL,
            status          VARCHAR(16)     NOT NULL DEFAULT 'pending',
            priority        TINYINT         NOT NULL DEFAULT 5,
            payload_json    JSON            NOT NULL,
            result_json     JSON            DEFAULT NULL,
            attempts        TINYINT         NOT NULL DEFAULT 0,
            max_attempts    TINYINT         NOT NULL DEFAULT 3,
            error_message   TEXT            DEFAULT NULL,
            worker_id       VARCHAR(64)     DEFAULT NULL,
            available_at    DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
            started_at      DATETIME        DEFAULT NULL,
            finished_at     DATETIME        DEFAULT NULL,
            created_at      DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            KEY idx_type_status    (type, status, priority, available_at),
            KEY idx_status         (status),
            KEY idx_available      (available_at),
            KEY idx_worker         (worker_id)
        ) $c;" );

        // ── Pre-aggregated daily analytics ───────────────────────────────────
        // Rebuilt nightly by analytics worker. Dashboard reads ONLY this table.
        // UNIQUE KEY ensures ON DUPLICATE KEY UPDATE works correctly.
        dbDelta( "CREATE TABLE IF NOT EXISTS " . CIAS_ANALYTICS_DAILY . " (
            id                  INT UNSIGNED    NOT NULL AUTO_INCREMENT,
            user_id             BIGINT UNSIGNED NOT NULL,
            stat_date           DATE            NOT NULL,
            tests_taken         SMALLINT        NOT NULL DEFAULT 0,
            avg_test_score      DECIMAL(5,2)    DEFAULT NULL,
            guru_messages       SMALLINT        NOT NULL DEFAULT 0,
            answers_submitted   SMALLINT        NOT NULL DEFAULT 0,
            answers_evaluated   SMALLINT        NOT NULL DEFAULT 0,
            avg_writing_score   DECIMAL(5,2)    DEFAULT NULL,
            credits_used        SMALLINT        NOT NULL DEFAULT 0,
            credits_purchased   SMALLINT        NOT NULL DEFAULT 0,
            streak_day          TINYINT         NOT NULL DEFAULT 0,
            ocr_confirmations   SMALLINT        NOT NULL DEFAULT 0,
            teacher_reviews     SMALLINT        NOT NULL DEFAULT 0,
            PRIMARY KEY (id),
            UNIQUE KEY user_date (user_id, stat_date),
            KEY idx_date (stat_date),
            KEY idx_user (user_id)
        ) $c;" );

        // ── Pre-aggregated topic-level writing performance ────────────────────
        dbDelta( "CREATE TABLE IF NOT EXISTS " . CIAS_TOPIC_PERF . " (
            id              INT UNSIGNED    NOT NULL AUTO_INCREMENT,
            user_id         BIGINT UNSIGNED NOT NULL,
            subject_id      INT             NOT NULL,
            topic_id        INT             NOT NULL DEFAULT 0,
            submissions     SMALLINT        NOT NULL DEFAULT 0,
            evaluations     SMALLINT        NOT NULL DEFAULT 0,
            avg_score       DECIMAL(5,2)    DEFAULT NULL,
            best_score      TINYINT         DEFAULT NULL,
            last_submission DATE            DEFAULT NULL,
            updated_at      DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            UNIQUE KEY user_subject_topic (user_id, subject_id, topic_id),
            KEY idx_user (user_id),
            KEY idx_avg_score (avg_score)
        ) $c;" );

        // ── Safe ALTER for jobs queue: add missing columns on existing installs ─
        $job_cols = $wpdb->get_col( "SHOW COLUMNS FROM `" . CIAS_JOB_QUEUE . "`" );
        if ( ! in_array( 'available_at', $job_cols, true ) ) {
            $wpdb->query( "ALTER TABLE `" . CIAS_JOB_QUEUE . "` ADD COLUMN available_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP AFTER worker_id" );
        }

        update_option( self::DB_VERSION_OPTION, CIAS_PHASE_B_VERSION );
    }

    // ── Job queue helpers ────────────────────────────────────────────────────

    /**
     * Push a job to the DB queue.
     * Also pushes a lightweight "wake" signal to Redis so workers don't sleep unnecessarily.
     *
     * @param string $type     ocr | evaluate | evaluate_batch | analytics
     * @param array  $payload  All data the worker needs
     * @param int    $priority 1=highest, 10=lowest
     * @param int    $delay    Seconds before job becomes available
     * @return int|false  Job ID
     */
    public static function push_job( string $type, array $payload, int $priority = 5, int $delay = 0 ) {
        global $wpdb;

        $available_at = $delay > 0
            ? gmdate( 'Y-m-d H:i:s', time() + $delay )
            : current_time( 'mysql' );

        $result = $wpdb->insert( CIAS_JOB_QUEUE, [
            'type'         => $type,
            'status'       => 'pending',
            'priority'     => min( 10, max( 1, $priority ) ),
            'payload_json' => wp_json_encode( $payload ),
            'max_attempts' => 3,
            'available_at' => $available_at,
            'created_at'   => current_time( 'mysql' ),
        ] );

        if ( ! $result ) return false;
        $job_id = (int) $wpdb->insert_id;

        // Signal Redis so the worker wakes immediately (best-effort)
        try {
            CIAS_Queue::signal_wake( $type );
        } catch ( \Throwable $e ) {
            // Redis unavailable — worker will pick it up on next poll cycle
        }

        return $job_id;
    }

    /**
     * Claim the next available job of a given type.
     * Uses atomic UPDATE+SELECT to prevent double-claiming under concurrency.
     */
    public static function claim_next_job( string $type, string $worker_id ): ?object {
        global $wpdb;

        // Atomic claim: only one worker wins the race
        $claimed = $wpdb->query( $wpdb->prepare(
            "UPDATE `" . CIAS_JOB_QUEUE . "`
             SET status = 'processing', worker_id = %s, started_at = NOW()
             WHERE type = %s
               AND status = 'pending'
               AND attempts < max_attempts
               AND available_at <= NOW()
             ORDER BY priority ASC, created_at ASC
             LIMIT 1",
            $worker_id, $type
        ) );

        if ( ! $claimed ) return null;

        return $wpdb->get_row( $wpdb->prepare(
            "SELECT * FROM `" . CIAS_JOB_QUEUE . "` WHERE worker_id = %s AND status = 'processing' AND type = %s ORDER BY started_at DESC LIMIT 1",
            $worker_id, $type
        ) );
    }

    public static function complete_job( int $job_id, array $result ): void {
        global $wpdb;
        $wpdb->update( CIAS_JOB_QUEUE,
            [ 'status' => 'done', 'result_json' => wp_json_encode( $result ), 'finished_at' => current_time('mysql') ],
            [ 'id' => $job_id ]
        );
    }

    public static function fail_job( int $job_id, string $error, bool $is_final = false ): void {
        global $wpdb;
        $row = $wpdb->get_row( $wpdb->prepare( "SELECT attempts, max_attempts FROM `" . CIAS_JOB_QUEUE . "` WHERE id=%d", $job_id ) );
        $new_attempts = (int)($row->attempts ?? 0) + 1;
        $new_status   = ( $is_final || $new_attempts >= (int)($row->max_attempts ?? 3) ) ? 'dead' : 'pending';

        // Exponential back-off: 2^attempts minutes
        $next_run = gmdate( 'Y-m-d H:i:s', time() + pow( 2, $new_attempts ) * 60 );

        $wpdb->update( CIAS_JOB_QUEUE, [
            'status'        => $new_status,
            'attempts'      => $new_attempts,
            'error_message' => mb_substr( $error, 0, 500 ),
            'worker_id'     => null,
            'available_at'  => $next_run,
        ], [ 'id' => $job_id ] );
    }
}
