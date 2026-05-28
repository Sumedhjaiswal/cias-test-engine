<?php
namespace CIAS_LIVE\Services;

defined( 'ABSPATH' ) || exit;

// ── ShareService ──────────────────────────────────────────────────────────────

class ShareService {

    /**
     * Generate a signed shareable link for a recording.
     * Still watermarked, no download, expires after X hours.
     */
    public static function generate_link( int $class_id, int $created_by, int $expiry_hours = 48 ): array|\WP_Error {
        global $wpdb;

        $class = $wpdb->get_row( $wpdb->prepare(
            "SELECT * FROM {$wpdb->prefix}cias_live_classes WHERE id = %d", $class_id
        ) );

        if ( ! $class ) return new \WP_Error( 'not_found', 'Class not found.', [ 'status' => 404 ] );
        if ( $class->recording_status !== 'published' ) {
            return new \WP_Error( 'not_ready', 'Recording not yet published.', [ 'status' => 400 ] );
        }

        $token      = bin2hex( random_bytes( 24 ) );
        $token_hash = hash( 'sha256', $token );
        $expires_at = gmdate( 'Y-m-d H:i:s', time() + ( $expiry_hours * 3600 ) );

        $wpdb->insert( $wpdb->prefix . 'cias_live_share_links', [
            'live_class_id' => $class_id,
            'token'         => $token,
            'token_hash'    => $token_hash,
            'expires_at'    => $expires_at,
            'created_by'    => $created_by,
        ] );

        return [
            'share_url'  => home_url( "/live-recording/{$token}" ),
            'expires_at' => $expires_at,
        ];
    }

    /**
     * Validate a share token and return class/recording info.
     */
    public static function validate_token( string $token ): array|\WP_Error {
        global $wpdb;

        $token_hash = hash( 'sha256', $token );

        $link = $wpdb->get_row( $wpdb->prepare(
            "SELECT sl.*, lc.title, lr.vimeo_video_id
             FROM {$wpdb->prefix}cias_live_share_links sl
             JOIN {$wpdb->prefix}cias_live_classes lc ON lc.id = sl.live_class_id
             LEFT JOIN {$wpdb->prefix}cias_live_recordings lr ON lr.live_class_id = sl.live_class_id
             WHERE sl.token_hash = %s AND sl.expires_at > NOW()",
            $token_hash
        ) );

        if ( ! $link ) {
            return new \WP_Error( 'invalid_token', 'Link expired or invalid.', [ 'status' => 404 ] );
        }

        // Increment view count
        $wpdb->query( $wpdb->prepare(
            "UPDATE {$wpdb->prefix}cias_live_share_links SET view_count = view_count + 1 WHERE token_hash = %s",
            $token_hash
        ) );

        return [
            'class_id'       => $link->live_class_id,
            'title'          => $link->title,
            'vimeo_video_id' => $link->vimeo_video_id,
            'expires_at'     => $link->expires_at,
        ];
    }
}

// ── AttendanceService ─────────────────────────────────────────────────────────

class AttendanceService {

    /**
     * Mark student as joined (called via Zoom webhook: meeting.participant_joined).
     */
    public static function mark_joined( string $meeting_id, string $zoom_user_email ): void {
        global $wpdb;

        $class = $wpdb->get_row( $wpdb->prepare(
            "SELECT id, batch_id FROM {$wpdb->prefix}cias_live_classes WHERE zoom_meeting_id = %s",
            $meeting_id
        ) );
        if ( ! $class ) return;

        $student = get_user_by( 'email', $zoom_user_email );
        if ( ! $student ) return;

        $wpdb->query( $wpdb->prepare(
            "UPDATE {$wpdb->prefix}cias_live_attendance
             SET joined_at = NOW(), status = 'present'
             WHERE live_class_id = %d AND student_id = %d",
            $class->id, $student->ID
        ) );
    }

    /**
     * Mark student as left and calculate duration.
     */
    public static function mark_left( string $meeting_id, string $zoom_user_email ): void {
        global $wpdb;

        $class = $wpdb->get_row( $wpdb->prepare(
            "SELECT id FROM {$wpdb->prefix}cias_live_classes WHERE zoom_meeting_id = %s", $meeting_id
        ) );
        if ( ! $class ) return;

        $student = get_user_by( 'email', $zoom_user_email );
        if ( ! $student ) return;

        $record = $wpdb->get_row( $wpdb->prepare(
            "SELECT * FROM {$wpdb->prefix}cias_live_attendance
             WHERE live_class_id = %d AND student_id = %d",
            $class->id, $student->ID
        ) );

        if ( ! $record || ! $record->joined_at ) return;

        $duration_mins = (int) round( ( time() - strtotime( $record->joined_at ) ) / 60 );

        $wpdb->update( $wpdb->prefix . 'cias_live_attendance', [
            'left_at'      => current_time( 'mysql', true ),
            'duration_mins' => $duration_mins,
            'status'       => $duration_mins >= 10 ? 'present' : 'partial',
        ], [ 'live_class_id' => $class->id, 'student_id' => $student->ID ] );
    }

