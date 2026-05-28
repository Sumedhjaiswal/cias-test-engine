<?php
namespace CIAS_LIVE\API;

defined( 'ABSPATH' ) || exit;

use CIAS_LIVE\Services\ZoomHostPool;
use CIAS_LIVE\Services\LiveClassService;
use CIAS_LIVE\Services\RecordingPipeline;
use CIAS_LIVE\Services\ShareService;
use CIAS_LIVE\Services\AttendanceService;

class RestController {

    private const NS = CIAS_LIVE_API_NS;
    private const B  = CIAS_LIVE_API_BASE;

    public static function register_routes(): void {
        $c = new self();

        // Zoom OAuth
        register_rest_route( self::NS, '/' . self::B . '/zoom-connect',    [ 'methods' => 'GET',  'callback' => [ $c, 'zoom_connect'    ], 'permission_callback' => [ $c, 'require_admin'   ] ] );
        register_rest_route( self::NS, '/' . self::B . '/zoom-callback',   [ 'methods' => 'GET',  'callback' => [ $c, 'zoom_callback'   ], 'permission_callback' => '__return_true'           ] );
        register_rest_route( self::NS, '/' . self::B . '/zoom-hosts',      [ 'methods' => 'GET',  'callback' => [ $c, 'zoom_hosts'      ], 'permission_callback' => [ $c, 'require_admin'   ] ] );
        register_rest_route( self::NS, '/' . self::B . '/zoom-hosts/(?P<id>\d+)/lock',   [ 'methods' => 'POST', 'callback' => [ $c, 'lock_host'   ], 'permission_callback' => [ $c, 'require_admin' ] ] );
        register_rest_route( self::NS, '/' . self::B . '/zoom-hosts/(?P<id>\d+)/unlock', [ 'methods' => 'POST', 'callback' => [ $c, 'unlock_host' ], 'permission_callback' => [ $c, 'require_admin' ] ] );

        // Live classes — admin/teacher
        register_rest_route( self::NS, '/' . self::B . '/classes',                      [ 'methods' => 'POST', 'callback' => [ $c, 'create_class'  ], 'permission_callback' => [ $c, 'require_teacher' ] ] );
        register_rest_route( self::NS, '/' . self::B . '/classes/(?P<id>\d+)/cancel',   [ 'methods' => 'POST', 'callback' => [ $c, 'cancel_class'  ], 'permission_callback' => [ $c, 'require_teacher' ] ] );
        register_rest_route( self::NS, '/' . self::B . '/classes/(?P<id>\d+)/share',    [ 'methods' => 'POST', 'callback' => [ $c, 'share_class'   ], 'permission_callback' => [ $c, 'require_teacher' ] ] );
        register_rest_route( self::NS, '/' . self::B . '/classes/(?P<id>\d+)/attendance',[ 'methods' => 'GET', 'callback' => [ $c, 'get_attendance'], 'permission_callback' => [ $c, 'require_teacher' ] ] );

        // Student endpoints
        register_rest_route( self::NS, '/' . self::B . '/my-classes',   [ 'methods' => 'GET',  'callback' => [ $c, 'my_classes'  ], 'permission_callback' => [ $c, 'require_student' ] ] );
        register_rest_route( self::NS, '/' . self::B . '/join/(?P<id>\d+)', [ 'methods' => 'GET', 'callback' => [ $c, 'join_class'  ], 'permission_callback' => [ $c, 'require_student' ] ] );

        // Zoom webhooks — public, verified by signature
        register_rest_route( self::NS, '/' . self::B . '/zoom-webhook', [ 'methods' => 'POST', 'callback' => [ $c, 'zoom_webhook' ], 'permission_callback' => '__return_true' ] );

        // Share link validation — public
        register_rest_route( self::NS, '/' . self::B . '/share/(?P<token>[a-f0-9]+)', [ 'methods' => 'GET', 'callback' => [ $c, 'validate_share' ], 'permission_callback' => '__return_true' ] );
    }

    // ── Permission callbacks ──────────────────────────────────────────────────

    public function require_admin(): bool|\WP_Error {
        if ( ! current_user_can( 'manage_options' ) ) {
            return new \WP_Error( 'forbidden', 'Admin access required.', [ 'status' => 403 ] );
        }
        return true;
    }

