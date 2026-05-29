<?php
namespace CIAS_LIVE\Services;

defined( 'ABSPATH' ) || exit;

use CIAS_LIVE\DB\LiveClassDB;

class LiveClassService {

    /**
     * Create a new live class — auto-assigns a free Zoom host
     */
    public static function create( array $data ): array {

        // 1. Validate required fields
        $required = [ 'title', 'batch_id', 'teacher_id', 'start_time', 'end_time' ];
        foreach ( $required as $field ) {
            if ( empty( $data[ $field ] ) ) {
                return [ 'success' => false, 'message' => "Missing required field: {$field}" ];
            }
        }

        // 2. Parse times
        $start = strtotime( $data['start_time'] );
        $end   = strtotime( $data['end_time'] );

        if ( ! $start || ! $end || $end <= $start ) {
            return [ 'success' => false, 'message' => 'Invalid start or end time.' ];
        }

        $duration = (int) round( ( $end - $start ) / 60 ); // minutes

        // 3. Get an available Zoom host
        $host = ZoomHostPool::get_available_host();
        if ( ! $host ) {
            return [
                'success' => false,
                'message' => 'All Zoom accounts are currently in use. Please choose a different time or wait for an ongoing class to finish.',
            ];
        }

        // 4. Check for time conflict on this host
        if ( self::host_has_conflict( $host['id'], $data['start_time'], $data['end_time'] ) ) {
            return [
                'success' => false,
                'message' => 'Selected Zoom account has a conflicting class at this time.',
            ];
        }

        // 5. Get batch + teacher names for denormalized storage
        $batch_name   = self::get_batch_name( (int) $data['batch_id'] );
        $teacher_name = self::get_teacher_name( (int) $data['teacher_id'] );

        // 6. Create Zoom meeting
        $zoom_result = ZoomHostPool::create_meeting( $host['id'], [
            'topic'          => sanitize_text_field( $data['title'] ),
            'start_time'     => gmdate( 'Y-m-d\TH:i:s', $start ),
            'duration'       => $duration,
            'auto_recording' => ! empty( $data['auto_recording'] ) ? 'cloud' : 'none',
            'host_video'     => ! empty( $data['host_video'] ),
            'mute_on_entry'  => ! empty( $data['mute_on_entry'] ) !== false,
        ] );

        if ( is_wp_error( $zoom_result ) ) {
            return [ 'success' => false, 'message' => $zoom_result->get_error_message() ];
        }

        // 7. Save to DB (lock happens only when class goes live, not on schedule)
        $class_id = LiveClassDB::insert( [
            'title'           => sanitize_text_field( $data['title'] ),
            'batch_id'        => (int) $data['batch_id'],
            'batch_name'      => $batch_name,
            'teacher_id'      => (int) $data['teacher_id'],
            'teacher_name'    => $teacher_name,
            'host_id'         => (int) $host['id'],
            'zoom_account'    => $host['display_name'] . ' (' . $host['email'] . ')',
            'zoom_meeting_id' => (string) $zoom_result['id'],
            'zoom_session_id' => (string) $zoom_result['id'],
            'join_url'        => $zoom_result['join_url'],
            'start_url'       => $zoom_result['start_url'],
            'start_time'      => gmdate( 'Y-m-d H:i:s', $start ),
            'end_time'        => gmdate( 'Y-m-d H:i:s', $end ),
            'duration'        => $duration,
            'auto_recording'  => ! empty( $data['auto_recording'] ) ? 1 : 0,
            'host_video'      => ! empty( $data['host_video'] ) ? 1 : 0,
            'mute_on_entry'   => isset( $data['mute_on_entry'] ) ? 1 : 0,
            'status'          => 'scheduled',
            'created_by'      => get_current_user_id(),
        ] );

        if ( ! $class_id ) {
            return [ 'success' => false, 'message' => 'Failed to save class to database.' ];
        }

        return [
            'success'  => true,
            'class_id' => $class_id,
            'message'  => 'Class scheduled successfully.',
            'data'     => LiveClassDB::get_class( $class_id ),
        ];
    }

