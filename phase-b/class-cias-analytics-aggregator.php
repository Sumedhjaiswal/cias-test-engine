<?php
/**
 * CIAS Phase B – Analytics Aggregator
 *
 * Two modes:
 *   1. WordPress cron (in-process): aggregate data every hour for active users
 *   2. CLI worker: full rebuild of analytics tables (run nightly)
 *
 * NEVER run expensive queries on dashboard page load.
 * Teacher dashboard reads ONLY cias_analytics_daily and cias_topic_performance.
 *
 * Aggregation jobs:
 *   - Daily student stats (tests, chat, submissions, scores, credits)
 *   - Topic-level writing performance (per subject/topic)
 *   - Streak calculation
 *
 * @package CIAS\PhaseB
 * @since   3.19.0
 */
defined( 'ABSPATH' ) || exit;

class CIAS_Analytics_Aggregator {

    // ── Cron registration ─────────────────────────────────────────────────────

    public static function init_cron(): void {
        add_action( 'cias_analytics_hourly',  [ __CLASS__, 'run_hourly_cron' ] );
        add_action( 'cias_analytics_nightly', [ __CLASS__, 'run_nightly_cron' ] );

        if ( ! wp_next_scheduled('cias_analytics_hourly') ) {
            wp_schedule_event( time(), 'hourly', 'cias_analytics_hourly' );
        }
        if ( ! wp_next_scheduled('cias_analytics_nightly') ) {
            $midnight = strtotime('tomorrow 00:30:00');
            wp_schedule_event( $midnight, 'daily', 'cias_analytics_nightly' );
        }
    }

    // ── Hourly: aggregate only users active in the last 2 hours ──────────────

    public static function run_hourly_cron(): void {
        global $wpdb;

        // Find recently active users (chat or test activity)
        $active_users = $wpdb->get_col(
            "SELECT DISTINCT user_id FROM {$wpdb->prefix}cias_chat_messages
             WHERE created_at >= DATE_SUB(NOW(), INTERVAL 2 HOUR)
             UNION
             SELECT DISTINCT user_id FROM " . CIAS_ATTEMPTS . "
             WHERE submitted_at >= DATE_SUB(NOW(), INTERVAL 2 HOUR)"
        );

        if ( empty($active_users) ) return;

        foreach ( $active_users as $user_id ) {
            self::aggregate_user_day( (int)$user_id, current_time('Y-m-d') );
        }
    }

    // ── Nightly: rebuild all analytics for yesterday ──────────────────────────

    public static function run_nightly_cron(): void {
        // Push to async job queue (don't block cron thread)
        CIAS_DB_Phase_B::push_job( 'analytics', [
            'type'      => 'nightly_rebuild',
            'date'      => gmdate('Y-m-d', strtotime('yesterday')),
            'full_run'  => true,
        ], priority: 9 );
    }

    // ── Worker entry point (called from worker-analytics.php) ─────────────────

    /**
     * Full nightly rebuild — processes all users who had activity yesterday.
     */
    public static function run_nightly_rebuild( string $date ): array {
        global $wpdb;

        // All users with any activity on $date
        $user_ids = $wpdb->get_col( $wpdb->prepare(
            "SELECT DISTINCT user_id FROM {$wpdb->prefix}cias_chat_messages WHERE DATE(created_at) = %s
             UNION
             SELECT DISTINCT user_id FROM " . CIAS_ATTEMPTS . " WHERE DATE(submitted_at) = %s
             UNION
             SELECT DISTINCT user_id FROM " . CIAS_SUBMISSIONS . " WHERE DATE(created_at) = %s",
            $date, $date, $date
        ) );

        $count = 0;
        foreach ( $user_ids as $user_id ) {
            self::aggregate_user_day( (int)$user_id, $date );
            $count++;
        }

        // Also update topic performance for users who got evaluations
        $eval_users = $wpdb->get_col( $wpdb->prepare(
            "SELECT DISTINCT user_id FROM " . CIAS_AI_EVALUATIONS . " WHERE DATE(evaluated_at) = %s", $date
        ) );
        foreach ( $eval_users as $uid ) {
            self::rebuild_topic_performance( (int)$uid );
        }

        return [ 'date' => $date, 'users_aggregated' => $count ];
    }

    // ── Core: aggregate one user's stats for one date ─────────────────────────