    public function require_teacher(): bool|\WP_Error {
        if ( ! is_user_logged_in() ) return new \WP_Error( 'unauthorized', 'Login required.', [ 'status' => 401 ] );
        if ( ! current_user_can( 'cias_teacher' ) && ! current_user_can( 'manage_options' ) ) {
            return new \WP_Error( 'forbidden', 'Teacher access required.', [ 'status' => 403 ] );
        }
        return true;
    }

    public function require_student(): bool|\WP_Error {
        if ( ! is_user_logged_in() ) return new \WP_Error( 'unauthorized', 'Login required.', [ 'status' => 401 ] );
        if ( ! current_user_can( 'cias_student' ) && ! current_user_can( 'manage_options' ) ) {
            return new \WP_Error( 'forbidden', 'Student access required.', [ 'status' => 403 ] );
        }
        return true;
    }

    // ── Zoom OAuth ────────────────────────────────────────────────────────────

    public function zoom_connect(): \WP_REST_Response {
        return rest_ensure_response( [ 'redirect_url' => ZoomHostPool::get_oauth_url() ] );
    }

    public function zoom_callback( \WP_REST_Request $req ): void {
        $code  = sanitize_text_field( $req->get_param( 'code' )  ?? '' );
        $state = sanitize_text_field( $req->get_param( 'state' ) ?? '' );

        $result = ZoomHostPool::handle_oauth_callback( $code, $state );

        if ( is_wp_error( $result ) ) {
            wp_redirect( admin_url( 'admin.php?page=cias-lms-live&zoom_error=' . urlencode( $result->get_error_message() ) ) );
        } else {
            wp_redirect( admin_url( 'admin.php?page=cias-lms-live&zoom_connected=1' ) );
        }
        exit;
    }

    public function zoom_hosts(): \WP_REST_Response {
        return rest_ensure_response( [ 'success' => true, 'data' => ZoomHostPool::get_all_hosts() ] );
    }

    public function lock_host( \WP_REST_Request $req ): \WP_REST_Response {
        ZoomHostPool::lock_host( (int) $req->get_param( 'id' ) );
        return rest_ensure_response( [ 'success' => true ] );
    }

    public function unlock_host( \WP_REST_Request $req ): \WP_REST_Response {
        ZoomHostPool::unlock_host( (int) $req->get_param( 'id' ) );
        return rest_ensure_response( [ 'success' => true ] );
    }

    // ── Classes ───────────────────────────────────────────────────────────────

    public function create_class( \WP_REST_Request $req ): \WP_REST_Response|\WP_Error {
        $result = LiveClassService::create( $req->get_json_params() ?? [], get_current_user_id() );
        if ( is_wp_error( $result ) ) return $result;
        return rest_ensure_response( [ 'success' => true, 'data' => $result ] );
    }

    public function cancel_class( \WP_REST_Request $req ): \WP_REST_Response|\WP_Error {
        $result = LiveClassService::cancel( (int) $req->get_param( 'id' ), get_current_user_id() );
        if ( is_wp_error( $result ) ) return $result;
        return rest_ensure_response( [ 'success' => true ] );
    }

    public function share_class( \WP_REST_Request $req ): \WP_REST_Response|\WP_Error {
        $hours  = (int) ( $req->get_param( 'expiry_hours' ) ?? 48 );
        $result = ShareService::generate_link( (int) $req->get_param( 'id' ), get_current_user_id(), $hours );
        if ( is_wp_error( $result ) ) return $result;
        return rest_ensure_response( [ 'success' => true, 'data' => $result ] );
    }

    public function get_attendance( \WP_REST_Request $req ): \WP_REST_Response {
        $data = AttendanceService::get_by_class( (int) $req->get_param( 'id' ) );
        return rest_ensure_response( [ 'success' => true, 'data' => $data ] );
    }