    /**
     * Update an existing class (only if not yet started)
     */
    public static function update( int $class_id, array $data ): array {
        $class = LiveClassDB::get_class( $class_id );
        if ( ! $class ) {
            return [ 'success' => false, 'message' => 'Class not found.' ];
        }
        if ( in_array( $class['status'], [ 'live', 'completed', 'cancelled' ] ) ) {
            return [ 'success' => false, 'message' => 'Cannot edit a class that is live, completed or cancelled.' ];
        }

        $update = [];
        if ( ! empty( $data['title'] ) )       $update['title']        = sanitize_text_field( $data['title'] );
        if ( ! empty( $data['batch_id'] ) ) {
            $update['batch_id']   = (int) $data['batch_id'];
            $update['batch_name'] = self::get_batch_name( (int) $data['batch_id'] );
        }
        if ( ! empty( $data['teacher_id'] ) ) {
            $update['teacher_id']   = (int) $data['teacher_id'];
            $update['teacher_name'] = self::get_teacher_name( (int) $data['teacher_id'] );
        }
        if ( ! empty( $data['start_time'] ) )  $update['start_time']   = $data['start_time'];
        if ( ! empty( $data['end_time'] ) )     $update['end_time']     = $data['end_time'];
        if ( isset( $data['auto_recording'] ) ) $update['auto_recording'] = (int) $data['auto_recording'];
        if ( isset( $data['host_video'] ) )     $update['host_video']   = (int) $data['host_video'];
        if ( isset( $data['mute_on_entry'] ) )  $update['mute_on_entry'] = (int) $data['mute_on_entry'];

        LiveClassDB::update( $class_id, $update );

        return [ 'success' => true, 'message' => 'Class updated.', 'data' => LiveClassDB::get_class( $class_id ) ];
    }

    /**
     * Cancel a class — unlock the Zoom host
     */
    public static function cancel( int $class_id ): array {
        $class = LiveClassDB::get_class( $class_id );
        if ( ! $class ) {
            return [ 'success' => false, 'message' => 'Class not found.' ];
        }
        if ( $class['status'] === 'cancelled' ) {
            return [ 'success' => false, 'message' => 'Class already cancelled.' ];
        }

        // Delete from Zoom
        if ( ! empty( $class['zoom_meeting_id'] ) ) {
            ZoomHostPool::delete_meeting( (int) $class['host_id'], $class['zoom_meeting_id'] );
        }

        // Unlock host
        if ( ! empty( $class['host_id'] ) ) {
            ZoomHostPool::unlock_host( (int) $class['host_id'] );
        }

        LiveClassDB::update( $class_id, [ 'status' => 'cancelled' ] );

        return [ 'success' => true, 'message' => 'Class cancelled and Zoom host released.' ];
    }

    /**
     * Get classes for student app — only their batch, upcoming + today
     */
    public static function get_for_student( int $user_id ): array {
        global $wpdb;

        // Get student's batch IDs from cias_enrollments
        $batch_ids = $wpdb->get_col( $wpdb->prepare(
            "SELECT batch_id FROM {$wpdb->prefix}cias_enrollments WHERE user_id = %d AND status = 'active'",
            $user_id
        ) );

        if ( empty( $batch_ids ) ) return [];

        $placeholders = implode( ',', array_fill( 0, count( $batch_ids ), '%d' ) );
        $table        = $wpdb->prefix . 'cias_live_classes';
        $now          = current_time( 'mysql' );

        return $wpdb->get_results( $wpdb->prepare(
            "SELECT id, title, batch_name, teacher_name, zoom_account, join_url,
                    start_time, end_time, duration, status, recording_url
             FROM {$table}
             WHERE batch_id IN ({$placeholders})
               AND status IN ('scheduled','live')
               AND end_time >= %s
             ORDER BY start_time ASC
             LIMIT 20",
            ...[...$batch_ids, $now]
        ), ARRAY_A ) ?: [];
    }

    // ── Helpers ────────────────────────────────────────────────────────────

    private static function get_batch_name( int $batch_id ): string {
        global $wpdb;
        return (string) $wpdb->get_var( $wpdb->prepare(
            "SELECT name FROM {$wpdb->prefix}cias_batches WHERE id = %d", $batch_id
        ) );
    }

    private static function get_teacher_name( int $user_id ): string {
        $user = get_userdata( $user_id );
        return $user ? $user->display_name : '';
    }

    private static function host_has_conflict( int $host_id, string $start, string $end ): bool {
        global $wpdb;
        $table = $wpdb->prefix . 'cias_live_classes';
        $count = $wpdb->get_var( $wpdb->prepare(
            "SELECT COUNT(*) FROM {$table}
             WHERE host_id = %d
               AND status IN ('scheduled','live')
               AND start_time < %s
               AND end_time   > %s",
            $host_id, $end, $start
        ) );
        return (int) $count > 0;
    }
}
