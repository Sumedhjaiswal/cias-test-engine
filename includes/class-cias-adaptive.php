<?php
if (!defined('ABSPATH')) exit;

class CIAS_Adaptive {

    private static $difficulty_mix = [
        'beginner' => ['easy'=>60,'medium'=>30,'hard'=>10],
        'mid'      => ['easy'=>20,'medium'=>50,'hard'=>30],
        'strong'   => ['easy'=>10,'medium'=>30,'hard'=>60],
    ];

    /* ══════════════════════════════════
       GENERATE ADAPTIVE TEST
    ══════════════════════════════════ */

    /**
     * Generate a practice test for a student on a subject
     * @param int $user_id
     * @param int $subject_id
     * @param int $q_count  Number of questions (default 15)
     * @param int $topic_id  Optional: drill a specific topic
     * @param int $subtopic_id Optional: drill a specific subtopic
     */
    public static function generate($user_id, $subject_id, $q_count = 15, $topic_id = 0, $subtopic_id = 0) {
        global $wpdb;

        // Get student level for this scope
        $level = self::get_student_level($user_id, $subject_id, $topic_id, $subtopic_id);
        $mix   = self::$difficulty_mix[$level];

        // Get questions already answered in last 30 days to avoid repeats
        $recent_ids = self::get_recent_question_ids($user_id, 30);
        $exclude    = !empty($recent_ids) ? 'AND q.id NOT IN (' . implode(',', array_map('intval',$recent_ids)) . ')' : '';

        // Base WHERE clause
        $where  = "WHERE q.status='published' AND q.subject_id=" . intval($subject_id);
        if ($topic_id)    $where .= " AND q.topic_id="    . intval($topic_id);
        if ($subtopic_id) $where .= " AND q.subtopic_id=" . intval($subtopic_id);

        // ── Join-date visibility gate ──────────────────────────────────────
        // Subject-only practice (no explicit topic/subtopic drill) hides
        // subtopics taught BEFORE the student joined. A subtopic's "taught date"
        // is automatic: the creation date of its first published question.
        // Explicitly drilling a topic/subtopic is a manual override that
        // bypasses this gate so students can catch up on foundational material.
        if (!$topic_id && !$subtopic_id) {
            $enrolled_at = self::get_student_join_date($user_id, $subject_id);
            if ($enrolled_at) {
                $where .= $wpdb->prepare(
                    " AND q.subtopic_id IN (
                        SELECT fq.subtopic_id FROM (
                            SELECT subtopic_id, MIN(created_at) AS first_q
                            FROM " . CIAS_QUESTIONS . "
                            WHERE status='published' AND subject_id=%d AND subtopic_id>0
                            GROUP BY subtopic_id
                        ) fq
                        WHERE fq.first_q >= %s
                    )",
                    intval($subject_id), $enrolled_at
                );
            }
        }

        $questions = [];

        // Pull questions per difficulty according to mix
        foreach (['easy','medium','hard'] as $diff) {
            $pct   = $mix[$diff];
            $count = max(1, (int)round($q_count * $pct / 100));
            $rows  = $wpdb->get_results($wpdb->prepare(
                "SELECT q.id, q.question_text, q.option_a, q.option_b, q.option_c, q.option_d, q.difficulty
                 FROM ".CIAS_QUESTIONS." q $where AND q.difficulty=%s $exclude
                 ORDER BY RAND() LIMIT %d",
                $diff, $count
            ));
            foreach ($rows as $r) $questions[] = $r;
        }

        // If we didn't get enough questions (small bank), fill from any difficulty
        if (count($questions) < $q_count) {
            $got_ids = !empty($questions) ? 'AND q.id NOT IN ('.implode(',',array_column($questions,'id')).')' : '';
            $need    = $q_count - count($questions);
            $fill    = $wpdb->get_results(
                "SELECT q.id, q.question_text, q.option_a, q.option_b, q.option_c, q.option_d, q.difficulty
                 FROM ".CIAS_QUESTIONS." q $where $exclude $got_ids
                 ORDER BY RAND() LIMIT $need"
            );
            foreach ($fill as $r) $questions[] = $r;
        }

        shuffle($questions);

        // Store adaptive test record
        $q_ids = array_column($questions, 'id');
        $wpdb->insert(CIAS_ADAPTIVE, [
            'user_id'       => $user_id,
            'subject_id'    => $subject_id,
            'topic_id'      => $topic_id,
            'subtopic_id'   => $subtopic_id,
            'adaptive_type' => $topic_id ? 'drill' : 'practice',
            'question_ids'  => implode(',', $q_ids),
            'created_at'    => current_time('mysql'),
        ]);