    public function my_classes( \WP_REST_Request $req ): \WP_REST_Response {
        global $wpdb;
        $student_id = get_current_user_id();
        $batch_ids  = $wpdb->get_col( $wpdb->prepare(
            "SELECT batch_id FROM {$wpdb->prefix}cias_lms_enrollments WHERE student_id = %d AND status = 'active'",
            $student_id
        ) );

        if ( empty( $batch_ids ) ) return rest_ensure_response( [ 'success' => true, 'data' => [] ] );

        $placeholders = implode( ',', array_fill( 0, count( $batch_ids ), '%d' ) );
        $upcoming     = $wpdb->get_results( $wpdb->prepare(
            "SELECT id, title, join_url, start_time, end_time, duration_mins, status
             FROM {$wpdb->prefix}cias_live_classes
             WHERE batch_id IN ($placeholders) AND status IN ('scheduled','live') AND start_time >= NOW()
             ORDER BY start_time ASC",
            ...$batch_ids
        ), ARRAY_A );

        $past = $wpdb->get_results( $wpdb->prepare(
            "SELECT lc.id, lc.title, lc.start_time, lc.duration_mins, lc.recording_status,
                    lr.vimeo_video_id
             FROM {$wpdb->prefix}cias_live_classes lc
             LEFT JOIN {$wpdb->prefix}cias_live_recordings lr ON lr.live_class_id = lc.id
             WHERE lc.batch_id IN ($placeholders) AND lc.status = 'completed'
             ORDER BY lc.start_time DESC LIMIT 20",
            ...$batch_ids
        ), ARRAY_A );

        return rest_ensure_response( [ 'success' => true, 'data' => [ 'upcoming' => $upcoming, 'past' => $past ] ] );
    }

    public function join_class( \WP_REST_Request $req ): \WP_REST_Response|\WP_Error {
        global $wpdb;
        $student_id = get_current_user_id();
        $class_id   = (int) $req->get_param( 'id' );

        $class = $wpdb->get_row( $wpdb->prepare(
            "SELECT lc.join_url, lc.batch_id FROM {$wpdb->prefix}cias_live_classes lc
             JOIN {$wpdb->prefix}cias_lms_enrollments e ON e.batch_id = lc.batch_id AND e.student_id = %d AND e.status = 'active'
             WHERE lc.id = %d AND lc.status IN ('scheduled','live')",
            $student_id, $class_id
        ) );

        if ( ! $class ) return new \WP_Error( 'forbidden', 'Access denied or class not found.', [ 'status' => 403 ] );

        return rest_ensure_response( [ 'success' => true, 'data' => [ 'join_url' => $class->join_url ] ] );
    }

    // ── Zoom Webhook ──────────────────────────────────────────────────────────

    public function zoom_webhook( \WP_REST_Request $req ): \WP_REST_Response {
        $signature = $req->get_header( 'x-zm-signature' ) ?? '';
        $raw_body  = $req->get_body();
        $payload   = $req->get_json_params() ?? [];

        // Handle URL validation challenge
        if ( ( $payload['event'] ?? '' ) === 'endpoint.url_validation' ) {
            $token     = $payload['payload']['plainToken'] ?? '';
            $hash      = hash_hmac( 'sha256', $token, defined( 'CIAS_ZOOM_WEBHOOK_SECRET' ) ? CIAS_ZOOM_WEBHOOK_SECRET : '' );
            return rest_ensure_response( [ 'plainToken' => $token, 'encryptedToken' => $hash ] );
        }

        // Handle participant events for attendance
        $event = $payload['event'] ?? '';
        if ( $event === 'meeting.participant_joined' ) {
            $meeting_id = $payload['payload']['object']['id'] ?? '';
            $email      = $payload['payload']['object']['participant']['email'] ?? '';
            if ( $meeting_id && $email ) AttendanceService::mark_joined( $meeting_id, $email );
        }

        if ( $event === 'meeting.participant_left' ) {
            $meeting_id = $payload['payload']['object']['id'] ?? '';
            $email      = $payload['payload']['object']['participant']['email'] ?? '';
            if ( $meeting_id && $email ) AttendanceService::mark_left( $meeting_id, $email );
        }

        RecordingPipeline::handle_webhook( $payload, $signature, $raw_body );
        return rest_ensure_response( [ 'success' => true ] );
    }

    public function validate_share( \WP_REST_Request $req ): \WP_REST_Response|\WP_Error {
        $result = ShareService::validate_token( sanitize_text_field( $req->get_param( 'token' ) ) );
        if ( is_wp_error( $result ) ) return $result;
        return rest_ensure_response( [ 'success' => true, 'data' => $result ] );
    }
}
