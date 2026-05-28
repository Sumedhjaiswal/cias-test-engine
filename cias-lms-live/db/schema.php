<?php
namespace CIAS_LIVE\DB;

defined( 'ABSPATH' ) || exit;

class Schema {

    public static function install(): void {
        global $wpdb;
        $charset = $wpdb->get_charset_collate();
        require_once ABSPATH . 'wp-admin/includes/upgrade.php';

        // Zoom host pool
        dbDelta( "CREATE TABLE IF NOT EXISTS {$wpdb->prefix}cias_zoom_hosts (
            id               BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            display_name     VARCHAR(255)    NOT NULL,
            email            VARCHAR(255)    NOT NULL,
            zoom_user_id     VARCHAR(64)     NOT NULL,
            access_token     TEXT            NOT NULL,
            refresh_token    TEXT            NOT NULL,
            token_expires_at DATETIME        NOT NULL,
            status           ENUM('active','locked','disconnected') NOT NULL DEFAULT 'active',
            connected_by     BIGINT UNSIGNED NOT NULL,
            created_at       DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            UNIQUE KEY uq_email (email),
            KEY idx_status (status)
        ) $charset;" );

        // Live classes
        dbDelta( "CREATE TABLE IF NOT EXISTS {$wpdb->prefix}cias_live_classes (
            id               BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            title            VARCHAR(255)    NOT NULL,
            batch_id         BIGINT UNSIGNED NOT NULL,
            course_id        BIGINT UNSIGNED NOT NULL,
            teacher_id       BIGINT UNSIGNED NOT NULL,
            zoom_host_id     BIGINT UNSIGNED NOT NULL,
            zoom_meeting_id  VARCHAR(64)     NOT NULL,
            join_url         VARCHAR(500)    NOT NULL,
            start_time       DATETIME        NOT NULL,
            end_time         DATETIME        NOT NULL,
            duration_mins    SMALLINT UNSIGNED NOT NULL DEFAULT 60,
            status           ENUM('scheduled','live','completed','cancelled') NOT NULL DEFAULT 'scheduled',
            recording_status ENUM('none','pending','processing','published','failed') NOT NULL DEFAULT 'none',
            share_mode       ENUM('batch','enrolled','link') NOT NULL DEFAULT 'batch',
            auto_recording   TINYINT(1)      NOT NULL DEFAULT 1,
            created_by       BIGINT UNSIGNED NOT NULL,
            created_at       DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            KEY idx_batch      (batch_id),
            KEY idx_host       (zoom_host_id),
            KEY idx_start      (start_time),
            KEY idx_status     (status)
        ) $charset;" );

        // Recordings
        dbDelta( "CREATE TABLE IF NOT EXISTS {$wpdb->prefix}cias_live_recordings (
            id                BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            live_class_id     BIGINT UNSIGNED NOT NULL,
            zoom_recording_id VARCHAR(64)     DEFAULT NULL,
            zoom_download_url VARCHAR(500)    DEFAULT NULL,
            r2_temp_key       VARCHAR(500)    DEFAULT NULL,
            vimeo_video_id    VARCHAR(64)     DEFAULT NULL,
            upload_status     ENUM('pending','downloading','uploading','published','failed') NOT NULL DEFAULT 'pending',
            retry_count       TINYINT UNSIGNED NOT NULL DEFAULT 0,
            error_message     TEXT            DEFAULT NULL,
            published_at      DATETIME        DEFAULT NULL,
            created_at        DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at        DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            KEY idx_class     (live_class_id),
            KEY idx_status    (upload_status)
        ) $charset;" );

        // Live attendance
        dbDelta( "CREATE TABLE IF NOT EXISTS {$wpdb->prefix}cias_live_attendance (
            id             BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            live_class_id  BIGINT UNSIGNED NOT NULL,
            student_id     BIGINT UNSIGNED NOT NULL,
            batch_id       BIGINT UNSIGNED NOT NULL,
            joined_at      DATETIME        DEFAULT NULL,
            left_at        DATETIME        DEFAULT NULL,
            duration_mins  SMALLINT UNSIGNED NOT NULL DEFAULT 0,
            status         ENUM('present','absent','partial') NOT NULL DEFAULT 'absent',
            notified       TINYINT(1)      NOT NULL DEFAULT 0,
            PRIMARY KEY (id),
            UNIQUE KEY uq_class_student (live_class_id, student_id),
            KEY idx_student (student_id),
            KEY idx_class   (live_class_id)
        ) $charset;" );

        // Shareable links
        dbDelta( "CREATE TABLE IF NOT EXISTS {$wpdb->prefix}cias_live_share_links (
            id            BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            live_class_id BIGINT UNSIGNED NOT NULL,
            token         VARCHAR(64)     NOT NULL,
            token_hash    VARCHAR(64)     NOT NULL,
            expires_at    DATETIME        NOT NULL,
            max_views     SMALLINT UNSIGNED DEFAULT NULL,
            view_count    SMALLINT UNSIGNED NOT NULL DEFAULT 0,
            created_by    BIGINT UNSIGNED NOT NULL,
            created_at    DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            UNIQUE KEY uq_token (token_hash),
            KEY idx_class (live_class_id)
        ) $charset;" );

        // Zoom OAuth state (for OAuth flow)
        dbDelta( "CREATE TABLE IF NOT EXISTS {$wpdb->prefix}cias_zoom_oauth_state (
            id         BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            state      VARCHAR(64)     NOT NULL,
            user_id    BIGINT UNSIGNED NOT NULL,
            expires_at DATETIME        NOT NULL,
            PRIMARY KEY (id),
            UNIQUE KEY uq_state (state)
        ) $charset;" );
    }
}
