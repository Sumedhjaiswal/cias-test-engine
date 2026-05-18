<?php
if (!defined('ABSPATH')) exit;

class CIAS_DB {

    /* ══════════════════════════════════
       TABLE CREATION
    ══════════════════════════════════ */
    public static function setup_roles_and_caps() {
        $admin = get_role('administrator');
        if ($admin) {
            foreach (['cias_add_questions','cias_create_tests','cias_view_reports',
                      'cias_manage_teachers','cias_enter_offline','cias_release_offline',
                      'cias_use_content_manager'] as $cap)
                $admin->add_cap($cap);
        }

        if (!get_role('cias_teacher')) {
            add_role('cias_teacher', 'CIAS Teacher', [
                'read'               => true,
                'cias_add_questions' => true,
                'cias_create_tests'  => true,
                'cias_view_reports'  => true,
            ]);
        } else {
            $teacher = get_role('cias_teacher');
            foreach (['cias_add_questions','cias_create_tests','cias_view_reports'] as $cap)
                $teacher->add_cap($cap);
        }

        if (!get_role('cias_content_manager')) {
            add_role('cias_content_manager', 'CIAS Content Manager', [
                'read'                    => true,
                'cias_add_questions'      => true,
                'cias_enter_offline'      => true,
                'cias_release_offline'    => true,
                'cias_use_content_manager'=> true,
            ]);
        } else {
            $cm = get_role('cias_content_manager');
            $cm->add_cap('cias_use_content_manager');
        }
    }

