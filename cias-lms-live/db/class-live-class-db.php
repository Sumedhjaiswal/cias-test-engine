<?php
namespace CIAS_LIVE\DB;

defined( 'ABSPATH' ) || exit;

class LiveClassDB {

    public static function create_table(): void {
        global $wpdb;
        $table   = $wpdb->prefix . 'cias_live_classes';
        $charset = $wpdb->get_charset_collate();

        $sql = "CREATE TABLE IF NOT EXISTS {$table} (
            id              BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            title           VARCHAR(255)    NOT NULL,
            batch_id        BIGINT UNSIGNED NOT NULL DEFAULT 0,
            batch_name      VARCHAR(255)    NOT NULL DEFAULT '',
            teacher_id      BIGINT UNSIGNED NOT NULL DEFAULT 0,
            teacher_name    VARCHAR(255)    NOT NULL DEFAULT '',
            host_id         BIGINT UNSIGNED NOT NULL DEFAULT 0,
            zoom_account    VARCHAR(255)    NOT NULL DEFAULT '',
            zoom_meeting_id VARCHAR(100)    NOT NULL DEFAULT '',
            zoom_session_id VARCHAR(100)    NOT NULL DEFAULT '',
            join_url        TEXT,
            start_url       TEXT,
            start_time      DATETIME        NOT NULL,
            end_time        DATETIME        NOT NULL,
            duration        SMALLINT UNSIGNED NOT NULL DEFAULT 60,
            auto_recording  TINYINT(1)      NOT NULL DEFAULT 0,
            host_video      TINYINT(1)      NOT NULL DEFAULT 1,
            mute_on_entry   TINYINT(1)      NOT NULL DEFAULT 1,
            status          VARCHAR(20)     NOT NULL DEFAULT 'scheduled',
            recording_url   TEXT,
            created_by      BIGINT UNSIGNED NOT NULL DEFAULT 0,
            created_at      DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at      DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            KEY idx_batch    (batch_id),
            KEY idx_teacher  (teacher_id),
            KEY idx_host     (host_id),
            KEY idx_status   (status),
            KEY idx_start    (start_time)
        ) {$charset};";

        require_once ABSPATH . 'wp-admin/includes/upgrade.php';
        dbDelta( $sql );
    }

    public static function get_classes( array $args = [] ): array {
        global $wpdb;
        $table = $wpdb->prefix . 'cias_live_classes';

        $where  = [ '1=1' ];
        $params = [];

        if ( ! empty( $args['status'] ) ) {
            $where[]  = 'status = %s';
            $params[] = $args['status'];
        }
        if ( ! empty( $args['batch_id'] ) ) {
            $where[]  = 'batch_id = %d';
            $params[] = $args['batch_id'];
        }
        if ( ! empty( $args['date_from'] ) ) {
            $where[]  = 'start_time >= %s';
            $params[] = $args['date_from'];
        }
        if ( ! empty( $args['date_to'] ) ) {
            $where[]  = 'start_time <= %s';
            $params[] = $args['date_to'] . ' 23:59:59';
        }

        $where_sql = implode( ' AND ', $where );
        $order     = sanitize_sql_orderby( $args['orderby'] ?? 'start_time ASC' ) ?: 'start_time ASC';
        $limit     = isset( $args['limit'] ) ? absint( $args['limit'] ) : 50;
        $offset    = isset( $args['offset'] ) ? absint( $args['offset'] ) : 0;

        $sql = "SELECT * FROM {$table} WHERE {$where_sql} ORDER BY {$order} LIMIT %d OFFSET %d";
        $params[] = $limit;
        $params[] = $offset;

        return $wpdb->get_results( $wpdb->prepare( $sql, ...$params ), ARRAY_A ) ?: [];
    }

    public static function get_class( int $id ): ?array {
        global $wpdb;
        $table = $wpdb->prefix . 'cias_live_classes';
        $row   = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table} WHERE id = %d", $id ), ARRAY_A );
        return $row ?: null;
    }

    public static function insert( array $data ): int|false {
        global $wpdb;
        $result = $wpdb->insert( $wpdb->prefix . 'cias_live_classes', $data );
        return $result ? $wpdb->insert_id : false;
    }

    public static function update( int $id, array $data ): bool {
        global $wpdb;
        return (bool) $wpdb->update( $wpdb->prefix . 'cias_live_classes', $data, [ 'id' => $id ] );
    }

    public static function count( array $args = [] ): int {
        global $wpdb;
        $table  = $wpdb->prefix . 'cias_live_classes';
        $where  = [ '1=1' ];
        $params = [];

        if ( ! empty( $args['status'] ) ) {
            $where[]  = 'status = %s';
            $params[] = $args['status'];
        }

        $where_sql = implode( ' AND ', $where );
        $sql = "SELECT COUNT(*) FROM {$table} WHERE {$where_sql}";

        return (int) ( empty( $params )
            ? $wpdb->get_var( $sql )
            : $wpdb->get_var( $wpdb->prepare( $sql, ...$params ) ) );
    }
}
