<?php
namespace CIAS_LIVE\Services;

defined( 'ABSPATH' ) || exit;

class LiveClassService {

    /**
     * Create a live class — auto-assigns Zoom host, creates Zoom meeting.
     */
    public static function create( array $data, int $created_by ): array|\WP_Error {
        global $wpdb;

        $title        = sanitize_text_field( $data['title'] ?? '' );
        $batch_id     = (int) ( $data['batch_id'] ?? 0 );
        $course_id    = (int) ( $data['course_id'] ?? 0 );
        $teacher_id   = (int) ( $data['teacher_id'] ?? 0 );
        $start_time   = sanitize_text_field( $data['start_time'] ?? '' );
        $duration     = (int) ( $data['duration_mins'] ?? 60 );
        $auto_record  = (bool) ( $data['auto_recording'] ?? true );
        $share_mode   = in_array( $data['share_mode'] ?? 'batch', [ 'batch', 'enrolled', 'link' ], true )
                        ? $data['share_mode'] : 'batch';

        if ( ! $title || ! $batch_id || ! $course_id || ! $teacher_id || ! $start_time ) {
            return new \WP_Error( 'invalid_params', 'title, batch_id, course_id, teacher_id, start_time required.', [ 'status' => 400 ] );
        }

        $end_time = gmdate( 'Y-m-d H:i:s', strtotime( $start_time ) + ( $duration * 60 ) );

        // Auto-assign Zoom host
        $host_id = ZoomHostPool::assign_host( $start_time, $end_time );
        if ( is_wp_error( $host_id ) ) return $host_id;

        // Get valid access token
        $access_token = ZoomHostPool::get_valid_token( $host_id );
        if ( is_wp_error( $access_token ) ) return $access_token;

        // Create Zoom meeting
        $meeting = ZoomHostPool::api_post( '/users/me/meetings', [
            'topic'    => '[CIAS] ' . $title,
            'type'     => 2, // scheduled
            'start_time' => gmdate( 'Y-m-d\TH:i:s', strtotime( $start_time ) ),
            'duration' => $duration,
            'timezone' => 'Asia/Kolkata',
            'settings' => [
                'waiting_room'         => true,
                'join_before_host'     => false,
                'mute_upon_entry'      => true,
                'auto_recording'       => $auto_record ? 'cloud' : 'none',
                'participant_video'    => false,
                'host_video'           => true,
                'approval_type'        => 2, // no registration required
            ],
        ], $access_token );

        if ( is_wp_error( $meeting ) ) return $meeting;

        // Save to DB
        $wpdb->insert( $wpdb->prefix . 'cias_live_classes', [
            'title'           => $title,
            'batch_id'        => $batch_id,
            'course_id'       => $course_id,
            'teacher_id'      => $teacher_id,
            'zoom_host_id'    => $host_id,
            'zoom_meeting_id' => (string) $meeting['id'],
            'join_url'        => $meeting['join_url'],
            'start_time'      => $start_time,
            'end_time'        => $end_time,
            'duration_mins'   => $duration,
            'auto_recording'  => (int) $auto_record,
            'share_mode'      => $share_mode,
            'created_by'      => $created_by,
        ] );

        $class_id = $wpdb->insert_id;

        // Pre-populate attendance records for all enrolled students
        self::seed_attendance( $class_id, $batch_id );

        // Send WhatsApp notification to students
        NotificationService::class_scheduled( $class_id );

        return [
            'class_id'   => $class_id,
            'join_url'   => $meeting['join_url'],
            'start_time' => $start_time,
            'host_email' => self::get_host_email( $host_id ),
        ];
    }

    /**
     * Cancel a live class.
     */
    public static function cancel( int $class_id, int $by ): bool|\WP_Error {
        global $wpdb;

        $class = $wpdb->get_row( $wpdb->prepare(
            "SELECT * FROM {$wpdb->prefix}cias_live_classes WHERE id = %d", $class_id
        ) );

        if ( ! $class ) return new \WP_Error( 'not_found', 'Class not found.', [ 'status' => 404 ] );
        if ( $class->status === 'completed' ) return new \WP_Error( 'invalid', 'Cannot cancel a completed class.' );

        // Delete Zoom meeting
        $access_token = ZoomHostPool::get_valid_token( $class->zoom_host_id );
        if ( ! is_wp_error( $access_token ) ) {
            wp_remote_request( "https://api.zoom.us/v2/meetings/{$class->zoom_meeting_id}", [
                'method'  => 'DELETE',
                'headers' => [ 'Authorization' => 'Bearer ' . $access_token ],
            ] );
        }

        $wpdb->update(
            $wpdb->prefix . 'cias_live_classes',
            [ 'status' => 'cancelled' ],
            [ 'id' => $class_id ]
        );

        NotificationService::class_cancelled( $class_id );
        return true;
    }

    /**
     * Get upcoming classes for a batch.
     */
    public static function get_upcoming( int $batch_id ): array {
        global $wpdb;
        return $wpdb->get_results( $wpdb->prepare(
            "SELECT lc.id, lc.title, lc.join_url, lc.start_time, lc.end_time,
                    lc.duration_mins, lc.status, lc.recording_status, lc.share_mode,
                    u.display_name AS teacher_name
             FROM {$wpdb->prefix}cias_live_classes lc
             LEFT JOIN {$wpdb->users} u ON u.ID = lc.teacher_id
             WHERE lc.batch_id = %d AND lc.status IN ('scheduled','live')
             AND lc.start_time >= NOW()
             ORDER BY lc.start_time ASC",
            $batch_id
        ), ARRAY_A ) ?: [];
    }

    /**
     * Get past classes with recordings for a batch.
     */
    public static function get_past( int $batch_id ): array {
        global $wpdb;
        return $wpdb->get_results( $wpdb->prepare(
            "SELECT lc.id, lc.title, lc.start_time, lc.duration_mins,
                    lc.recording_status, lr.vimeo_video_id,
                    u.display_name AS teacher_name
             FROM {$wpdb->prefix}cias_live_classes lc
             LEFT JOIN {$wpdb->prefix}cias_live_recordings lr ON lr.live_class_id = lc.id
             LEFT JOIN {$wpdb->users} u ON u.ID = lc.teacher_id
             WHERE lc.batch_id = %d AND lc.status = 'completed'
             ORDER BY lc.start_time DESC",
            $batch_id
        ), ARRAY_A ) ?: [];
    }

    // ── Helpers ───────────────────────────────────────────────────────────────

    private static function seed_attendance( int $class_id, int $batch_id ): void {
        global $wpdb;

        // Get all active students in this batch
        $students = $wpdb->get_col( $wpdb->prepare(
            "SELECT student_id FROM {$wpdb->prefix}cias_lms_enrollments
             WHERE batch_id = %d AND status = 'active'",
            $batch_id
        ) );

        foreach ( $students as $student_id ) {
            $wpdb->insert( $wpdb->prefix . 'cias_live_attendance', [
                'live_class_id' => $class_id,
                'student_id'    => $student_id,
                'batch_id'      => $batch_id,
                'status'        => 'absent',
            ] );
        }
    }

    private static function get_host_email( int $host_id ): string {
        global $wpdb;
        return $wpdb->get_var( $wpdb->prepare(
            "SELECT email FROM {$wpdb->prefix}cias_zoom_hosts WHERE id = %d", $host_id
        ) ) ?? '';
    }
}
