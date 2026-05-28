<?php
namespace CIAS_LMS\DB;

defined( 'ABSPATH' ) || exit;

class Schema {

    public static function install(): void {
        global $wpdb;
        $charset = $wpdb->get_charset_collate();
        require_once ABSPATH . 'wp-admin/includes/upgrade.php';

        // Courses
        dbDelta( "CREATE TABLE IF NOT EXISTS {$wpdb->prefix}cias_lms_courses (
            id            BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            title         VARCHAR(255)    NOT NULL,
            slug          VARCHAR(255)    NOT NULL UNIQUE,
            description   TEXT,
            thumbnail_url VARCHAR(500),
            status        ENUM('draft','published','archived') NOT NULL DEFAULT 'draft',
            access_type   ENUM('free','enrolled') NOT NULL DEFAULT 'enrolled',
            created_by    BIGINT UNSIGNED NOT NULL,
            created_at    DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at    DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            KEY idx_status (status)
        ) $charset;" );

        // Sections (chapters inside a course)
        dbDelta( "CREATE TABLE IF NOT EXISTS {$wpdb->prefix}cias_lms_sections (
            id          BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            course_id   BIGINT UNSIGNED NOT NULL,
            title       VARCHAR(255)    NOT NULL,
            sort_order  SMALLINT UNSIGNED NOT NULL DEFAULT 0,
            PRIMARY KEY (id),
            KEY idx_course (course_id)
        ) $charset;" );

        // Lessons — videos, PDFs, quizzes, live classes
        dbDelta( "CREATE TABLE IF NOT EXISTS {$wpdb->prefix}cias_lms_lessons (
            id              BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            section_id      BIGINT UNSIGNED NOT NULL,
            course_id       BIGINT UNSIGNED NOT NULL,
            title           VARCHAR(255)    NOT NULL,
            type            ENUM('video','pdf','quiz','live') NOT NULL,
            vimeo_video_id  VARCHAR(64)     DEFAULT NULL,
            r2_pdf_key      VARCHAR(500)    DEFAULT NULL,
            zoom_meeting_id VARCHAR(64)     DEFAULT NULL,
            duration_secs   INT UNSIGNED    DEFAULT 0,
            sort_order      SMALLINT UNSIGNED NOT NULL DEFAULT 0,
            is_preview      TINYINT(1)      NOT NULL DEFAULT 0,
            created_at      DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            KEY idx_section  (section_id),
            KEY idx_course   (course_id)
        ) $charset;" );

        // Enrollments
        dbDelta( "CREATE TABLE IF NOT EXISTS {$wpdb->prefix}cias_lms_enrollments (
            id          BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            student_id  BIGINT UNSIGNED NOT NULL,
            course_id   BIGINT UNSIGNED NOT NULL,
            enrolled_at DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
            expires_at  DATETIME        DEFAULT NULL,
            status      ENUM('active','expired','revoked') NOT NULL DEFAULT 'active',
            PRIMARY KEY (id),
            UNIQUE KEY uq_student_course (student_id, course_id),
            KEY idx_student (student_id)
        ) $charset;" );

        // Progress — per lesson per student
        dbDelta( "CREATE TABLE IF NOT EXISTS {$wpdb->prefix}cias_lms_progress (
            id            BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            student_id    BIGINT UNSIGNED NOT NULL,
            lesson_id     BIGINT UNSIGNED NOT NULL,
            course_id     BIGINT UNSIGNED NOT NULL,
            watch_secs    INT UNSIGNED    NOT NULL DEFAULT 0,
            completed     TINYINT(1)      NOT NULL DEFAULT 0,
            completed_at  DATETIME        DEFAULT NULL,
            last_activity DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            UNIQUE KEY uq_student_lesson (student_id, lesson_id),
            KEY idx_student_course (student_id, course_id)
        ) $charset;" );

        // Security — video/PDF session fingerprint log
        dbDelta( "CREATE TABLE IF NOT EXISTS {$wpdb->prefix}cias_lms_sessions (
            id          BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            student_id  BIGINT UNSIGNED NOT NULL,
            lesson_id   BIGINT UNSIGNED NOT NULL,
            token_hash  VARCHAR(64)     NOT NULL,
            ip_address  VARCHAR(45)     NOT NULL,
            user_agent  VARCHAR(500)    NOT NULL,
            started_at  DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
            expires_at  DATETIME        NOT NULL,
            revoked     TINYINT(1)      NOT NULL DEFAULT 0,
            event_log   JSON            DEFAULT NULL,
            PRIMARY KEY (id),
            KEY idx_student   (student_id),
            KEY idx_token     (token_hash),
            KEY idx_expires   (expires_at)
        ) $charset;" );

        // Security events (screenshot attempts, dev tools, visibility loss)
        dbDelta( "CREATE TABLE IF NOT EXISTS {$wpdb->prefix}cias_lms_security_events (
            id         BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            student_id BIGINT UNSIGNED NOT NULL,
            lesson_id  BIGINT UNSIGNED NOT NULL,
            session_id BIGINT UNSIGNED NOT NULL,
            event_type VARCHAR(64)     NOT NULL,
            metadata   JSON            DEFAULT NULL,
            occurred_at DATETIME       NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            KEY idx_student (student_id),
            KEY idx_type    (event_type)
        ) $charset;" );

        // Zoom live class schedule
        dbDelta( "CREATE TABLE IF NOT EXISTS {$wpdb->prefix}cias_lms_live_schedule (
            id              BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            lesson_id       BIGINT UNSIGNED NOT NULL,
            zoom_meeting_id VARCHAR(64)     NOT NULL,
            join_url        VARCHAR(500)    NOT NULL,
            start_time      DATETIME        NOT NULL,
            duration_mins   SMALLINT UNSIGNED NOT NULL DEFAULT 60,
            created_at      DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            KEY idx_lesson  (lesson_id),
            KEY idx_start   (start_time)
        ) $charset;" );
    }
}