        return [
            'questions'  => $questions,
            'level'      => $level,
            'mix'        => $mix,
            'adaptive_id'=> $wpdb->insert_id,
        ];
    }

    /* ══════════════════════════════════
       GENERATE REVISION TEST
    ══════════════════════════════════ */
    public static function generate_revision($user_id, $topic_stat_id, $q_count = 10) {
        global $wpdb;
        $stat = $wpdb->get_row($wpdb->prepare("SELECT * FROM ".CIAS_TOPIC_STATS." WHERE id=%d AND user_id=%d",$topic_stat_id,$user_id));
        if (!$stat) return null;

        return self::generate($user_id, $stat->subject_id, $q_count, $stat->topic_id, $stat->subtopic_id);
    }

    /* ══════════════════════════════════
       LEVEL CALCULATION
    ══════════════════════════════════ */
    /**
     * Earliest enrollment (join) date for a student. Used to gate which
     * subtopics are visible in subject-only adaptive practice.
     * Returns MySQL datetime string, or null if no enrollment found.
     */
    public static function get_student_join_date($user_id, $subject_id = 0) {
        global $wpdb;
        // Earliest active enrollment across the student's batches.
        $date = $wpdb->get_var($wpdb->prepare(
            "SELECT MIN(enrolled_at) FROM " . CIAS_ENROLLMENTS . "
             WHERE user_id=%d AND status='active'",
            intval($user_id)
        ));
        return $date ?: null;
    }

    public static function get_student_level($user_id, $subject_id, $topic_id = 0, $subtopic_id = 0) {
        global $wpdb;

        // Try most specific scope first, fall back to broader
        $scopes = [
            [$subject_id, $topic_id, $subtopic_id],
            [$subject_id, $topic_id, 0],
            [$subject_id, 0, 0],
        ];

        foreach ($scopes as [$s,$t,$st]) {
            if (!$s && !$t && !$st) continue;
            $row = $wpdb->get_row($wpdb->prepare(
                "SELECT level, weighted_accuracy FROM ".CIAS_TOPIC_STATS."
                 WHERE user_id=%d AND subject_id=%d AND topic_id=%d AND subtopic_id=%d",
                $user_id, $s, $t, $st
            ));
            if ($row) return $row->level;
        }

        return 'beginner'; // No history = start from beginner
    }

    /* ══════════════════════════════════
       RECENT QUESTIONS (avoid repeats)
    ══════════════════════════════════ */
    private static function get_recent_question_ids($user_id, $days = 30) {
        global $wpdb;
        $since = date('Y-m-d', strtotime("-{$days} days"));
        return $wpdb->get_col($wpdb->prepare(
            "SELECT DISTINCT a.question_id FROM ".CIAS_ANSWERS." a
             JOIN ".CIAS_ATTEMPTS." att ON a.attempt_id=att.id
             WHERE att.user_id=%d AND att.submitted_at >= %s",
            $user_id, $since
        ));
    }

    /* ══════════════════════════════════
       STATS FOR ADMIN HEATMAP
    ══════════════════════════════════ */
    public static function get_class_weak_topics($subject_id = 0) {
        global $wpdb;
        $where = $subject_id ? "WHERE ts.subject_id=".intval($subject_id) : '';
        return $wpdb->get_results(
            "SELECT ts.subject_id, ts.topic_id, ts.subtopic_id,
                s.name AS subject_name, t.name AS topic_name, st.name AS subtopic_name,
                COUNT(DISTINCT ts.user_id) AS student_count,
                AVG(ts.weighted_accuracy) AS avg_accuracy,
                SUM(CASE WHEN ts.level='beginner' THEN 1 ELSE 0 END) AS beginner_count,
                SUM(CASE WHEN ts.level='mid' THEN 1 ELSE 0 END) AS mid_count,
                SUM(CASE WHEN ts.level='strong' THEN 1 ELSE 0 END) AS strong_count
             FROM ".CIAS_TOPIC_STATS." ts
             LEFT JOIN ".CIAS_SUBJECTS." s ON ts.subject_id=s.id
             LEFT JOIN ".CIAS_TOPICS." t ON ts.topic_id=t.id
             LEFT JOIN ".CIAS_SUBTOPICS." st ON ts.subtopic_id=st.id
             $where
             GROUP BY ts.subject_id, ts.topic_id, ts.subtopic_id
             ORDER BY avg_accuracy ASC LIMIT 20"
        );
    }

    /* ══════════════════════════════════
       CRON: MARK DUE REVISIONS
    ══════════════════════════════════ */
    public static function run_revision_check() {
        global $wpdb;
        $today    = current_time('Y-m-d');
        $due_rows = $wpdb->get_results($wpdb->prepare(
            "SELECT DISTINCT user_id FROM ".CIAS_TOPIC_STATS." WHERE next_revision <= %s", $today
        ));
        // Stats are already stored — the portal reads them on load
        // This hook exists so server cron can trigger cache clearing or notifications in future
        do_action('cias_revisions_checked', count($due_rows));
        return count($due_rows);
    }
}
