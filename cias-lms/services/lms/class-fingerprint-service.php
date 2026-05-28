<?php
namespace CIAS_LMS\Services;

defined( 'ABSPATH' ) || exit;

/**
 * FingerprintService
 *
 * Logs all security events — screenshot attempts, screen recording detection,
 * dev tools open, visibility loss, IP mismatch, unusual seek patterns.
 *
 * If a student trips too many events, auto-revoke their session.
 */
class FingerprintService {

    // Event types reported by client JS
    private const ALLOWED_EVENTS = [
        'visibility_hidden',   // tab hidden / screen recorder likely
        'devtools_open',       // dev tools detected
        'right_click',         // right click attempt
        'keyboard_shortcut',   // screenshot shortcut (PrtSc, Cmd+Shift+4, etc.)
        'focus_lost',          // window lost focus
        'screenshot_attempt',  // generic
        'unusual_seek',        // seek jumped forward unusually fast
        'ip_mismatch',         // server-side: IP changed mid-session
        'fullscreen_exit',     // exited fullscreen
    ];

    private const AUTO_REVOKE_THRESHOLD = 5; // events before session is revoked

    public static function log_event(
        int    $student_id,
        int    $lesson_id,
        string $event_type,
        mixed  $metadata = null
    ): void {
        global $wpdb;

        if ( ! in_array( $event_type, self::ALLOWED_EVENTS, true ) ) return;

        // Find the active session
        $session = $wpdb->get_row( $wpdb->prepare(
            "SELECT id FROM {$wpdb->prefix}cias_lms_sessions
             WHERE student_id = %d AND lesson_id = %d AND revoked = 0
             AND expires_at > NOW()
             ORDER BY started_at DESC LIMIT 1",
            $student_id, $lesson_id
        ) );

        $session_id = $session ? $session->id : 0;

        $wpdb->insert( $wpdb->prefix . 'cias_lms_security_events', [
            'student_id'  => $student_id,
            'lesson_id'   => $lesson_id,
            'session_id'  => $session_id,
            'event_type'  => $event_type,
            'metadata'    => wp_json_encode( $metadata ),
            'occurred_at' => current_time( 'mysql', true ),
        ] );

        // Auto-revoke if threshold exceeded
        if ( $session_id ) {
            $count = (int) $wpdb->get_var( $wpdb->prepare(
                "SELECT COUNT(*) FROM {$wpdb->prefix}cias_lms_security_events
                 WHERE session_id = %d",
                $session_id
            ) );

            if ( $count >= self::AUTO_REVOKE_THRESHOLD ) {
                self::revoke_session( $session_id );
            }
        }
    }

    public static function revoke_session( int $session_id ): void {
        global $wpdb;
        $wpdb->update(
            $wpdb->prefix . 'cias_lms_sessions',
            [ 'revoked' => 1 ],
            [ 'id'      => $session_id ]
        );
    }

    /**
     * Get security summary for a student — used in teacher dashboard.
     */
    public static function get_student_summary( int $student_id ): array {
        global $wpdb;
        return $wpdb->get_results( $wpdb->prepare(
            "SELECT event_type, COUNT(*) as count
             FROM {$wpdb->prefix}cias_lms_security_events
             WHERE student_id = %d
             GROUP BY event_type
             ORDER BY count DESC",
            $student_id
        ), ARRAY_A );
    }
}