    public static function get_by_class( int $class_id ): array {
        global $wpdb;
        return $wpdb->get_results( $wpdb->prepare(
            "SELECT a.student_id, a.status, a.joined_at, a.left_at, a.duration_mins,
                    u.display_name AS student_name
             FROM {$wpdb->prefix}cias_live_attendance a
             JOIN {$wpdb->users} u ON u.ID = a.student_id
             WHERE a.live_class_id = %d
             ORDER BY u.display_name ASC",
            $class_id
        ), ARRAY_A ) ?: [];
    }
}

// ── NotificationService ───────────────────────────────────────────────────────

class NotificationService {

    private const AISENSY_API = 'https://backend.aisensy.com/campaign/t1/api/v2';

    public static function class_scheduled( int $class_id ): void {
        global $wpdb;

        $class = $wpdb->get_row( $wpdb->prepare(
            "SELECT lc.*, u.display_name AS teacher
             FROM {$wpdb->prefix}cias_live_classes lc
             LEFT JOIN {$wpdb->users} u ON u.ID = lc.teacher_id
             WHERE lc.id = %d", $class_id
        ) );
        if ( ! $class ) return;

        $students = self::get_batch_student_phones( $class->batch_id );
        $date     = date( 'd M Y, h:i A', strtotime( $class->start_time ) );

        foreach ( $students as $phone ) {
            self::send( $phone, 'cias_live_scheduled', [
                $class->title, $date, $class->teacher ?? 'Faculty', $class->join_url
            ] );
        }
    }

    public static function class_cancelled( int $class_id ): void {
        global $wpdb;
        $class    = $wpdb->get_row( $wpdb->prepare(
            "SELECT * FROM {$wpdb->prefix}cias_live_classes WHERE id = %d", $class_id
        ) );
        if ( ! $class ) return;

        $students = self::get_batch_student_phones( $class->batch_id );
        foreach ( $students as $phone ) {
            self::send( $phone, 'cias_live_cancelled', [ $class->title ] );
        }
    }

    public static function recording_ready( int $class_id, int $batch_id ): void {
        global $wpdb;
        $class    = $wpdb->get_row( $wpdb->prepare(
            "SELECT title FROM {$wpdb->prefix}cias_live_classes WHERE id = %d", $class_id
        ) );
        if ( ! $class ) return;

        $students = self::get_batch_student_phones( $batch_id );
        $app_url  = home_url( '/student/courses' );

        foreach ( $students as $phone ) {
            self::send( $phone, 'cias_recording_ready', [ $class->title, $app_url ] );
        }
    }

    /**
     * Send reminders 1 hour before class — called by cron.
     */
    public static function send_reminders(): void {
        global $wpdb;

        // Classes starting in 55–65 minutes not yet reminded
        $classes = $wpdb->get_results(
            "SELECT lc.id, lc.title, lc.batch_id, lc.join_url, lc.start_time
             FROM {$wpdb->prefix}cias_live_classes lc
             WHERE lc.status = 'scheduled'
             AND lc.start_time BETWEEN DATE_ADD(NOW(), INTERVAL 55 MINUTE)
                                   AND DATE_ADD(NOW(), INTERVAL 65 MINUTE)
             AND NOT EXISTS (
                SELECT 1 FROM {$wpdb->prefix}cias_live_attendance
                WHERE live_class_id = lc.id AND notified = 1 LIMIT 1
             )"
        );

        foreach ( $classes as $class ) {
            $students = self::get_batch_student_phones( $class->batch_id );
            $time     = date( 'h:i A', strtotime( $class->start_time ) );

            foreach ( $students as $phone ) {
                self::send( $phone, 'cias_live_reminder', [ $class->title, $time, $class->join_url ] );
            }

            // Mark as reminded
            $wpdb->query( $wpdb->prepare(
                "UPDATE {$wpdb->prefix}cias_live_attendance SET notified = 1 WHERE live_class_id = %d",
                $class->id
            ) );
        }
    }

    private static function get_batch_student_phones( int $batch_id ): array {
        global $wpdb;
        return $wpdb->get_col( $wpdb->prepare(
            "SELECT um.meta_value
             FROM {$wpdb->prefix}cias_lms_enrollments e
             JOIN {$wpdb->usermeta} um ON um.user_id = e.student_id AND um.meta_key = 'phone'
             WHERE e.batch_id = %d AND e.status = 'active'",
            $batch_id
        ) ) ?: [];
    }

    private static function send( string $phone, string $campaign, array $params ): void {
        $api_key = defined( 'CIAS_AISENSY_API_KEY' ) ? CIAS_AISENSY_API_KEY : '';
        if ( ! $api_key ) return;

        $phone = preg_replace( '/\D/', '', $phone );
        if ( ! str_starts_with( $phone, '91' ) ) $phone = '91' . $phone;

        wp_remote_post( self::AISENSY_API, [
            'timeout' => 8,
            'headers' => [ 'Content-Type' => 'application/json' ],
            'body'    => wp_json_encode( [
                'apiKey'         => $api_key,
                'campaignName'   => $campaign,
                'destination'    => $phone,
                'userName'       => 'CIAS',
                'templateParams' => $params,
            ] ),
        ] );
    }
}
