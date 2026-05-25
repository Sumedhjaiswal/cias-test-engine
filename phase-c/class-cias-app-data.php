<?php
/**
 * CIAS Phase C – App Data Layer
 *
 * Fetches all data needed to bootstrap the JS app for the current user.
 * Called once on page load; all subsequent data fetches use REST API.
 *
 * @package CIAS\PhaseC
 * @since   3.19.0
 */
defined( 'ABSPATH' ) || exit;

class CIAS_App_Data {

    /**
     * Collect and return all bootstrap data for the current user.
     * This object is JSON-encoded and passed to the JS app as `ciasApp`.
     */
    public static function bootstrap( int $user_id ): array {
        global $wpdb;

        $user    = get_userdata( $user_id );
        $credits = self::get_credits( $user_id );
        $stats   = self::get_stats( $user_id );

        $data = [
            'user'        => [
                'id'          => $user_id,
                'name'        => $user->first_name ?: $user->display_name,
                'email'       => $user->user_email,
                'initials'    => strtoupper( substr( $user->first_name ?: $user->display_name, 0, 1 ) . substr( $user->last_name ?: '', 0, 1 ) ) ?: 'U',
                'display_name'=> $user->display_name,
                'batch'       => self::get_student_batch_name( $user_id ),
                'member_since'=> self::get_member_since( $user_id ),
            ],
            'credits'     => $credits,
            'stats'       => $stats,
            'streak'      => self::get_streak( $user_id ),
            'due_today'   => self::get_due_words( $user_id ),
            'recent_submissions' => self::get_recent_submissions( $user_id ),
            'subject_accuracy'   => self::get_subject_accuracy( $user_id ),
            'writing_scores'     => self::get_writing_scores( $user_id ),
            'plan'        => self::get_plan( $user_id ),
            'activity_days'      => self::get_activity_days( $user_id ),
            'due_tests'          => self::get_due_tests( $user_id ),
            'due_revisions'      => self::get_due_revisions( $user_id ),
            'study_plan_today'   => self::get_study_plan_today( $user_id ),
            'rank'               => self::get_leaderboard_rank( $user_id ),
            'notifications'      => self::get_notifications( $user_id ),
            'nonce'       => wp_create_nonce( 'cias_app_nonce' ),
            'rest_nonce'  => wp_create_nonce( 'wp_rest' ),
            'rest_url'    => esc_url_raw( rest_url( 'cias/v1' ) ),
            'ajax_url'    => admin_url( 'admin-ajax.php' ),
            'r2_configured' => defined('CIAS_R2_ACCOUNT_ID') && CIAS_R2_ACCOUNT_ID ? true : false,
            // Bridge flags — overridden by CIAS_Vocab_Bridge::add_bootstrap_flag() when active
            'vocab_bridge'         => false,
            'vocab_rate_action'    => 'cias_vocab_rate',
            'vocab_session_action' => 'cias_vocab_session',
            'vocab_stats_action'   => 'cias_vocab_stats',
        ];

        // Allow bridge (and future bridges) to inject extra flags
        return array_merge( $data, apply_filters( 'cias_app_bootstrap_extra', [], $user_id ) );
    }

    // ── Credits ───────────────────────────────────────────────────────────────

    public static function get_credits( int $user_id ): array {
        global $wpdb;
        $row = $wpdb->get_row( $wpdb->prepare(
            "SELECT credits_remaining, access_type FROM {$wpdb->prefix}cias_ai_credits WHERE user_id = %d",
            $user_id
        ) );
        $used = (int) $wpdb->get_var( $wpdb->prepare(
            "SELECT ABS(SUM(credits)) FROM {$wpdb->prefix}cias_ai_credit_log WHERE user_id = %d AND action = 'usage'",
            $user_id
        ) );

        $remaining  = $row ? (int) $row->credits_remaining : 0;
        $access     = $row ? $row->access_type : 'free';
        $monthly    = $access === 'paid' ? 50 : 5;
        $reset_days = self::days_until_reset();

        return [
            'remaining'   => $remaining,
            'used'        => $used,
            'monthly'     => $monthly,
            'access_type' => $access,
            'reset_days'  => $reset_days,
            'history'     => self::get_credit_history( $user_id ),
        ];
    }