    public static function create_tables() {
        global $wpdb;
        $c = $wpdb->get_charset_collate();
        require_once ABSPATH . 'wp-admin/includes/upgrade.php';

        dbDelta("CREATE TABLE IF NOT EXISTS ".CIAS_COURSES." (
            id INT AUTO_INCREMENT PRIMARY KEY,
            name VARCHAR(200) NOT NULL,
            description TEXT,
            status VARCHAR(20) DEFAULT 'active',
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP
        ) $c;");

        dbDelta("CREATE TABLE IF NOT EXISTS ".CIAS_BATCHES." (
            id INT AUTO_INCREMENT PRIMARY KEY,
            course_id INT NOT NULL,
            name VARCHAR(200) NOT NULL,
            description TEXT,
            start_date DATE,
            end_date DATE,
            status VARCHAR(20) DEFAULT 'active',
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP
        ) $c;");

        dbDelta("CREATE TABLE IF NOT EXISTS ".CIAS_SUBJECTS." (
            id INT AUTO_INCREMENT PRIMARY KEY,
            name VARCHAR(200) NOT NULL,
            description TEXT,
            color VARCHAR(20) DEFAULT '#6C63FF',
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP
        ) $c;");

        dbDelta("CREATE TABLE IF NOT EXISTS ".CIAS_TOPICS." (
            id INT AUTO_INCREMENT PRIMARY KEY,
            subject_id INT NOT NULL,
            name VARCHAR(200) NOT NULL,
            description TEXT,
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            KEY subject_id (subject_id)
        ) $c;");

        dbDelta("CREATE TABLE IF NOT EXISTS ".CIAS_SUBTOPICS." (
            id INT AUTO_INCREMENT PRIMARY KEY,
            topic_id INT NOT NULL,
            name VARCHAR(200) NOT NULL,
            description TEXT,
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            KEY topic_id (topic_id)
        ) $c;");

        dbDelta("CREATE TABLE IF NOT EXISTS ".CIAS_ENROLLMENTS." (
            id INT AUTO_INCREMENT PRIMARY KEY,
            user_id BIGINT UNSIGNED NOT NULL,
            batch_id INT NOT NULL,
            enrolled_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            status VARCHAR(20) DEFAULT 'active',
            UNIQUE KEY user_batch (user_id, batch_id)
        ) $c;");

        dbDelta("CREATE TABLE IF NOT EXISTS ".CIAS_QUESTIONS." (
            id INT AUTO_INCREMENT PRIMARY KEY,
            subject_id INT NOT NULL,
            topic_id INT DEFAULT 0,
            subtopic_id INT DEFAULT 0,
            question_text TEXT NOT NULL,
            option_a TEXT NOT NULL,
            option_b TEXT NOT NULL,
            option_c TEXT NOT NULL,
            option_d TEXT NOT NULL,
            correct_option VARCHAR(1) NOT NULL,
            explanation TEXT,
            difficulty VARCHAR(10) DEFAULT 'medium',
            source VARCHAR(20) DEFAULT 'manual',
            created_by BIGINT UNSIGNED,
            status VARCHAR(20) DEFAULT 'published',
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            KEY subject_id (subject_id),
            KEY topic_id (topic_id),
            KEY subtopic_id (subtopic_id),
            KEY difficulty (difficulty)
        ) $c;");

        // ── CRITICAL: ALTER TABLE to add columns dbDelta may have missed ──
        // dbDelta silently fails to add columns to existing tables in some MySQL versions.
        // We use explicit ALTER TABLE with SHOW COLUMNS check instead.
        $questions_table = $wpdb->prefix . 'cias_questions';
        $existing_cols = $wpdb->get_col("SHOW COLUMNS FROM `{$questions_table}`");
        if (!in_array('topic_id', $existing_cols)) {
            $wpdb->query("ALTER TABLE $questions_table ADD COLUMN topic_id INT DEFAULT 0 AFTER subject_id");
        }
        if (!in_array('subtopic_id', $existing_cols)) {
            $wpdb->query("ALTER TABLE $questions_table ADD COLUMN subtopic_id INT DEFAULT 0 AFTER topic_id");
        }
        // Phase 1A: UPSC question format columns
        if (!in_array('question_type', $existing_cols)) {
            $wpdb->query("ALTER TABLE $questions_table ADD COLUMN question_type VARCHAR(20) NOT NULL DEFAULT 'standard' AFTER subtopic_id");
        }
        if (!in_array('statements', $existing_cols)) {
            $wpdb->query("ALTER TABLE $questions_table ADD COLUMN statements LONGTEXT NULL AFTER question_type");
        }
        if (!in_array('question_tags', $existing_cols)) {
            $wpdb->query("ALTER TABLE $questions_table ADD COLUMN question_tags VARCHAR(500) NOT NULL DEFAULT '' AFTER statements");
        }
        if (!in_array('year_asked', $existing_cols)) {
            $wpdb->query("ALTER TABLE $questions_table ADD COLUMN year_asked SMALLINT NULL AFTER question_tags");
        }

        // New test columns
        $tests_table = $wpdb->prefix . 'cias_tests';
        $test_cols   = $wpdb->get_col("SHOW COLUMNS FROM `{$tests_table}`");
        if (!in_array('end_date', $test_cols)) {
            $wpdb->query("ALTER TABLE $tests_table ADD COLUMN end_date DATETIME NULL AFTER scheduled_date");
        }
        if (!in_array('test_mode', $test_cols)) {
            $wpdb->query("ALTER TABLE $tests_table ADD COLUMN test_mode VARCHAR(20) NOT NULL DEFAULT 'online' AFTER status");
        }
        if (!in_array('teacher_id', $test_cols)) {
            $wpdb->query("ALTER TABLE $tests_table ADD COLUMN teacher_id BIGINT UNSIGNED DEFAULT 0 AFTER test_mode");
        }
        if (!in_array('access_pin', $test_cols)) {
            $wpdb->query("ALTER TABLE $tests_table ADD COLUMN access_pin VARCHAR(10) DEFAULT '' AFTER teacher_id");
        }
        if (!in_array('pin_expires_at', $test_cols)) {
            $wpdb->query("ALTER TABLE $tests_table ADD COLUMN pin_expires_at DATETIME NULL AFTER access_pin");
        }

        // WhatsApp log table
        dbDelta("CREATE TABLE IF NOT EXISTS ".CIAS_WA_LOG." (
            id INT AUTO_INCREMENT PRIMARY KEY,
            user_id BIGINT UNSIGNED NOT NULL,
            parent_phone VARCHAR(20) NOT NULL,
            message_type VARCHAR(20) DEFAULT 'daily',
            status VARCHAR(20) DEFAULT 'pending',
            brevo_message_id VARCHAR(100) DEFAULT '',
            error_message TEXT,
            sent_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            KEY user_id (user_id),
            KEY sent_at (sent_at)
        ) $c;");

        dbDelta("CREATE TABLE IF NOT EXISTS ".CIAS_TESTS." (
            id INT AUTO_INCREMENT PRIMARY KEY,
            title VARCHAR(300) NOT NULL,
            subject_id INT,
            description TEXT,
            time_limit INT DEFAULT 0,
            scheduled_date DATETIME,
            end_date DATETIME,
            status VARCHAR(20) DEFAULT 'draft',
            test_mode VARCHAR(20) DEFAULT 'online',
            teacher_id BIGINT UNSIGNED DEFAULT 0,
            access_pin VARCHAR(10) DEFAULT '',
            pin_expires_at DATETIME,
            created_by BIGINT UNSIGNED,
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP
        ) $c;");

        // Active sessions table for attendance control
        dbDelta("CREATE TABLE IF NOT EXISTS {$wpdb->prefix}cias_active_sessions (
            id INT AUTO_INCREMENT PRIMARY KEY,
            test_id INT NOT NULL,
            user_id BIGINT UNSIGNED NOT NULL,
            session_token VARCHAR(64),
            pin_verified TINYINT DEFAULT 0,
            kicked TINYINT DEFAULT 0,
            started_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            last_seen DATETIME DEFAULT CURRENT_TIMESTAMP,
            KEY test_id (test_id),
            UNIQUE KEY test_user (test_id, user_id)
        ) $c;");

        dbDelta("CREATE TABLE IF NOT EXISTS ".CIAS_TEST_BATCH." (
            id INT AUTO_INCREMENT PRIMARY KEY,
            test_id INT NOT NULL,
            batch_id INT NOT NULL,
            UNIQUE KEY test_batch (test_id, batch_id)
        ) $c;");

        dbDelta("CREATE TABLE IF NOT EXISTS ".CIAS_TEST_Q." (
            id INT AUTO_INCREMENT PRIMARY KEY,
            test_id INT NOT NULL,
            question_id INT NOT NULL,
            position INT DEFAULT 0,
            UNIQUE KEY test_q (test_id, question_id)
        ) $c;");

        dbDelta("CREATE TABLE IF NOT EXISTS ".CIAS_ATTEMPTS." (
            id INT AUTO_INCREMENT PRIMARY KEY,
            test_id INT NOT NULL,
            user_id BIGINT UNSIGNED NOT NULL,
            test_type VARCHAR(20) DEFAULT 'assigned',
            started_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            submitted_at DATETIME,
            score INT DEFAULT 0,
            total INT DEFAULT 0,
            percentage FLOAT DEFAULT 0,
            time_taken INT DEFAULT 0,
            status VARCHAR(20) DEFAULT 'in_progress',
            KEY user_test (user_id, test_id),
            KEY test_id (test_id)
        ) $c;");

        dbDelta("CREATE TABLE IF NOT EXISTS ".CIAS_ANSWERS." (
            id INT AUTO_INCREMENT PRIMARY KEY,
            attempt_id INT NOT NULL,
            question_id INT NOT NULL,
            selected_option VARCHAR(1),
            is_correct TINYINT DEFAULT 0,
            answered_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            UNIQUE KEY attempt_q (attempt_id, question_id)
        ) $c;");

        dbDelta("CREATE TABLE IF NOT EXISTS ".CIAS_TOPIC_STATS." (
            id INT AUTO_INCREMENT PRIMARY KEY,
            user_id BIGINT UNSIGNED NOT NULL,
            subject_id INT NOT NULL,
            topic_id INT DEFAULT 0,
            subtopic_id INT DEFAULT 0,
            attempts INT DEFAULT 0,
            total_questions INT DEFAULT 0,
            correct_questions INT DEFAULT 0,
            weighted_accuracy FLOAT DEFAULT 0,
            level VARCHAR(20) DEFAULT 'beginner',
            next_revision DATE,
            last_attempt DATETIME,
            UNIQUE KEY user_subtopic (user_id, subject_id, topic_id, subtopic_id)
        ) $c;");

        dbDelta("CREATE TABLE IF NOT EXISTS ".CIAS_ADAPTIVE." (
            id INT AUTO_INCREMENT PRIMARY KEY,
            user_id BIGINT UNSIGNED NOT NULL,
            subject_id INT NOT NULL,
            topic_id INT DEFAULT 0,
            subtopic_id INT DEFAULT 0,
            adaptive_type VARCHAR(20) DEFAULT 'practice',
            question_ids TEXT,
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            KEY user_id (user_id)
        ) $c;");

        dbDelta("CREATE TABLE IF NOT EXISTS ".CIAS_TEACHER_BATCHES." (
            id INT AUTO_INCREMENT PRIMARY KEY,
            teacher_id BIGINT UNSIGNED NOT NULL,
            batch_id INT NOT NULL,
            assigned_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            UNIQUE KEY teacher_batch (teacher_id, batch_id)
        ) $c;");

        dbDelta("CREATE TABLE IF NOT EXISTS ".CIAS_OFFLINE_TESTS." (
            id INT AUTO_INCREMENT PRIMARY KEY,
            title VARCHAR(300) NOT NULL,
            batch_id INT NOT NULL,
            subject_id INT DEFAULT 0,
            test_type VARCHAR(50) DEFAULT 'surprise',
            date_conducted DATE,
            max_marks INT DEFAULT 100,
            status VARCHAR(20) DEFAULT 'draft',
            created_by BIGINT UNSIGNED,
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            KEY batch_id (batch_id)
        ) $c;");

        dbDelta("CREATE TABLE IF NOT EXISTS ".CIAS_OFFLINE_RESULTS." (
            id INT AUTO_INCREMENT PRIMARY KEY,
            offline_test_id INT NOT NULL,
            user_id BIGINT UNSIGNED NOT NULL,
            marks_obtained FLOAT DEFAULT 0,
            is_absent TINYINT DEFAULT 0,
            percentage FLOAT DEFAULT 0,
            grade VARCHAR(50) DEFAULT '',
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            UNIQUE KEY test_user (offline_test_id, user_id),
            KEY offline_test_id (offline_test_id)
        ) $c;");

        // ── AI Bot tables ──
        dbDelta("CREATE TABLE IF NOT EXISTS {$wpdb->prefix}cias_ai_credits (
            id INT AUTO_INCREMENT PRIMARY KEY,
            user_id BIGINT UNSIGNED NOT NULL,
            access_type VARCHAR(20) DEFAULT 'free',
            credits_remaining INT DEFAULT 0,
            is_revoked TINYINT DEFAULT 0,
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            UNIQUE KEY user_id (user_id)
        ) $c;");

        dbDelta("CREATE TABLE IF NOT EXISTS {$wpdb->prefix}cias_ai_usage_log (
            id INT AUTO_INCREMENT PRIMARY KEY,
            user_id BIGINT UNSIGNED NOT NULL,
            model VARCHAR(100) DEFAULT '',
            context VARCHAR(50) DEFAULT 'bot',
            input_tokens INT DEFAULT 0,
            output_tokens INT DEFAULT 0,
            cost_usd DECIMAL(10,6) DEFAULT 0,
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            KEY user_id (user_id),
            KEY context (context),
            KEY created_at (created_at)
        ) $c;");

        dbDelta("CREATE TABLE IF NOT EXISTS {$wpdb->prefix}cias_ai_credit_log (
            id INT AUTO_INCREMENT PRIMARY KEY,
            user_id BIGINT UNSIGNED NOT NULL,
            credits INT DEFAULT 0,
            action VARCHAR(30) DEFAULT 'purchase',
            order_id VARCHAR(100) DEFAULT '',
            note VARCHAR(200) DEFAULT '',
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            KEY user_id (user_id)
        ) $c;");

        dbDelta("CREATE TABLE IF NOT EXISTS {$wpdb->prefix}cias_ai_generations (
            id INT AUTO_INCREMENT PRIMARY KEY,
            created_by BIGINT UNSIGNED NOT NULL,
            source_text_hash VARCHAR(64) DEFAULT '',
            source_filename VARCHAR(200) DEFAULT '',
            config_json TEXT,
            status VARCHAR(20) DEFAULT 'pending',
            questions_json LONGTEXT,
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            KEY created_by (created_by),
            KEY status (status)
        ) $c;");

        // ── AI Guru tables ──
        dbDelta("CREATE TABLE IF NOT EXISTS {$wpdb->prefix}caig_study_plans (
            id INT AUTO_INCREMENT PRIMARY KEY,
            user_id BIGINT UNSIGNED NOT NULL,
            plan_date DATE NOT NULL,
            plan_json LONGTEXT NOT NULL,
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            UNIQUE KEY user_date (user_id, plan_date)
        ) $c;");

        dbDelta("CREATE TABLE IF NOT EXISTS {$wpdb->prefix}caig_lectures (
            id INT AUTO_INCREMENT PRIMARY KEY,
            subject_id INT NOT NULL,
            topic_id INT DEFAULT 0,
            lecture_number INT NOT NULL,
            title VARCHAR(300) NOT NULL,
            description TEXT,
            url VARCHAR(500) DEFAULT '',
            thumbnail VARCHAR(500) DEFAULT '',
            duration_min INT DEFAULT 0,
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            KEY subject_topic (subject_id, topic_id)
        ) $c;");

        dbDelta("CREATE TABLE IF NOT EXISTS {$wpdb->prefix}caig_rank_predictions (
            id INT AUTO_INCREMENT PRIMARY KEY,
            user_id BIGINT UNSIGNED NOT NULL,
            predicted_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            prelims_low INT DEFAULT 0,
            prelims_high INT DEFAULT 0,
            mains_estimate INT DEFAULT 0,
            confidence INT DEFAULT 0,
            analysis_json LONGTEXT,
            UNIQUE KEY user_id (user_id)
        ) $c;");

        // Ensure active sessions UNIQUE KEY exists (ALTER safe)
        $wpdb->query("ALTER IGNORE TABLE {$wpdb->prefix}cias_active_sessions
            ADD UNIQUE IF NOT EXISTS `test_user` (test_id, user_id)");

        // ── Phase A: extend cias_ai_credit_log with richer columns ─────────────
        $credit_log_cols = $wpdb->get_col("SHOW COLUMNS FROM `{$wpdb->prefix}cias_ai_credit_log`");
        if (!in_array('balance_after', $credit_log_cols))
            $wpdb->query("ALTER TABLE `{$wpdb->prefix}cias_ai_credit_log` ADD COLUMN balance_after INT DEFAULT NULL AFTER credits");
        if (!in_array('admin_user_id', $credit_log_cols))
            $wpdb->query("ALTER TABLE `{$wpdb->prefix}cias_ai_credit_log` ADD COLUMN admin_user_id BIGINT UNSIGNED DEFAULT NULL");
        // Ensure 'note' column exists (it should, but safety check)
        if (!in_array('note', $credit_log_cols))
            $wpdb->query("ALTER TABLE `{$wpdb->prefix}cias_ai_credit_log` ADD COLUMN note VARCHAR(500) DEFAULT ''");
        // Add indexes if missing
        $wpdb->query("ALTER IGNORE TABLE `{$wpdb->prefix}cias_ai_credit_log` ADD INDEX IF NOT EXISTS `idx_action` (action)");

        // ── Phase A: chat messages table ────────────────────────────────────────
        dbDelta("CREATE TABLE IF NOT EXISTS {$wpdb->prefix}cias_chat_messages (
            id              BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
            session_id      VARCHAR(64)         NOT NULL,
            user_id         BIGINT(20) UNSIGNED NOT NULL,
            role            ENUM('user','assistant') NOT NULL,
            body            LONGTEXT            NOT NULL,
            message_type    VARCHAR(64)         DEFAULT NULL,
            media_id        BIGINT(20) UNSIGNED DEFAULT NULL,
            media_url       TEXT                DEFAULT NULL,
            tokens_used     INT(11)             DEFAULT NULL,
            credits_charged SMALLINT(5) UNSIGNED DEFAULT NULL,
            created_at      DATETIME            NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            KEY idx_session    (session_id),
            KEY idx_user_id    (user_id),
            KEY idx_created_at (created_at),
            KEY idx_msg_type   (message_type)
        ) $c;");
    }

    public static function seed_defaults() {
        global $wpdb;
        $existing = $wpdb->get_var("SELECT COUNT(*) FROM ".CIAS_SUBJECTS);
        if ($existing > 0) return;
        $subjects = [
            ['Current Affairs', 'Daily news and events', '#6C63FF'],
            ['History',         'Ancient to Modern India', '#E85D04'],
            ['Polity',          'Indian Constitution and Governance', '#1D9E75'],
            ['Economy',         'Indian Economy and Finance', '#3B8BD4'],
            ['Geography',       'Physical and Human Geography', '#639922'],
            ['Environment',     'Ecology and Environment', '#22c55e'],
            ['Science & Tech',  'Science, Technology, Space', '#D85A30'],
            ['Ethics',          'Ethics, Integrity and Aptitude', '#BA7517'],
        ];
        foreach ($subjects as [$name, $desc, $color]) {
            $wpdb->insert(CIAS_SUBJECTS, ['name'=>$name,'description'=>$desc,'color'=>$color]);
        }
    }

    /* ══════════════════════════════════
       GENERIC CRUD
    ══════════════════════════════════ */
    private function table($type) {
        $map = [
            'courses'     => CIAS_COURSES,
            'batches'     => CIAS_BATCHES,
            'subjects'    => CIAS_SUBJECTS,
            'topics'      => CIAS_TOPICS,
            'subtopics'   => CIAS_SUBTOPICS,
            'enrollments' => CIAS_ENROLLMENTS,
            'questions'   => CIAS_QUESTIONS,
            'tests'       => CIAS_TESTS,
        ];
        return $map[$type] ?? null;
    }

    public function insert($type, $data) {
        global $wpdb;
        $wpdb->insert($this->table($type), $data);
        $id = $wpdb->insert_id;
        $this->bust_cache();
        return $id;
    }

    public function update($type, $data, $id) {
        global $wpdb;
        $result = $wpdb->update($this->table($type), $data, ['id'=>$id]);
        $this->bust_cache();
        return $result;
    }

    public function delete($type, $id) {
        global $wpdb;
        $result = $wpdb->delete($this->table($type), ['id'=>$id]);
        $this->bust_cache();
        return $result;
    }

    /* Bust ALL cache layers: WordPress object cache, Redis, Varnish, Breeze */
    private function bust_cache() {
        global $wpdb;

        // 1. Flush WordPress object cache (in-memory)
        wp_cache_flush();

        // 2. Reset wpdb internal cache
        $wpdb->flush();

        // 3. Object Cache Pro Redis flush (if active)
        if (function_exists('wp_cache_flush_runtime')) {
            wp_cache_flush_runtime();
        }
        if (class_exists('ObjectCachePro\Caches\Cache')) {
            try { wp_cache_flush(); } catch(\Throwable $e) {}
        }

        // 4. Breeze/Varnish purge via HTTP headers
        if (function_exists('breeze_cache_flush')) {
            breeze_cache_flush();
        }

        // 5. Force no-cache headers on current response
        nocache_headers();
    }

    /* Bypass all caches for a direct DB read */
    private function db_get($sql) {
        global $wpdb;
        // Suspend WordPress object cache additions temporarily
        wp_suspend_cache_addition(true);
        $wpdb->flush(); // reset wpdb internal last_result
        $result = $wpdb->get_results($sql);
        wp_suspend_cache_addition(false);
        return $result;
    }

    public function get_by_id($type, $id) {
        global $wpdb;
        return $wpdb->get_row($wpdb->prepare("SELECT * FROM ".$this->table($type)." WHERE id=%d", $id));
    }

    public function get_all($type, $where = null) {
        $sql = "SELECT * FROM " . $this->table($type);
        if ($where) $sql .= " WHERE $where";
        $sql .= " ORDER BY id ASC";
        return $this->db_get($sql);
    }

    /* ══════════════════════════════════
       TOPICS & SUBTOPICS
    ══════════════════════════════════ */
    public function get_topics_with_subject() {
        return $this->db_get(
            "SELECT t.*, s.name AS subject_name,
                (SELECT COUNT(*) FROM ".CIAS_SUBTOPICS." WHERE topic_id=t.id) AS subtopic_count,
                (SELECT COUNT(*) FROM ".CIAS_QUESTIONS." WHERE topic_id=t.id AND status='published') AS question_count
             FROM ".CIAS_TOPICS." t LEFT JOIN ".CIAS_SUBJECTS." s ON t.subject_id=s.id ORDER BY s.name,t.name"
        );
    }

    public function get_subtopics_with_topic() {
        return $this->db_get(
            "SELECT st.*, t.name AS topic_name, s.name AS subject_name, t.subject_id,
                (SELECT COUNT(*) FROM ".CIAS_QUESTIONS." WHERE subtopic_id=st.id AND status='published') AS question_count
             FROM ".CIAS_SUBTOPICS." st
             LEFT JOIN ".CIAS_TOPICS." t ON st.topic_id=t.id
             LEFT JOIN ".CIAS_SUBJECTS." s ON t.subject_id=s.id
             ORDER BY s.name, t.name, st.name"
        );
    }

    public function get_subtopics_by_topic($topic_id) {
        global $wpdb;
        return $this->db_get($wpdb->prepare(
            "SELECT * FROM ".CIAS_SUBTOPICS." WHERE topic_id=%d ORDER BY name ASC", $topic_id
        ));
    }

    /* ══════════════════════════════════
       TOPIC PERFORMANCE STATS
    ══════════════════════════════════ */
    public function update_topic_stats($user_id, $attempt_id) {
        global $wpdb;
        $attempt = $wpdb->get_row($wpdb->prepare("SELECT * FROM ".CIAS_ATTEMPTS." WHERE id=%d",$attempt_id));
        if (!$attempt) return;

        $answers = $wpdb->get_results($wpdb->prepare(
            "SELECT a.*, q.subject_id, q.topic_id, q.subtopic_id, q.difficulty
             FROM ".CIAS_ANSWERS." a JOIN ".CIAS_QUESTIONS." q ON a.question_id=q.id
             WHERE a.attempt_id=%d", $attempt_id
        ));

        $weights = ['easy'=>1.0,'medium'=>1.5,'hard'=>2.0];
        $groups  = [];

        foreach ($answers as $ans) {
            $key = $ans->subject_id.'_'.$ans->topic_id.'_'.$ans->subtopic_id;
            if (!isset($groups[$key])) {
                $groups[$key] = ['subject_id'=>$ans->subject_id,'topic_id'=>$ans->topic_id,'subtopic_id'=>$ans->subtopic_id,'wc'=>0,'wt'=>0,'correct'=>0,'total'=>0];
            }
            $w = $weights[$ans->difficulty] ?? 1.0;
            $groups[$key]['wt']      += $w;
            $groups[$key]['wc']      += $ans->is_correct ? $w : 0;
            $groups[$key]['total']++;
            $groups[$key]['correct'] += $ans->is_correct;
        }

        foreach ($groups as $g) {
            $weighted_acc = $g['wt'] > 0 ? round(($g['wc']/$g['wt'])*100, 1) : 0;
            $level = $weighted_acc < 40 ? 'beginner' : ($weighted_acc < 70 ? 'mid' : 'strong');
            $rev_days = $weighted_acc < 40 ? 3 : ($weighted_acc < 70 ? 7 : 14);

            if ($weighted_acc >= 90) {
                $next_revision = date('Y-m-d', strtotime('+30 days'));
            } else {
                $next_revision = date('Y-m-d', strtotime("+{$rev_days} days"));
            }

            $existing = $wpdb->get_row($wpdb->prepare(
                "SELECT * FROM ".CIAS_TOPIC_STATS." WHERE user_id=%d AND subject_id=%d AND topic_id=%d AND subtopic_id=%d",
                $user_id, $g['subject_id'], $g['topic_id'], $g['subtopic_id']
            ));

            if ($existing) {
                $new_attempts = $existing->attempts + 1;
                $new_total    = $existing->total_questions + $g['total'];
                $new_correct  = $existing->correct_questions + $g['correct'];
                $rolling_acc  = round(($new_correct / $new_total) * 100, 1);
                $new_level    = $rolling_acc < 40 ? 'beginner' : ($rolling_acc < 70 ? 'mid' : 'strong');
                $new_rev_days = $rolling_acc < 40 ? 3 : ($rolling_acc < 70 ? 7 : 14);
                $new_revision = $rolling_acc >= 90 ? date('Y-m-d',strtotime('+30 days')) : date('Y-m-d',strtotime("+{$new_rev_days} days"));

                $wpdb->update(CIAS_TOPIC_STATS, [
                    'attempts'          => $new_attempts,
                    'total_questions'   => $new_total,
                    'correct_questions' => $new_correct,
                    'weighted_accuracy' => $rolling_acc,
                    'level'             => $new_level,
                    'next_revision'     => $new_revision,
                    'last_attempt'      => current_time('mysql'),
                ], ['user_id'=>$user_id,'subject_id'=>$g['subject_id'],'topic_id'=>$g['topic_id'],'subtopic_id'=>$g['subtopic_id']]);
            } else {
                $wpdb->insert(CIAS_TOPIC_STATS, [
                    'user_id'           => $user_id,
                    'subject_id'        => $g['subject_id'],
                    'topic_id'          => $g['topic_id'],
                    'subtopic_id'       => $g['subtopic_id'],
                    'attempts'          => 1,
                    'total_questions'   => $g['total'],
                    'correct_questions' => $g['correct'],
                    'weighted_accuracy' => $weighted_acc,
                    'level'             => $level,
                    'next_revision'     => $next_revision,
                    'last_attempt'      => current_time('mysql'),
                ]);
            }
        }
    }

    public function get_student_topic_stats($user_id, $subject_id = 0) {
        global $wpdb;
        $sql = "SELECT ts.*, s.name AS subject_name, s.color AS subject_color,
                    t.name AS topic_name, st.name AS subtopic_name
                FROM ".CIAS_TOPIC_STATS." ts
                LEFT JOIN ".CIAS_SUBJECTS." s ON ts.subject_id=s.id
                LEFT JOIN ".CIAS_TOPICS." t ON ts.topic_id=t.id
                LEFT JOIN ".CIAS_SUBTOPICS." st ON ts.subtopic_id=st.id
                WHERE ts.user_id=%d";
        $args = [$user_id];
        if ($subject_id) { $sql .= " AND ts.subject_id=%d"; $args[] = $subject_id; }
        $sql .= " ORDER BY ts.weighted_accuracy ASC";
        return $wpdb->get_results($wpdb->prepare($sql, ...$args));
    }

    public function get_due_revisions($user_id) {
        global $wpdb;
        return $wpdb->get_results($wpdb->prepare(
            "SELECT ts.*, s.name AS subject_name, s.color, t.name AS topic_name, st.name AS subtopic_name
             FROM ".CIAS_TOPIC_STATS." ts
             LEFT JOIN ".CIAS_SUBJECTS." s ON ts.subject_id=s.id
             LEFT JOIN ".CIAS_TOPICS." t ON ts.topic_id=t.id
             LEFT JOIN ".CIAS_SUBTOPICS." st ON ts.subtopic_id=st.id
             WHERE ts.user_id=%d AND ts.next_revision <= %s
             ORDER BY ts.weighted_accuracy ASC LIMIT 10",
            $user_id, current_time('Y-m-d')
        ));
    }

    public function count_due_revisions($user_id) {
        global $wpdb;
        return intval($wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM ".CIAS_TOPIC_STATS." WHERE user_id=%d AND next_revision <= %s",
            $user_id, current_time('Y-m-d')
        )));
    }

    /* ══════════════════════════════════
       BATCHES
    ══════════════════════════════════ */
    public function get_batches_with_course() {
        global $wpdb;
        return $wpdb->get_results(
            "SELECT b.*, c.name AS course_name FROM ".CIAS_BATCHES." b
             LEFT JOIN ".CIAS_COURSES." c ON b.course_id=c.id ORDER BY b.id DESC"
        );
    }

    /* ══════════════════════════════════
       ENROLLMENTS
    ══════════════════════════════════ */
    public function enroll_student($user_id, $batch_id) {
        global $wpdb;
        $wpdb->query($wpdb->prepare(
            "INSERT IGNORE INTO ".CIAS_ENROLLMENTS." (user_id,batch_id) VALUES(%d,%d)",
            $user_id, $batch_id
        ));
    }

    public function unenroll($enrollment_id) {
        global $wpdb;
        $wpdb->delete(CIAS_ENROLLMENTS, ['id'=>$enrollment_id]);
    }

    public function get_enrollments_full() {
        global $wpdb;
        return $wpdb->get_results(
            "SELECT e.*, u.display_name, u.user_email, b.name AS batch_name, c.name AS course_name
             FROM ".CIAS_ENROLLMENTS." e
             JOIN {$wpdb->users} u ON e.user_id=u.ID
             JOIN ".CIAS_BATCHES." b ON e.batch_id=b.id
             LEFT JOIN ".CIAS_COURSES." c ON b.course_id=c.id
             ORDER BY e.id DESC"
        );
    }

    public function get_student_batch_ids($user_id) {
        global $wpdb;
        return $wpdb->get_col($wpdb->prepare(
            "SELECT batch_id FROM ".CIAS_ENROLLMENTS." WHERE user_id=%d AND status='active'", $user_id
        ));
    }

    /* ══════════════════════════════════
       QUESTIONS
    ══════════════════════════════════ */
    public function get_questions_list($subject_id = 0, $status = '', $filters = []) {
        global $wpdb;
        $where_parts = ['1=1'];
        $args        = [];

        if (!empty($subject_id)) {
            $where_parts[] = 'q.subject_id=%d';
            $args[]        = intval($subject_id);
        }
        if (!empty($status)) {
            $where_parts[] = 'q.status=%s';
            $args[]        = sanitize_text_field($status);
        }
        if (!empty($filters['topic_id'])) {
            $where_parts[] = 'q.topic_id=%d';
            $args[]        = intval($filters['topic_id']);
        }
        if (!empty($filters['subtopic_id'])) {
            $where_parts[] = 'q.subtopic_id=%d';
            $args[]        = intval($filters['subtopic_id']);
        }
        if (!empty($filters['date_from'])) {
            $where_parts[] = 'DATE(q.created_at) >= %s';
            $args[]        = sanitize_text_field($filters['date_from']);
        }
        if (!empty($filters['date_to'])) {
            $where_parts[] = 'DATE(q.created_at) <= %s';
            $args[]        = sanitize_text_field($filters['date_to']);
        }
        if (!empty($filters['qtype'])) {
            $where_parts[] = 'q.question_type=%s';
            $args[]        = sanitize_text_field($filters['qtype']);
        }

        $where = implode(' AND ', $where_parts);
        $sql   = "SELECT q.*, s.name AS subject_name,
                    t.name AS topic_name, st.name AS subtopic_name
                FROM ".CIAS_QUESTIONS." q
                LEFT JOIN ".CIAS_SUBJECTS."  s  ON q.subject_id=s.id
                LEFT JOIN ".CIAS_TOPICS."    t  ON q.topic_id=t.id
                LEFT JOIN ".CIAS_SUBTOPICS." st ON q.subtopic_id=st.id
                WHERE {$where}
                ORDER BY q.created_at DESC, q.id DESC";

        return empty($args)
            ? $wpdb->get_results($sql)
            : $wpdb->get_results($wpdb->prepare($sql, ...$args));
    }

    public function check_duplicate_questions($question_ids) {
        global $wpdb;
        if (empty($question_ids)) return [];
        $placeholders = implode(',', array_fill(0, count($question_ids), '%d'));
        $questions = $wpdb->get_results($wpdb->prepare(
            "SELECT id, question_text FROM ".CIAS_QUESTIONS." WHERE id IN ($placeholders)",
            ...$question_ids
        ));
        // Simple similarity check — flag pairs with >80% word overlap
        $duplicates = [];
        $texts = [];
        foreach ($questions as $q) {
            $words = array_unique(preg_split('/\s+/', strtolower(preg_replace('/[^a-zA-Z0-9\s]/', '', $q->question_text))));
            $texts[$q->id] = $words;
        }
        $ids = array_keys($texts);
        for ($i = 0; $i < count($ids); $i++) {
            for ($j = $i + 1; $j < count($ids); $j++) {
                $a = $texts[$ids[$i]];
                $b = $texts[$ids[$j]];
                $intersection = count(array_intersect($a, $b));
                $union = count(array_unique(array_merge($a, $b)));
                $similarity = $union > 0 ? ($intersection / $union) : 0;
                if ($similarity > 0.7) {
                    $duplicates[] = ['q1' => $ids[$i], 'q2' => $ids[$j], 'similarity' => round($similarity * 100)];
                }
            }
        }
        return $duplicates;
    }

    // PIN management
    public function generate_test_pin($test_id, $minutes = 60) {
        global $wpdb;
        $pin     = str_pad(rand(100000, 999999), 6, '0', STR_PAD_LEFT);
        $expires = date('Y-m-d H:i:s', time() + ($minutes * 60));
        $wpdb->update(CIAS_TESTS, ['access_pin' => $pin, 'pin_expires_at' => $expires], ['id' => $test_id]);
        return ['pin' => $pin, 'expires_at' => $expires];
    }

    public function verify_test_pin($test_id, $pin) {
        global $wpdb;
        $test = $wpdb->get_row($wpdb->prepare(
            "SELECT access_pin, pin_expires_at FROM ".CIAS_TESTS." WHERE id=%d", $test_id
        ));
        if (!$test || empty($test->access_pin)) return true; // No PIN required
        if ($test->access_pin !== $pin) return false;
        if ($test->pin_expires_at && strtotime($test->pin_expires_at) < time()) return false;
        return true;
    }

    public function clear_test_pin($test_id) {
        global $wpdb;
        $wpdb->update(CIAS_TESTS, ['access_pin' => '', 'pin_expires_at' => null], ['id' => $test_id]);
    }

    // Active sessions
    public function get_active_sessions($test_id) {
        global $wpdb;
        return $wpdb->get_results($wpdb->prepare(
            "SELECT s.*, u.display_name, a.percentage, a.status AS attempt_status
             FROM {$wpdb->prefix}cias_active_sessions s
             JOIN {$wpdb->users} u ON s.user_id = u.ID
             LEFT JOIN ".CIAS_ATTEMPTS." a ON a.user_id=s.user_id AND a.test_id=s.test_id AND a.status='in_progress'
             WHERE s.test_id=%d AND s.kicked=0
             AND s.last_seen >= DATE_SUB(NOW(), INTERVAL 5 MINUTE)
             ORDER BY s.started_at ASC",
            $test_id
        ));
    }

    public function kick_student_from_test($test_id, $user_id) {
        global $wpdb;
        $wpdb->update(
            $wpdb->prefix . 'cias_active_sessions',
            ['kicked' => 1],
            ['test_id' => $test_id, 'user_id' => $user_id]
        );
    }

    /* ══════════════════════════════════
       TESTS
    ══════════════════════════════════ */
    public function create_test($data, $question_ids, $batch_ids) {
        global $wpdb;
        $wpdb->insert(CIAS_TESTS, $data);
        $tid = $wpdb->insert_id;
        $this->sync_test_questions($tid, $question_ids);
        $this->sync_test_batches($tid, $batch_ids);
        return $tid;
    }

    public function update_test($tid, $data, $question_ids, $batch_ids) {
        global $wpdb;
        $wpdb->update(CIAS_TESTS, $data, ['id'=>$tid]);
        $this->sync_test_questions($tid, $question_ids);
        $this->sync_test_batches($tid, $batch_ids);
        return $tid;
    }

    private function sync_test_questions($tid, $ids) {
        global $wpdb;
        $wpdb->delete(CIAS_TEST_Q, ['test_id'=>$tid]);
        foreach (array_values($ids) as $pos => $qid) {
            $wpdb->insert(CIAS_TEST_Q, ['test_id'=>$tid,'question_id'=>$qid,'position'=>$pos]);
        }
    }

    private function sync_test_batches($tid, $ids) {
        global $wpdb;
        $wpdb->delete(CIAS_TEST_BATCH, ['test_id'=>$tid]);
        foreach ($ids as $bid) {
            $wpdb->insert(CIAS_TEST_BATCH, ['test_id'=>$tid,'batch_id'=>$bid]);
        }
    }

    public function delete_test($tid) {
        global $wpdb;
        $wpdb->delete(CIAS_TESTS,     ['id'=>$tid]);
        $wpdb->delete(CIAS_TEST_Q,    ['test_id'=>$tid]);
        $wpdb->delete(CIAS_TEST_BATCH,['test_id'=>$tid]);
        $attempt_ids = $wpdb->get_col($wpdb->prepare("SELECT id FROM ".CIAS_ATTEMPTS." WHERE test_id=%d",$tid));
        foreach ($attempt_ids as $aid) $wpdb->delete(CIAS_ANSWERS, ['attempt_id'=>$aid]);
        $wpdb->delete(CIAS_ATTEMPTS, ['test_id'=>$tid]);
    }

    public function toggle_test_status($tid) {
        global $wpdb;
        $current = $wpdb->get_var($wpdb->prepare("SELECT status FROM ".CIAS_TESTS." WHERE id=%d",$tid));
        $new = $current === 'published' ? 'draft' : 'published';
        $wpdb->update(CIAS_TESTS, ['status'=>$new], ['id'=>$tid]);
    }

    public function get_test_full($tid) {
        global $wpdb;
        return $wpdb->get_row($wpdb->prepare(
            "SELECT t.*, s.name AS subject_name FROM ".CIAS_TESTS." t LEFT JOIN ".CIAS_SUBJECTS." s ON t.subject_id=s.id WHERE t.id=%d", $tid
        ));
    }

    public function get_test_question_ids($tid) {
        global $wpdb;
        return array_map('intval', $wpdb->get_col($wpdb->prepare(
            "SELECT question_id FROM ".CIAS_TEST_Q." WHERE test_id=%d ORDER BY position ASC", $tid
        )));
    }

    public function get_test_batch_ids($tid) {
        global $wpdb;
        return array_map('intval', $wpdb->get_col($wpdb->prepare(
            "SELECT batch_id FROM ".CIAS_TEST_BATCH." WHERE test_id=%d", $tid
        )));
    }

    public function get_tests_list() {
        global $wpdb;
        return $wpdb->get_results(
            "SELECT t.*, s.name AS subject_name,
                (SELECT COUNT(*) FROM ".CIAS_TEST_Q." WHERE test_id=t.id) AS q_count,
                (SELECT COUNT(*) FROM ".CIAS_TEST_BATCH." WHERE test_id=t.id) AS batch_count,
                (SELECT COUNT(*) FROM ".CIAS_ATTEMPTS." WHERE test_id=t.id AND status='submitted') AS attempt_count
             FROM ".CIAS_TESTS." t
             LEFT JOIN ".CIAS_SUBJECTS." s ON t.subject_id=s.id
             ORDER BY t.id DESC"
        );
    }

    /* ══════════════════════════════════
       STUDENT TEST ACCESS
    ══════════════════════════════════ */
    public function get_student_tests($user_id) {
        global $wpdb;
        $batch_ids = $this->get_student_batch_ids($user_id);
        if (empty($batch_ids)) return [];
        $in = implode(',', array_map('intval', $batch_ids));

        return $wpdb->get_results($wpdb->prepare(
            "SELECT DISTINCT t.*,
                s.name AS subject_name, s.color AS subject_color,
                (SELECT COUNT(*) FROM ".CIAS_TEST_Q." WHERE test_id=t.id) AS q_count,
                (SELECT id FROM ".CIAS_ATTEMPTS." WHERE test_id=t.id AND user_id=%d AND status='submitted' LIMIT 1) AS attempt_id,
                (SELECT percentage FROM ".CIAS_ATTEMPTS." WHERE test_id=t.id AND user_id=%d AND status='submitted' LIMIT 1) AS my_pct,
                (SELECT status FROM ".CIAS_ATTEMPTS." WHERE test_id=t.id AND user_id=%d ORDER BY id DESC LIMIT 1) AS my_attempt_status
             FROM ".CIAS_TESTS." t
             JOIN ".CIAS_TEST_BATCH." tb ON tb.test_id=t.id
             LEFT JOIN ".CIAS_SUBJECTS." s ON t.subject_id=s.id
             WHERE tb.batch_id IN($in) AND t.status='published'
             ORDER BY t.scheduled_date DESC, t.id DESC",
            $user_id, $user_id, $user_id
        ));
    }

    public function count_pending_tests($user_id) {
        global $wpdb;
        $batch_ids = $this->get_student_batch_ids($user_id);
        if (empty($batch_ids)) return 0;
        $in = implode(',', array_map('intval', $batch_ids));
        return intval($wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(DISTINCT t.id) FROM ".CIAS_TESTS." t
             JOIN ".CIAS_TEST_BATCH." tb ON tb.test_id=t.id
             WHERE tb.batch_id IN($in) AND t.status='published'
             AND NOT EXISTS (SELECT 1 FROM ".CIAS_ATTEMPTS." a WHERE a.test_id=t.id AND a.user_id=%d AND a.status='submitted')",
            $user_id
        )));
    }

    /* ══════════════════════════════════
       ATTEMPTS
    ══════════════════════════════════ */
    public function start_attempt($test_id, $user_id) {
        global $wpdb;
        $existing = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM ".CIAS_ATTEMPTS." WHERE test_id=%d AND user_id=%d AND status='in_progress'",
            $test_id, $user_id
        ));
        if ($existing) return $existing->id;
        $q_count = intval($wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM ".CIAS_TEST_Q." WHERE test_id=%d",$test_id)));
        $wpdb->insert(CIAS_ATTEMPTS, ['test_id'=>$test_id,'user_id'=>$user_id,'total'=>$q_count,'status'=>'in_progress']);
        return $wpdb->insert_id;
    }

    public function save_answer($attempt_id, $question_id, $selected_option) {
        global $wpdb;
        $correct_opt = $wpdb->get_var($wpdb->prepare("SELECT correct_option FROM ".CIAS_QUESTIONS." WHERE id=%d",$question_id));
        $is_correct  = (strtolower($selected_option) === strtolower($correct_opt)) ? 1 : 0;
        $wpdb->query($wpdb->prepare(
            "INSERT INTO ".CIAS_ANSWERS." (attempt_id,question_id,selected_option,is_correct,answered_at)
             VALUES(%d,%d,%s,%d,%s)
             ON DUPLICATE KEY UPDATE selected_option=%s, is_correct=%d, answered_at=%s",
            $attempt_id, $question_id, $selected_option, $is_correct, current_time('mysql'),
            $selected_option, $is_correct, current_time('mysql')
        ));
        return $is_correct;
    }

    public function submit_attempt($attempt_id, $user_id) {
        global $wpdb;
        $attempt = $wpdb->get_row($wpdb->prepare("SELECT * FROM ".CIAS_ATTEMPTS." WHERE id=%d AND user_id=%d",$attempt_id,$user_id));
        if (!$attempt || $attempt->status === 'submitted') return $attempt;

        $score    = intval($wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM ".CIAS_ANSWERS." WHERE attempt_id=%d AND is_correct=1",$attempt_id)));
        $total    = intval($attempt->total);
        $pct      = $total > 0 ? round(($score/$total)*100,1) : 0;
        $time_taken = (int)(strtotime(current_time('mysql')) - strtotime($attempt->started_at));

        $wpdb->update(CIAS_ATTEMPTS,[
            'score'        => $score,
            'percentage'   => $pct,
            'time_taken'   => $time_taken,
            'submitted_at' => current_time('mysql'),
            'status'       => 'submitted',
        ],['id'=>$attempt_id]);

        return (object)array_merge((array)$attempt,['score'=>$score,'total'=>$total,'percentage'=>$pct,'time_taken'=>$time_taken,'status'=>'submitted']);
    }

    public function get_attempt_with_answers($attempt_id, $user_id) {
        global $wpdb;
        $attempt = $wpdb->get_row($wpdb->prepare("SELECT * FROM ".CIAS_ATTEMPTS." WHERE id=%d AND user_id=%d",$attempt_id,$user_id));
        if (!$attempt) return null;
        $questions = $wpdb->get_results($wpdb->prepare(
            "SELECT q.*, tq.position, ans.selected_option, ans.is_correct
             FROM ".CIAS_TEST_Q." tq
             JOIN ".CIAS_QUESTIONS." q ON tq.question_id=q.id
             LEFT JOIN ".CIAS_ANSWERS." ans ON ans.question_id=q.id AND ans.attempt_id=%d
             WHERE tq.test_id=%d ORDER BY tq.position ASC",
            $attempt_id, $attempt->test_id
        ));
        return ['attempt'=>$attempt,'questions'=>$questions];
    }

    public function get_test_questions_for_exam($test_id) {
        global $wpdb;
        return $wpdb->get_results($wpdb->prepare(
            "SELECT q.id, q.question_text, q.question_type, q.statements,
                    q.question_tags, q.year_asked,
                    q.option_a, q.option_b, q.option_c, q.option_d
             FROM ".CIAS_TEST_Q." tq JOIN ".CIAS_QUESTIONS." q ON tq.question_id=q.id
             WHERE tq.test_id=%d ORDER BY tq.position ASC",
            $test_id
        ));
    }

    public function get_saved_answers($attempt_id) {
        global $wpdb;
        $rows = $wpdb->get_results($wpdb->prepare("SELECT question_id, selected_option FROM ".CIAS_ANSWERS." WHERE attempt_id=%d",$attempt_id));
        $map  = [];
        foreach ($rows as $r) $map[$r->question_id] = $r->selected_option;
        return $map;
    }

    public function get_student_attempts($user_id) {
        global $wpdb;
        return $wpdb->get_results($wpdb->prepare(
            "SELECT a.*, t.title AS test_title FROM ".CIAS_ATTEMPTS." a
             JOIN ".CIAS_TESTS." t ON a.test_id=t.id
             WHERE a.user_id=%d AND a.status='submitted' ORDER BY a.submitted_at DESC",
            $user_id
        ));
    }

    /* ══════════════════════════════════
       REPORTING
    ══════════════════════════════════ */
    public function get_overview_stats() {
        global $wpdb;
        return [
            'courses'   => $wpdb->get_var("SELECT COUNT(*) FROM ".CIAS_COURSES),
            'batches'   => $wpdb->get_var("SELECT COUNT(*) FROM ".CIAS_BATCHES),
            'students'  => $wpdb->get_var("SELECT COUNT(DISTINCT user_id) FROM ".CIAS_ENROLLMENTS),
            'questions' => $wpdb->get_var("SELECT COUNT(*) FROM ".CIAS_QUESTIONS),
            'tests'     => $wpdb->get_var("SELECT COUNT(*) FROM ".CIAS_TESTS),
            'attempts'  => $wpdb->get_var("SELECT COUNT(*) FROM ".CIAS_ATTEMPTS." WHERE status='submitted'"),
        ];
    }

    public function get_student_summary($user_id) {
        global $wpdb;
        $attempts = $wpdb->get_results($wpdb->prepare(
            "SELECT percentage FROM ".CIAS_ATTEMPTS." WHERE user_id=%d AND status='submitted'", $user_id
        ));
        if (empty($attempts)) return ['total'=>0,'avg'=>0,'best'=>0,'pass_rate'=>0];
        $pcts  = array_column($attempts, 'percentage');
        $pass  = get_option('cias_pass_percentage', 60);
        $passed= count(array_filter($pcts, function($p) use ($pass) { return $p >= $pass; }));
        return [
            'total'     => count($pcts),
            'avg'       => round(array_sum($pcts)/count($pcts),1),
            'best'      => max($pcts),
            'pass_rate' => round($passed/count($pcts)*100),
        ];
    }

    public function get_batch_report($batch_id) {
        global $wpdb;
        return $wpdb->get_results($wpdb->prepare(
            "SELECT u.display_name, COUNT(a.id) AS total_attempts,
                ROUND(AVG(a.percentage),1) AS avg_pct, MAX(a.percentage) AS best_pct
             FROM ".CIAS_ENROLLMENTS." e
             JOIN {$wpdb->users} u ON e.user_id=u.ID
             LEFT JOIN ".CIAS_ATTEMPTS." a ON a.user_id=e.user_id AND a.status='submitted'
             WHERE e.batch_id=%d GROUP BY e.user_id ORDER BY avg_pct DESC",
            $batch_id
        ));
    }

    public function get_test_results($test_id) {
        global $wpdb;
        return $wpdb->get_results($wpdb->prepare(
            "SELECT a.*, u.display_name FROM ".CIAS_ATTEMPTS." a
             JOIN {$wpdb->users} u ON a.user_id=u.ID
             WHERE a.test_id=%d AND a.status='submitted' ORDER BY a.percentage DESC",
            $test_id
        ));
    }

    public function get_question_analysis($test_id) {
        global $wpdb;
        return $wpdb->get_results($wpdb->prepare(
            "SELECT q.question_text,
                SUM(CASE WHEN ans.is_correct=1 THEN 1 ELSE 0 END) AS correct,
                SUM(CASE WHEN ans.is_correct=0 THEN 1 ELSE 0 END) AS wrong
             FROM ".CIAS_TEST_Q." tq
             JOIN ".CIAS_QUESTIONS." q ON tq.question_id=q.id
             LEFT JOIN ".CIAS_ANSWERS." ans ON ans.question_id=q.id
             LEFT JOIN ".CIAS_ATTEMPTS." a ON ans.attempt_id=a.id AND a.status='submitted'
             WHERE tq.test_id=%d GROUP BY tq.question_id ORDER BY correct ASC",
            $test_id
        ));
    }

    /* ══════════════════════════════════
       TEACHER BATCH METHODS
    ══════════════════════════════════ */
    public function set_teacher_batches($teacher_id, $batch_ids) {
        global $wpdb;
        $wpdb->delete(CIAS_TEACHER_BATCHES, ['teacher_id' => intval($teacher_id)]);
        foreach ($batch_ids as $bid) {
            $wpdb->insert(CIAS_TEACHER_BATCHES, ['teacher_id' => intval($teacher_id), 'batch_id' => intval($bid)]);
        }
    }

    public function get_teacher_batches($teacher_id) {
        global $wpdb;
        return $wpdb->get_results($wpdb->prepare(
            "SELECT b.*, c.name AS course_name FROM ".CIAS_TEACHER_BATCHES." tb
             JOIN ".CIAS_BATCHES." b ON tb.batch_id=b.id
             LEFT JOIN ".CIAS_COURSES." c ON b.course_id=c.id
             WHERE tb.teacher_id=%d ORDER BY b.name ASC",
            intval($teacher_id)
        ));
    }

    public function get_batch_students($batch_id) {
        global $wpdb;
        $user_ids = $wpdb->get_col($wpdb->prepare(
            "SELECT user_id FROM ".CIAS_ENROLLMENTS." WHERE batch_id=%d AND status='active'", $batch_id
        ));
        if (empty($user_ids)) return [];
        $in = implode(',', array_map('intval', $user_ids));
        return $wpdb->get_results("SELECT ID, display_name, user_email FROM {$wpdb->users} WHERE ID IN($in) ORDER BY display_name ASC");
    }

    /* ══════════════════════════════════
       LEADERBOARD
    ══════════════════════════════════ */
    public function get_leaderboard($batch_id, $period = 'week', $subject_id = 0) {
        global $wpdb;

        $date_filter = '';
        if ($period === 'week')  $date_filter = "AND a.submitted_at >= DATE_SUB(NOW(), INTERVAL 7 DAY)";
        if ($period === 'month') $date_filter = "AND a.submitted_at >= DATE_SUB(NOW(), INTERVAL 30 DAY)";

        $subject_filter = $subject_id ? $wpdb->prepare("AND t.subject_id=%d", $subject_id) : '';

        return $wpdb->get_results($wpdb->prepare(
            "SELECT u.ID AS user_id, u.display_name,
                COUNT(DISTINCT a.id)      AS total_tests,
                ROUND(AVG(a.percentage),1) AS avg_pct,
                MAX(a.percentage)          AS best_pct,
                (SELECT COUNT(DISTINCT DATE(a2.submitted_at))
                 FROM ".CIAS_ATTEMPTS." a2
                 WHERE a2.user_id=u.ID AND a2.status='submitted'
                 AND a2.submitted_at >= DATE_SUB(NOW(), INTERVAL 30 DAY)) AS streak
             FROM ".CIAS_ENROLLMENTS." e
             JOIN {$wpdb->users} u ON e.user_id=u.ID
             LEFT JOIN ".CIAS_ATTEMPTS." a ON a.user_id=u.ID AND a.status='submitted' $date_filter
             LEFT JOIN ".CIAS_TESTS." t ON a.test_id=t.id $subject_filter
             WHERE e.batch_id=%d AND e.status='active'
             GROUP BY u.ID
             ORDER BY avg_pct DESC, total_tests DESC
             LIMIT 30",
            intval($batch_id)
        ));
    }

    /* ══════════════════════════════════
       TEACHER DASHBOARD DATA
    ══════════════════════════════════ */
    public function get_batch_weekly_curve($batch_id, $weeks = 4) {
        global $wpdb;
        $rows = [];
        for ($i = $weeks - 1; $i >= 0; $i--) {
            $start = date('Y-m-d', strtotime("-" . ($i+1) . " weeks"));
            $end   = date('Y-m-d', strtotime("-$i weeks"));
            $avg   = $wpdb->get_var($wpdb->prepare(
                "SELECT ROUND(AVG(a.percentage),1) FROM ".CIAS_ATTEMPTS." a
                 JOIN ".CIAS_ENROLLMENTS." e ON a.user_id=e.user_id
                 WHERE e.batch_id=%d AND a.status='submitted'
                 AND a.submitted_at >= %s AND a.submitted_at < %s",
                $batch_id, $start, $end
            ));
            $rows[] = ['week' => 'W'.($weeks-$i), 'avg' => floatval($avg ?? 0)];
        }
        return $rows;
    }

    public function get_batch_subject_heatmap($batch_id) {
        global $wpdb;
        return $wpdb->get_results($wpdb->prepare(
            "SELECT s.name AS subject_name, s.color,
                ROUND(AVG(a.percentage),1) AS avg_pct,
                COUNT(DISTINCT a.id) AS test_count
             FROM ".CIAS_ATTEMPTS." a
             JOIN ".CIAS_ENROLLMENTS." e ON a.user_id=e.user_id
             JOIN ".CIAS_TESTS." t ON a.test_id=t.id
             JOIN ".CIAS_SUBJECTS." s ON t.subject_id=s.id
             WHERE e.batch_id=%d AND a.status='submitted'
             GROUP BY t.subject_id ORDER BY s.name",
            intval($batch_id)
        ));
    }

    public function get_batch_inactive_students($batch_id, $days = 7) {
        global $wpdb;
        $since = date('Y-m-d H:i:s', strtotime("-$days days"));
        return $wpdb->get_results($wpdb->prepare(
            "SELECT u.ID, u.display_name, u.user_email,
                MAX(a.submitted_at) AS last_active,
                ROUND(AVG(a.percentage),1) AS avg_pct
             FROM ".CIAS_ENROLLMENTS." e
             JOIN {$wpdb->users} u ON e.user_id=u.ID
             LEFT JOIN ".CIAS_ATTEMPTS." a ON a.user_id=u.ID AND a.status='submitted'
             WHERE e.batch_id=%d AND e.status='active'
             GROUP BY u.ID
             HAVING last_active IS NULL OR last_active < %s
             ORDER BY last_active ASC",
            intval($batch_id), $since
        ));
    }

    public function get_batch_overview($batch_id) {
        global $wpdb;
        $enrolled = intval($wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM ".CIAS_ENROLLMENTS." WHERE batch_id=%d AND status='active'", $batch_id
        )));
        $avg_pct = floatval($wpdb->get_var($wpdb->prepare(
            "SELECT ROUND(AVG(a.percentage),1) FROM ".CIAS_ATTEMPTS." a
             JOIN ".CIAS_ENROLLMENTS." e ON a.user_id=e.user_id
             WHERE e.batch_id=%d AND a.status='submitted'", $batch_id
        )));
        $tests_done = intval($wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM ".CIAS_ATTEMPTS." a
             JOIN ".CIAS_ENROLLMENTS." e ON a.user_id=e.user_id
             WHERE e.batch_id=%d AND a.status='submitted'", $batch_id
        )));
        $active_week = intval($wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(DISTINCT a.user_id) FROM ".CIAS_ATTEMPTS." a
             JOIN ".CIAS_ENROLLMENTS." e ON a.user_id=e.user_id
             WHERE e.batch_id=%d AND a.status='submitted'
             AND a.submitted_at >= DATE_SUB(NOW(), INTERVAL 7 DAY)", $batch_id
        )));
        return compact('enrolled','avg_pct','tests_done','active_week');
    }

    public function get_batch_topic_accuracy($batch_id) {
        global $wpdb;
        return $wpdb->get_results($wpdb->prepare(
            "SELECT t.name AS topic_name, s.name AS subject_name,
                ROUND(AVG(ts.weighted_accuracy),1) AS avg_accuracy,
                SUM(ts.total_questions) AS total_questions,
                COUNT(DISTINCT ts.user_id) AS student_count
             FROM ".CIAS_TOPIC_STATS." ts
             JOIN ".CIAS_ENROLLMENTS." e ON ts.user_id=e.user_id
             LEFT JOIN ".CIAS_TOPICS." t ON ts.topic_id=t.id
             LEFT JOIN ".CIAS_SUBJECTS." s ON ts.subject_id=s.id
             WHERE e.batch_id=%d AND e.status='active' AND ts.topic_id > 0
             GROUP BY ts.topic_id
             ORDER BY avg_accuracy DESC",
            intval($batch_id)
        ));
    }

    public function get_batch_weak_topics($batch_id, $limit = 5) {
        global $wpdb;
        return $wpdb->get_results($wpdb->prepare(
            "SELECT t.name AS topic_name, s.name AS subject_name,
                ROUND(AVG(ts.weighted_accuracy),1) AS avg_accuracy,
                COUNT(DISTINCT ts.user_id) AS student_count
             FROM ".CIAS_TOPIC_STATS." ts
             JOIN ".CIAS_ENROLLMENTS." e ON ts.user_id=e.user_id
             LEFT JOIN ".CIAS_TOPICS." t ON ts.topic_id=t.id
             LEFT JOIN ".CIAS_SUBJECTS." s ON ts.subject_id=s.id
             WHERE e.batch_id=%d AND e.status='active' AND ts.topic_id > 0
             GROUP BY ts.topic_id
             HAVING student_count >= 1
             ORDER BY avg_accuracy ASC
             LIMIT %d",
            intval($batch_id), intval($limit)
        ));
    }

    public function get_batch_most_improved($batch_id) {
        global $wpdb;
        // Compare first 3 attempts avg vs last 3 attempts avg
        return $wpdb->get_results($wpdb->prepare(
            "SELECT u.display_name,
                (SELECT ROUND(AVG(a2.percentage),1) FROM ".CIAS_ATTEMPTS." a2
                 WHERE a2.user_id=e.user_id AND a2.status='submitted'
                 ORDER BY a2.id ASC LIMIT 3) AS early_avg,
                (SELECT ROUND(AVG(a3.percentage),1) FROM ".CIAS_ATTEMPTS." a3
                 WHERE a3.user_id=e.user_id AND a3.status='submitted'
                 ORDER BY a3.id DESC LIMIT 3) AS recent_avg,
                COUNT(a.id) AS total_attempts
             FROM ".CIAS_ENROLLMENTS." e
             JOIN {$wpdb->users} u ON e.user_id=u.ID
             LEFT JOIN ".CIAS_ATTEMPTS." a ON a.user_id=e.user_id AND a.status='submitted'
             WHERE e.batch_id=%d AND e.status='active'
             GROUP BY e.user_id
             HAVING total_attempts >= 4
             ORDER BY (recent_avg - early_avg) DESC
             LIMIT 5",
            intval($batch_id)
        ));
    }

    public function get_student_detail_for_teacher($user_id, $batch_id) {
        global $wpdb;
        // Recent attempts
        $attempts = $wpdb->get_results($wpdb->prepare(
            "SELECT a.*, t.title AS test_title, t.subject_id,
                s.name AS subject_name,
                (SELECT COUNT(*) FROM ".CIAS_ANSWERS." WHERE attempt_id=a.id AND is_correct=1) AS correct,
                (SELECT COUNT(*) FROM ".CIAS_ANSWERS." WHERE attempt_id=a.id) AS answered
             FROM ".CIAS_ATTEMPTS." a
             LEFT JOIN ".CIAS_TESTS." t ON a.test_id=t.id
             LEFT JOIN ".CIAS_SUBJECTS." s ON t.subject_id=s.id
             WHERE a.user_id=%d AND a.status='submitted'
             ORDER BY a.submitted_at DESC LIMIT 15",
            intval($user_id)
        ));
        // Subject breakdown
        $subjects = $wpdb->get_results($wpdb->prepare(
            "SELECT s.name AS subject_name, s.color,
                COUNT(a.id) AS tests_taken,
                ROUND(AVG(a.percentage),1) AS avg_pct
             FROM ".CIAS_ATTEMPTS." a
             JOIN ".CIAS_ENROLLMENTS." e ON a.user_id=e.user_id
             JOIN ".CIAS_TESTS." t ON a.test_id=t.id
             JOIN ".CIAS_SUBJECTS." s ON t.subject_id=s.id
             WHERE a.user_id=%d AND e.batch_id=%d AND a.status='submitted'
             GROUP BY t.subject_id ORDER BY avg_pct ASC",
            intval($user_id), intval($batch_id)
        ));
        // Topic stats
        $topics = $wpdb->get_results($wpdb->prepare(
            "SELECT ts.*, t.name AS topic_name, s.name AS subject_name, ts.level,
                ROUND(ts.weighted_accuracy,1) AS accuracy
             FROM ".CIAS_TOPIC_STATS." ts
             LEFT JOIN ".CIAS_TOPICS." t ON ts.topic_id=t.id
             LEFT JOIN ".CIAS_SUBJECTS." s ON ts.subject_id=s.id
             WHERE ts.user_id=%d AND ts.topic_id>0
             ORDER BY ts.weighted_accuracy ASC LIMIT 10",
            intval($user_id)
        ));
        // Offline results
        $offline = $wpdb->get_results($wpdb->prepare(
            "SELECT r.*, ot.title, ot.max_marks, ot.date_conducted, ot.test_type
             FROM ".CIAS_OFFLINE_RESULTS." r
             JOIN ".CIAS_OFFLINE_TESTS." ot ON r.offline_test_id=ot.id
             WHERE r.user_id=%d AND ot.batch_id=%d
             ORDER BY ot.date_conducted DESC LIMIT 10",
            intval($user_id), intval($batch_id)
        ));
        return compact('attempts','subjects','topics','offline');
    }
    public function create_offline_test($data) {
        global $wpdb;
        $wpdb->insert(CIAS_OFFLINE_TESTS, $data);
        return $wpdb->insert_id;
    }

    public function update_offline_test($id, $data) {
        global $wpdb;
        return $wpdb->update(CIAS_OFFLINE_TESTS, $data, ['id' => intval($id)]);
    }

    public function get_offline_test($id) {
        global $wpdb;
        return $wpdb->get_row($wpdb->prepare(
            "SELECT ot.*, b.name AS batch_name, b.course_id FROM ".CIAS_OFFLINE_TESTS." ot
             LEFT JOIN ".CIAS_BATCHES." b ON ot.batch_id=b.id WHERE ot.id=%d", $id
        ));
    }

    public function toggle_offline_test_status($id) {
        global $wpdb;
        $current = $wpdb->get_var($wpdb->prepare("SELECT status FROM ".CIAS_OFFLINE_TESTS." WHERE id=%d", $id));
        $new = $current === 'published' ? 'draft' : 'published';
        $wpdb->update(CIAS_OFFLINE_TESTS, ['status'=>$new], ['id'=>intval($id)]);
    }

    public function delete_offline_test($id) {
        global $wpdb;
        $wpdb->delete(CIAS_OFFLINE_RESULTS, ['offline_test_id' => intval($id)]);
        $wpdb->delete(CIAS_OFFLINE_TESTS,   ['id'              => intval($id)]);
    }

    public function save_offline_result($test_id, $user_id, $marks, $is_absent, $pct, $grade) {
        global $wpdb;
        $wpdb->query($wpdb->prepare(
            "INSERT INTO ".CIAS_OFFLINE_RESULTS."
             (offline_test_id,user_id,marks_obtained,is_absent,percentage,grade)
             VALUES(%d,%d,%f,%d,%f,%s)
             ON DUPLICATE KEY UPDATE marks_obtained=%f, is_absent=%d, percentage=%f, grade=%s",
            $test_id,$user_id,$marks,$is_absent,$pct,$grade,
            $marks,$is_absent,$pct,$grade
        ));
    }

    public function get_offline_results($test_id) {
        global $wpdb;
        return $wpdb->get_results($wpdb->prepare(
            "SELECT * FROM ".CIAS_OFFLINE_RESULTS." WHERE offline_test_id=%d", intval($test_id)
        ));
    }

    public function get_offline_tests_list() {
        global $wpdb;
        return $wpdb->get_results(
            "SELECT ot.*, b.name AS batch_name,
                (SELECT COUNT(*) FROM ".CIAS_OFFLINE_RESULTS." WHERE offline_test_id=ot.id) AS result_count,
                (SELECT COUNT(*) FROM ".CIAS_ENROLLMENTS." WHERE batch_id=ot.batch_id AND status='active') AS student_count
             FROM ".CIAS_OFFLINE_TESTS." ot
             LEFT JOIN ".CIAS_BATCHES." b ON ot.batch_id=b.id
             ORDER BY ot.created_at DESC"
        );
    }

    public function get_student_offline_results($user_id) {
        global $wpdb;
        return $wpdb->get_results($wpdb->prepare(
            "SELECT r.*, ot.title, ot.max_marks, ot.date_conducted, ot.test_type, s.name AS subject_name
             FROM ".CIAS_OFFLINE_RESULTS." r
             JOIN ".CIAS_OFFLINE_TESTS." ot ON r.offline_test_id=ot.id
             LEFT JOIN ".CIAS_SUBJECTS." s ON ot.subject_id=s.id
             WHERE r.user_id=%d AND ot.status='published'
             ORDER BY ot.date_conducted DESC",
            intval($user_id)
        ));
    }
}