    public static function aggregate_user_day( int $user_id, string $date ): void {
        global $wpdb;

        $msg_table = $wpdb->prefix . 'cias_chat_messages';

        // Test stats for date
        $test_stats = $wpdb->get_row( $wpdb->prepare(
            "SELECT COUNT(*) AS tests_taken, ROUND(AVG(percentage),2) AS avg_score
             FROM " . CIAS_ATTEMPTS . "
             WHERE user_id=%d AND DATE(submitted_at)=%s AND status='submitted'",
            $user_id, $date
        ) );

        // Chat stats
        $guru_msgs = (int) $wpdb->get_var( $wpdb->prepare(
            "SELECT COUNT(*) FROM $msg_table WHERE user_id=%d AND role='user' AND DATE(created_at)=%s",
            $user_id, $date
        ) );

        // Submission stats
        $sub_stats = $wpdb->get_row( $wpdb->prepare(
            "SELECT COUNT(*) AS submitted,
                    SUM(status='evaluated') AS evaluated,
                    (SELECT ROUND(AVG(ev.score),2) FROM " . CIAS_AI_EVALUATIONS . " ev
                     WHERE ev.user_id=%d AND DATE(ev.evaluated_at)=%s) AS avg_writing
             FROM " . CIAS_SUBMISSIONS . "
             WHERE user_id=%d AND DATE(created_at)=%s",
            $user_id, $date, $user_id, $date
        ) );

        // Credit stats
        $cred_used = (int) $wpdb->get_var( $wpdb->prepare(
            "SELECT ABS(SUM(credits)) FROM {$wpdb->prefix}cias_ai_credit_log
             WHERE user_id=%d AND action='usage' AND DATE(created_at)=%s",
            $user_id, $date
        ) );
        $cred_purchased = (int) $wpdb->get_var( $wpdb->prepare(
            "SELECT SUM(credits) FROM {$wpdb->prefix}cias_ai_credit_log
             WHERE user_id=%d AND action='purchase' AND DATE(created_at)=%s",
            $user_id, $date
        ) );

        // OCR confirmations
        $ocr_confirms = (int) $wpdb->get_var( $wpdb->prepare(
            "SELECT COUNT(*) FROM " . CIAS_OCR_RESULTS . "
             WHERE user_id=%d AND confirmed=1 AND DATE(confirmed_at)=%s",
            $user_id, $date
        ) );

        // Teacher reviews sent
        $teacher_reviews = (int) $wpdb->get_var( $wpdb->prepare(
            "SELECT COUNT(*) FROM " . CIAS_TEACHER_REVIEWS . "
             WHERE user_id=%d AND DATE(created_at)=%s",
            $user_id, $date
        ) );

        // Streak: count consecutive days with activity (from today backwards)
        $streak = self::calculate_streak( $user_id, $date );

        // Upsert (ON DUPLICATE KEY UPDATE)
        $wpdb->query( $wpdb->prepare(
            "INSERT INTO " . CIAS_ANALYTICS_DAILY . "
             (user_id, stat_date, tests_taken, avg_test_score, guru_messages,
              answers_submitted, answers_evaluated, avg_writing_score,
              credits_used, credits_purchased, streak_day, ocr_confirmations, teacher_reviews)
             VALUES (%d, %s, %d, %s, %d, %d, %d, %s, %d, %d, %d, %d, %d)
             ON DUPLICATE KEY UPDATE
               tests_taken        = VALUES(tests_taken),
               avg_test_score     = VALUES(avg_test_score),
               guru_messages      = VALUES(guru_messages),
               answers_submitted  = VALUES(answers_submitted),
               answers_evaluated  = VALUES(answers_evaluated),
               avg_writing_score  = VALUES(avg_writing_score),
               credits_used       = VALUES(credits_used),
               credits_purchased  = VALUES(credits_purchased),
               streak_day         = VALUES(streak_day),
               ocr_confirmations  = VALUES(ocr_confirmations),
               teacher_reviews    = VALUES(teacher_reviews)",
            $user_id, $date,
            (int)($test_stats->tests_taken ?? 0),
            $test_stats->avg_score ?? null,
            $guru_msgs,
            (int)($sub_stats->submitted ?? 0),
            (int)($sub_stats->evaluated ?? 0),
            $sub_stats->avg_writing ?? null,
            $cred_used, $cred_purchased,
            $streak,
            $ocr_confirms, $teacher_reviews
        ) );
    }

    // ── Streak calculation ────────────────────────────────────────────────────

    private static function calculate_streak( int $user_id, string $date ): int {
        global $wpdb;
        $streak = 0;
        $check  = $date;

        for ( $i = 0; $i < 365; $i++ ) {
            $has_activity = $wpdb->get_var( $wpdb->prepare(
                "SELECT 1 FROM {$wpdb->prefix}cias_chat_messages WHERE user_id=%d AND DATE(created_at)=%s LIMIT 1
                 UNION ALL
                 SELECT 1 FROM " . CIAS_ATTEMPTS . " WHERE user_id=%d AND DATE(submitted_at)=%s AND status='submitted' LIMIT 1",
                $user_id, $check, $user_id, $check
            ) );

            if ( ! $has_activity ) break;
            $streak++;
            $check = gmdate('Y-m-d', strtotime("{$check} -1 day"));
        }

        return $streak;
    }

    // ── Rebuild topic performance for a user ─────────────────────────────────

    public static function rebuild_topic_performance( int $user_id ): void {
        global $wpdb;

        $rows = $wpdb->get_results( $wpdb->prepare(
            "SELECT s.subject_id, s.topic_id,
                    COUNT(s.id) AS submissions,
                    COUNT(ev.id) AS evaluations,
                    ROUND(AVG(ev.score),2) AS avg_score,
                    MAX(ev.score) AS best_score,
                    MAX(DATE(s.created_at)) AS last_submission
             FROM " . CIAS_SUBMISSIONS . " s
             LEFT JOIN " . CIAS_AI_EVALUATIONS . " ev ON ev.submission_id = s.id
             WHERE s.user_id = %d AND s.subject_id IS NOT NULL
             GROUP BY s.subject_id, s.topic_id",
            $user_id
        ) );

        foreach ( $rows as $row ) {
            $wpdb->query( $wpdb->prepare(
                "INSERT INTO " . CIAS_TOPIC_PERF . "
                 (user_id, subject_id, topic_id, submissions, evaluations, avg_score, best_score, last_submission)
                 VALUES (%d, %d, %d, %d, %d, %s, %d, %s)
                 ON DUPLICATE KEY UPDATE
                   submissions     = VALUES(submissions),
                   evaluations     = VALUES(evaluations),
                   avg_score       = VALUES(avg_score),
                   best_score      = VALUES(best_score),
                   last_submission = VALUES(last_submission)",
                $user_id,
                (int)$row->subject_id,
                (int)($row->topic_id ?? 0),
                (int)$row->submissions,
                (int)$row->evaluations,
                $row->avg_score,
                (int)($row->best_score ?? 0),
                $row->last_submission
            ) );
        }
    }
}