    private static function days_until_reset(): int {
        $next_month = mktime( 0, 0, 0, (int)date('m') + 1, 1 );
        return (int) ceil( ( $next_month - time() ) / DAY_IN_SECONDS );
    }

    private static function get_credit_history( int $user_id ): array {
        global $wpdb;
        return $wpdb->get_results( $wpdb->prepare(
            "SELECT credits AS delta, action AS type, note, order_id, created_at
             FROM {$wpdb->prefix}cias_ai_credit_log
             WHERE user_id = %d
             ORDER BY created_at DESC LIMIT 8",
            $user_id
        ), ARRAY_A ) ?: [];
    }

    // ── Summary stats ─────────────────────────────────────────────────────────

    public static function get_stats( int $user_id ): array {
        global $wpdb;

        // Today's analytics row if available
        $today = $wpdb->get_row( $wpdb->prepare(
            "SELECT * FROM " . ( defined('CIAS_ANALYTICS_DAILY') ? CIAS_ANALYTICS_DAILY : $wpdb->prefix . 'cias_analytics_daily' ) . "
             WHERE user_id = %d AND stat_date = CURDATE()",
            $user_id
        ) );

        // Total tests from attempts table
        $total_tests = defined('CIAS_ATTEMPTS')
            ? (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM " . CIAS_ATTEMPTS . " WHERE user_id = %d AND status = 'submitted'", $user_id ) )
            : 0;

        // Average test score
        $avg_score = defined('CIAS_ATTEMPTS')
            ? (float) $wpdb->get_var( $wpdb->prepare( "SELECT AVG(percentage) FROM " . CIAS_ATTEMPTS . " WHERE user_id = %d AND status = 'submitted'", $user_id ) )
            : 0;

        // Words mastered — filterable so vocab bridge can override with real data
        $words_mastered = (int) $wpdb->get_var( $wpdb->prepare(
            "SELECT COUNT(*) FROM {$wpdb->prefix}cias_user_progress WHERE user_id = %d AND mastered = 1",
            $user_id
        ) );
        if ( ! $words_mastered ) $words_mastered = (int) get_user_meta( $user_id, 'cias_vocab_mastered', true );
        $words_mastered = (int) apply_filters( 'cias_words_mastered', $words_mastered ?: 0, $user_id );

        // Guru messages this month
        $guru_msgs = (int) $wpdb->get_var( $wpdb->prepare(
            "SELECT COUNT(*) FROM {$wpdb->prefix}cias_chat_messages WHERE user_id = %d AND role = 'user' AND created_at >= DATE_FORMAT(NOW(),'%%Y-%%m-01')",
            $user_id
        ) );

        // Vocab accuracy — filterable so bridge can pull from vocab_progress
        $vocab_accuracy = (int) apply_filters( 'cias_vocab_accuracy', round( $avg_score ), $user_id );

        return [
            'tests_taken'       => $total_tests,
            'avg_score'         => round( $avg_score ),
            'vocab_accuracy'    => $vocab_accuracy,
            'words_mastered'    => $words_mastered,
            'guru_messages'     => $guru_msgs,
            'answers_submitted' => $today ? (int)$today->answers_submitted : 0,
        ];
    }

    // ── Streak ────────────────────────────────────────────────────────────────

    /**
     * Unified notifications feed from REAL events only (no fabricated items):
     *  - credit grants / purchases (cias_ai_credit_log)
     *  - test completions (cias_attempts, submitted)
     *  - auto-generated questions ready (cias_gen_notices user meta)
     * Returns newest-first, capped.
     */
    public static function get_notifications( int $user_id ): array {
        global $wpdb;
        $items = [];

        // Credit log events
        $credits = $wpdb->get_results( $wpdb->prepare(
            "SELECT credits, action, note, created_at
             FROM {$wpdb->prefix}cias_ai_credit_log
             WHERE user_id = %d ORDER BY created_at DESC LIMIT 10",
            $user_id
        ) );
        foreach ( (array) $credits as $r ) {
            $delta = (int) $r->credits;
            if ( $delta > 0 ) {
                $items[] = [
                    'icon'  => 'coin',
                    'title' => $delta . ' credits added',
                    'sub'   => $r->note ?: ucfirst( str_replace( '_', ' ', (string) $r->action ) ),
                    'time'  => $r->created_at,
                ];
            }
        }

        // Test completions
        if ( defined('CIAS_ATTEMPTS') ) {
            $tests = $wpdb->get_results( $wpdb->prepare(
                "SELECT percentage, submitted_at
                 FROM " . CIAS_ATTEMPTS . "
                 WHERE user_id = %d AND status = 'submitted'
                 ORDER BY submitted_at DESC LIMIT 5",
                $user_id
            ) );
            foreach ( (array) $tests as $r ) {
                $items[] = [
                    'icon'  => 'circle-check',
                    'title' => 'Test completed',
                    'sub'   => 'You scored ' . round( (float) $r->percentage ) . '%',
                    'time'  => $r->submitted_at,
                ];
            }
        }

        // Auto-generated questions ready (pending in-app notices)
        $notices = get_user_meta( $user_id, 'cias_gen_notices', true );
        if ( is_array( $notices ) ) {
            foreach ( $notices as $n ) {
                $items[] = [
                    'icon'  => 'bolt',
                    'title' => 'New questions ready',
                    'sub'   => $n['message'] ?? 'Fresh questions are available.',
                    'time'  => $n['created_at'] ?? current_time( 'mysql' ),
                ];
            }
        }

        // Sort newest-first, cap at 15
        usort( $items, function ( $a, $b ) {
            return strcmp( (string) $b['time'], (string) $a['time'] );
        } );
        return array_slice( $items, 0, 15 );
    }

    public static function get_student_batch_name( int $user_id ): string {
        global $wpdb;
        $name = $wpdb->get_var( $wpdb->prepare(
            "SELECT b.name FROM {$wpdb->prefix}cias_enrollments e
             JOIN {$wpdb->prefix}cias_batches b ON b.id = e.batch_id
             WHERE e.user_id = %d AND e.status = 'active'
             ORDER BY e.enrolled_at ASC LIMIT 1",
            $user_id
        ) );
        return $name ?: '';
    }

    public static function get_member_since( int $user_id ): string {
        global $wpdb;
        $date = $wpdb->get_var( $wpdb->prepare(
            "SELECT MIN(enrolled_at) FROM {$wpdb->prefix}cias_enrollments
             WHERE user_id = %d AND status = 'active'",
            $user_id
        ) );
        return $date ? date_i18n( 'M Y', strtotime( $date ) ) : '';
    }

    public static function get_streak( int $user_id ): array {
        global $wpdb;

        // Get all active days in last 60 days from analytics table
        $days = $wpdb->get_col( $wpdb->prepare(
            "SELECT stat_date FROM " . ( defined('CIAS_ANALYTICS_DAILY') ? CIAS_ANALYTICS_DAILY : $wpdb->prefix . 'cias_analytics_daily' ) . "
             WHERE user_id = %d AND (guru_messages > 0 OR tests_taken > 0 OR answers_submitted > 0)
               AND stat_date >= DATE_SUB(CURDATE(), INTERVAL 60 DAY)
             ORDER BY stat_date DESC",
            $user_id
        ) );

        // If no analytics data, compute from chat messages directly
        if ( empty( $days ) ) {
            $days = $wpdb->get_col( $wpdb->prepare(
                "SELECT DISTINCT DATE(created_at) AS d FROM {$wpdb->prefix}cias_chat_messages
                 WHERE user_id = %d AND created_at >= DATE_SUB(CURDATE(), INTERVAL 60 DAY)
                 ORDER BY d DESC",
                $user_id
            ) );
        }

        // Calculate current streak
        $streak      = 0;
        $check       = date('Y-m-d');
        $days_set    = array_flip( $days );

        for ( $i = 0; $i < 60; $i++ ) {
            if ( isset( $days_set[ $check ] ) ) {
                $streak++;
                $check = date( 'Y-m-d', strtotime( "$check -1 day" ) );
            } else {
                break;
            }
        }

        return [
            'current'    => (int) apply_filters( 'cias_vocab_streak', $streak, $user_id ),
            'active_days'=> $days,
        ];
    }

    // ── Vocabulary ────────────────────────────────────────────────────────────

    public static function get_due_words( int $user_id ): array {
        global $wpdb;

        $words = $wpdb->get_results( $wpdb->prepare(
            "SELECT w.id, w.word, w.meaning AS definition, w.part_of_speech,
                    COALESCE(p.ease_factor, 2.5) AS ease,
                    CASE WHEN COALESCE(p.ease_factor, 2.5) < 2.0 THEN 'hard'
                         WHEN COALESCE(p.ease_factor, 2.5) < 2.5 THEN 'review'
                         ELSE 'easy' END AS difficulty
             FROM {$wpdb->prefix}cias_vocabulary w
             LEFT JOIN {$wpdb->prefix}cias_user_progress p ON p.word_id = w.id AND p.user_id = %d
             WHERE (p.next_review IS NULL OR p.next_review <= NOW())
               AND (p.mastered IS NULL OR p.mastered = 0)
             ORDER BY p.next_review ASC, w.id ASC
             LIMIT 30",
            $user_id
        ), ARRAY_A );

        // Demo fallback if CIAS vocab tables don't exist
        $fallback = [];
        if ( empty( $words ) ) {
            $fallback = [
                ['id'=>1,'word'=>'Perfidious','definition'=>'Deceitful and untrustworthy; guilty of betrayal.','part_of_speech'=>'Adjective','difficulty'=>'hard'],
                ['id'=>2,'word'=>'Alacrity','definition'=>'Brisk and cheerful readiness; eager willingness.','part_of_speech'=>'Noun','difficulty'=>'review'],
                ['id'=>3,'word'=>'Sanguine','definition'=>'Optimistic or positive, especially in difficulty.','part_of_speech'=>'Adjective','difficulty'=>'easy'],
                ['id'=>4,'word'=>'Recalcitrant','definition'=>'Having an obstinate resistance to authority.','part_of_speech'=>'Adjective','difficulty'=>'hard'],
                ['id'=>5,'word'=>'Inveterate','definition'=>'Having a habit that is unlikely to change.','part_of_speech'=>'Adjective','difficulty'=>'review'],
                ['id'=>6,'word'=>'Equivocal','definition'=>'Open to more than one interpretation; ambiguous.','part_of_speech'=>'Adjective','difficulty'=>'easy'],
            ];
        }

        // Bridge filter: vocab plugin overrides this with real vocab_words data
        return apply_filters( 'cias_due_words', $words ?: $fallback, $user_id );
    }

    // ── Answer submissions ────────────────────────────────────────────────────

    public static function get_recent_submissions( int $user_id ): array {
        if ( ! defined('CIAS_SUBMISSIONS') ) return [];
        global $wpdb;
        return $wpdb->get_results( $wpdb->prepare(
            "SELECT s.id, s.status, s.r2_key, s.question_text, s.created_at,
                    ev.score, ev.max_score, ev.feedback_json
             FROM " . CIAS_SUBMISSIONS . " s
             LEFT JOIN " . CIAS_AI_EVALUATIONS . " ev ON ev.submission_id = s.id
             WHERE s.user_id = %d
             ORDER BY s.created_at DESC LIMIT 5",
            $user_id
        ), ARRAY_A ) ?: [];
    }

    // ── Subject accuracy (from topic performance table) ───────────────────────

    public static function get_subject_accuracy( int $user_id ): array {
        global $wpdb;
        $att = $wpdb->prefix . 'cias_attempts';
        $ans = $wpdb->prefix . 'cias_answers';
        $qs  = $wpdb->prefix . 'cias_questions';
        $sub = $wpdb->prefix . 'cias_subjects';

        // Real subject accuracy from actual MCQ practice/test answers.
        // accuracy = correct answers / total answered, per subject, for this student.
        $rows = $wpdb->get_results( $wpdb->prepare(
            "SELECT q.subject_id,
                    COALESCE(s.name,'Subject') AS subject,
                    ROUND( SUM(a.is_correct) / NULLIF(COUNT(a.id),0) * 100 ) AS accuracy,
                    COUNT(a.id) AS answered
             FROM {$ans} a
             JOIN {$att} t ON t.id = a.attempt_id AND t.user_id = %d AND t.status = 'submitted'
             JOIN {$qs}  q ON q.id = a.question_id
             LEFT JOIN {$sub} s ON s.id = q.subject_id
             WHERE q.subject_id > 0
             GROUP BY q.subject_id
             HAVING answered > 0
             ORDER BY accuracy DESC",
            $user_id
        ), ARRAY_A );

        return $rows ?: [];
    }

    // ── Writing scores ────────────────────────────────────────────────────────

    public static function get_writing_scores( int $user_id ): array {
        if ( ! defined('CIAS_AI_EVALUATIONS') ) return [];
        global $wpdb;
        return $wpdb->get_results( $wpdb->prepare(
            "SELECT ev.score, ev.max_score, ev.evaluated_at,
                    COALESCE(s.question_text,'General answer') AS question_text,
                    COALESCE(sub.name,'General') AS subject_name
             FROM " . CIAS_AI_EVALUATIONS . " ev
             JOIN " . CIAS_SUBMISSIONS . " s ON s.id = ev.submission_id
             LEFT JOIN {$wpdb->prefix}cias_subjects sub ON sub.id = s.subject_id
             WHERE ev.user_id = %d
             ORDER BY ev.evaluated_at DESC LIMIT 5",
            $user_id
        ), ARRAY_A ) ?: [];
    }

    // ── Plan / subscription ───────────────────────────────────────────────────

    public static function get_plan( int $user_id ): array {
        $access  = get_user_meta( $user_id, 'cias_access_type', true ) ?: 'free';
        $plans   = [
            'free'  => ['name'=>'Free',  'price'=>'₹0',   'monthly_credits'=>5],
            'paid'  => ['name'=>'Pro',   'price'=>'₹499', 'monthly_credits'=>50],
            'elite' => ['name'=>'Elite', 'price'=>'₹999', 'monthly_credits'=>200],
        ];
        return array_merge( $plans[ $access ] ?? $plans['free'], ['key' => $access] );
    }

    // ── Activity days for streak calendar ─────────────────────────────────────

    public static function get_activity_days( int $user_id ): array {
        global $wpdb;
        $days = $wpdb->get_col( $wpdb->prepare(
            "SELECT DISTINCT DATE(created_at) FROM {$wpdb->prefix}cias_chat_messages
             WHERE user_id = %d AND created_at >= DATE_SUB(CURDATE(), INTERVAL 31 DAY)",
            $user_id
        ) );
        // Bridge merges vocab last_reviewed days here
        return apply_filters( 'cias_activity_days', $days ?: [], $user_id );
    }

    // ── Due tests (pending, not yet submitted) ────────────────────────────────

    public static function get_due_tests( int $user_id ): array {
        if ( ! defined('CIAS_TESTS') || ! defined('CIAS_ATTEMPTS') ) return [];
        global $wpdb;

        $db        = new CIAS_DB();
        $batch_ids = $db->get_student_batch_ids( $user_id );
        if ( empty( $batch_ids ) ) return [];

        $in = implode( ',', array_map( 'intval', $batch_ids ) );

        $tests = $wpdb->get_results( $wpdb->prepare(
            "SELECT t.id, t.title, t.q_count, t.time_limit, t.subject_id,
                    COALESCE(s.name,'General') AS subject_name,
                    COALESCE(s.color,'#6C63FF') AS subject_color,
                    t.scheduled_date,
                    COALESCE(tp.avg_score, 0) AS subject_avg_score
             FROM " . CIAS_TESTS . " t
             JOIN " . CIAS_TEST_BATCH . " tb ON tb.test_id = t.id
             LEFT JOIN {$wpdb->prefix}cias_subjects s ON s.id = t.subject_id
             LEFT JOIN " . (defined('CIAS_TOPIC_PERF') ? CIAS_TOPIC_PERF : "({$wpdb->prefix}cias_topic_performance)") . " tp
               ON tp.user_id = %d AND tp.subject_id = t.subject_id
             WHERE tb.batch_id IN ({$in})
               AND t.status = 'published'
               AND (t.scheduled_date IS NULL OR t.scheduled_date <= NOW())
               AND NOT EXISTS (
                   SELECT 1 FROM " . CIAS_ATTEMPTS . " a
                   WHERE a.test_id = t.id AND a.user_id = %d AND a.status = 'submitted'
               )
             GROUP BY t.id
             ORDER BY t.scheduled_date DESC
             LIMIT 5",
            $user_id, $user_id
        ), ARRAY_A ) ?: [];

        // Tag each test as AI-recommended (weak subject) or assigned
        foreach ( $tests as &$test ) {
            $avg = (float) $test['subject_avg_score'];
            $test['tag']     = $avg > 0 && $avg < 60 ? 'weak_area' : 'assigned';
            $test['tag_label'] = $avg > 0 && $avg < 60 ? 'Weak area' : 'Assigned';
            $test['q_count'] = (int) $test['q_count'];
            $test['time_limit'] = (int) $test['time_limit'];
        }
        unset($test);

        return $tests;
    }

    // ── Due revisions (spaced repetition from topic performance) ──────────────

    public static function get_due_revisions( int $user_id ): array {
        global $wpdb;
        $revisions = [];

        // Primary source: cias_topic_stats (always available in main plugin)
        if ( defined('CIAS_TOPIC_STATS') ) {
            $revisions = $wpdb->get_results( $wpdb->prepare(
                "SELECT ts.subject_id, ts.topic_id,
                        COALESCE(s.name,'General') AS subject_name,
                        COALESCE(t.name,'General topic') AS topic_name,
                        ROUND(COALESCE((ts.correct_count / NULLIF(ts.total_count,0)) * 100, 0)) AS avg_score,
                        ts.last_seen AS last_attempted,
                        COALESCE(DATEDIFF(NOW(), ts.last_seen), 999) AS days_since
                 FROM " . CIAS_TOPIC_STATS . " ts
                 LEFT JOIN {$wpdb->prefix}cias_subjects s ON s.id = ts.subject_id
                 LEFT JOIN {$wpdb->prefix}cias_topics t ON t.id = ts.topic_id
                 WHERE ts.user_id = %d AND ts.total_count >= 2
                   AND (
                       (ts.correct_count / NULLIF(ts.total_count,0)) < 0.70
                       OR ts.last_seen < DATE_SUB(NOW(), INTERVAL 5 DAY)
                       OR ts.last_seen IS NULL
                   )
                 ORDER BY (ts.correct_count / NULLIF(ts.total_count,0)) ASC, ts.last_seen ASC
                 LIMIT 4",
                $user_id
            ), ARRAY_A ) ?: [];
        }

        // Secondary: cias_topic_performance (phase-b, writing-based)
        if ( empty( $revisions ) && defined('CIAS_TOPIC_PERF') ) {
            $revisions = $wpdb->get_results( $wpdb->prepare(
                "SELECT tp.subject_id, tp.topic_id,
                        COALESCE(s.name,'General') AS subject_name,
                        COALESCE(t.name,'General topic') AS topic_name,
                        ROUND(tp.avg_score) AS avg_score,
                        tp.last_attempted,
                        COALESCE(DATEDIFF(NOW(), tp.last_attempted), 999) AS days_since
                 FROM " . CIAS_TOPIC_PERF . " tp
                 LEFT JOIN {$wpdb->prefix}cias_subjects s ON s.id = tp.subject_id
                 LEFT JOIN {$wpdb->prefix}cias_topics t ON t.id = tp.topic_id
                 WHERE tp.user_id = %d
                   AND (tp.avg_score < 70 OR tp.last_attempted < DATE_SUB(NOW(), INTERVAL 5 DAY))
                 ORDER BY tp.avg_score ASC
                 LIMIT 4",
                $user_id
            ), ARRAY_A ) ?: [];
        }

        foreach ( $revisions as &$rev ) {
            $score = (int)($rev['avg_score'] ?? 0);
            $days  = (int)($rev['days_since'] ?? 0);
            $rev['tag'] = $score < 50 ? 'weak' : 'review';
            $rev['tag_label'] = $score < 50 ? 'Weak' : 'Review';
            $rev['reason'] = $score < 50
                ? "Only {$score}% accuracy — needs reinforcement"
                : ( $days > 0 ? "Spaced repetition · last revised {$days} day" . ($days !== 1 ? 's' : '') . " ago" : 'Due for revision' );
        }
        unset($rev);

        return $revisions;
    }

    // ── Today's AI study plan (cached from Claude, refreshed daily) ───────────

    public static function get_study_plan_today( int $user_id ): array {
        // Try cached plan first (set by caig_get_study_plan AJAX handler)
        $cached = get_transient( "cias_plan_{$user_id}" );
        if ( $cached && is_array( $cached ) ) return $cached;

        // Build a lightweight rule-based plan from real data (no API call on page load)
        global $wpdb;

        $subject_acc = self::get_subject_accuracy( $user_id );
        $due_words   = count( self::get_due_words( $user_id ) );
        $due_tests   = self::get_due_tests( $user_id );

        // Find weakest subject
        $weakest = null;
        $lowest  = 101;
        foreach ( $subject_acc as $sub ) {
            if ( (int)$sub['accuracy'] < $lowest ) {
                $lowest  = (int)$sub['accuracy'];
                $weakest = $sub['subject'];
            }
        }

        $tasks = [];

        // 1. Weakest subject MCQs
        if ( $weakest ) {
            $tasks[] = [
                'type'    => 'mcq',
                'icon'    => 'ti-help-circle',
                'icon_bg' => '#fff7ed',
                'icon_fg' => '#ea580c',
                'name'    => "{$weakest} MCQs",
                'why'     => "Weakest subject · {$lowest}% accuracy",
                'count'   => '20 Qs',
                'est_min' => 30,
            ];
        }

        // 2. Vocabulary
        if ( $due_words > 0 ) {
            $tasks[] = [
                'type'    => 'vocab',
                'icon'    => 'ti-book-2',
                'icon_bg' => '#f5f3ff',
                'icon_fg' => '#7c3aed',
                'name'    => 'Vocabulary session',
                'why'     => "{$due_words} word" . ($due_words !== 1 ? 's' : '') . " due for review",
                'count'   => "{$due_words} words",
                'est_min' => min( $due_words * 2, 20 ),
            ];
        }

        // 3. Pending test if any
        if ( ! empty( $due_tests ) ) {
            $t = $due_tests[0];
            $tasks[] = [
                'type'    => 'test',
                'icon'    => 'ti-clipboard-list',
                'icon_bg' => '#fff7ed',
                'icon_fg' => '#e8431a',
                'name'    => $t['title'] ?? 'Pending test',
                'why'     => $t['subject_name'] . ' · ' . ($t['q_count'] ?? 20) . ' Qs',
                'count'   => ($t['q_count'] ?? 20) . ' Qs',
                'est_min' => $t['time_limit'] ?: 40,
            ];
        }

        // 4. Answer writing
        $tasks[] = [
            'type'    => 'writing',
            'icon'    => 'ti-writing',
            'icon_bg' => '#f0fdf4',
            'icon_fg' => '#16a34a',
            'name'    => 'Answer writing',
            'why'     => 'Upload 1 handwritten answer for AI evaluation',
            'count'   => '1 answer',
            'est_min' => 20,
        ];

        $total_hrs = round( array_sum( array_column( $tasks, 'est_min' ) ) / 60, 1 );

        // Build motivational line from stats
        $streak    = self::get_streak( $user_id );
        $motivation = $streak['current'] > 0
            ? "You're on a {$streak['current']}-day streak — keep going! Focus on {$weakest} today and you'll see real improvement."
            : ( $weakest ? "Let's start fresh. Focus on {$weakest} first — that's where the most marks are hiding." : "Every question you attempt today compounds. Let's go!" );

        return [
            'tasks'      => $tasks,
            'total_hrs'  => $total_hrs,
            'motivation' => $motivation,
            'generated'  => 'rule_based',
        ];
    }

    // ── Leaderboard rank for the student ─────────────────────────────────────

    public static function get_leaderboard_rank( int $user_id ): array {
        if ( ! defined('CIAS_ATTEMPTS') ) return ['rank' => 0, 'total' => 0, 'percentile' => 0];
        global $wpdb;

        $db        = new CIAS_DB();
        $batch_ids = $db->get_student_batch_ids( $user_id );
        if ( empty( $batch_ids ) ) return ['rank' => 0, 'total' => 0, 'percentile' => 0];

        $in  = implode( ',', array_map( 'intval', $batch_ids ) );

        // Score all students in same batch by avg percentage
        $scores = $wpdb->get_results(
            "SELECT a.user_id, AVG(a.percentage) AS avg_pct
             FROM " . CIAS_ATTEMPTS . " a
             JOIN " . CIAS_TEST_BATCH . " tb ON tb.test_id = a.test_id
             WHERE tb.batch_id IN ({$in}) AND a.status = 'submitted'
             GROUP BY a.user_id
             ORDER BY avg_pct DESC"
        );

        $rank  = 0;
        $total = count( $scores );
        foreach ( $scores as $i => $row ) {
            if ( (int)$row->user_id === $user_id ) {
                $rank = $i + 1;
                break;
            }
        }

        $percentile = $total > 0 && $rank > 0 ? round( (($total - $rank) / $total) * 100 ) : 0;

        return [
            'rank'       => $rank ?: 0,
            'total'      => $total,
            'percentile' => $percentile,
        ];
    }


}
