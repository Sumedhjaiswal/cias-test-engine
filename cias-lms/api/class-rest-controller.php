<?php
namespace CIAS_LMS\API;

defined( 'ABSPATH' ) || exit;

use CIAS_LMS\Services\EnrollService;
use CIAS_LMS\Services\VideoService;
use CIAS_LMS\Services\PDFService;
use CIAS_LMS\Services\ZoomService;
use CIAS_LMS\Services\ProgressService;
use CIAS_LMS\Services\FingerprintService;

class RestController {

    private const NS = CIAS_LMS_API_NS;
    private const B  = CIAS_LMS_API_BASE;

    public static function register_routes(): void {
        $c = new self();

        // Courses
        register_rest_route( self::NS, '/' . self::B . '/courses',           [ 'methods' => 'GET',  'callback' => [ $c, 'get_courses'  ],    'permission_callback' => [ $c, 'require_student' ] ] );
        register_rest_route( self::NS, '/' . self::B . '/courses/(?P<id>\d+)', [ 'methods' => 'GET',  'callback' => [ $c, 'get_course'   ],    'permission_callback' => [ $c, 'require_student' ] ] );

        // Enroll
        register_rest_route( self::NS, '/' . self::B . '/enroll',            [ 'methods' => 'POST', 'callback' => [ $c, 'enroll'        ],    'permission_callback' => [ $c, 'require_student' ] ] );

        // Video — returns a short-lived signed iframe token, never the raw Vimeo ID
        register_rest_route( self::NS, '/' . self::B . '/video-token',       [ 'methods' => 'POST', 'callback' => [ $c, 'video_token'   ],    'permission_callback' => [ $c, 'require_student' ] ] );

        // PDF — returns a short-lived signed R2 URL
        register_rest_route( self::NS, '/' . self::B . '/pdf-token',         [ 'methods' => 'POST', 'callback' => [ $c, 'pdf_token'     ],    'permission_callback' => [ $c, 'require_student' ] ] );

        // Live class Zoom link
        register_rest_route( self::NS, '/' . self::B . '/zoom-link',         [ 'methods' => 'POST', 'callback' => [ $c, 'zoom_link'     ],    'permission_callback' => [ $c, 'require_student' ] ] );

        // Progress
        register_rest_route( self::NS, '/' . self::B . '/progress',          [ 'methods' => 'POST', 'callback' => [ $c, 'save_progress' ],    'permission_callback' => [ $c, 'require_student' ] ] );
        register_rest_route( self::NS, '/' . self::B . '/progress/(?P<course_id>\d+)', [ 'methods' => 'GET', 'callback' => [ $c, 'get_progress' ], 'permission_callback' => [ $c, 'require_student' ] ] );

        // Security events — client reports screenshot attempts, visibility loss, etc.
        register_rest_route( self::NS, '/' . self::B . '/security-event',    [ 'methods' => 'POST', 'callback' => [ $c, 'log_security_event' ], 'permission_callback' => [ $c, 'require_student' ] ] );

        // Admin — teacher/admin routes
        register_rest_route( self::NS, '/' . self::B . '/admin/courses',     [ 'methods' => 'POST', 'callback' => [ $c, 'create_course' ],    'permission_callback' => [ $c, 'require_teacher' ] ] );
        register_rest_route( self::NS, '/' . self::B . '/admin/lessons',     [ 'methods' => 'POST', 'callback' => [ $c, 'create_lesson' ],    'permission_callback' => [ $c, 'require_teacher' ] ] );
    }

    // ── Permission callbacks ──────────────────────────────────────────────────

    public function require_student( \WP_REST_Request $req ): bool|\WP_Error {
        if ( ! is_user_logged_in() ) {
            return new \WP_Error( 'unauthorized', 'Authentication required.', [ 'status' => 401 ] );
        }
        if ( ! current_user_can( 'cias_student' ) && ! current_user_can( 'manage_options' ) ) {
            return new \WP_Error( 'forbidden', 'Student access required.', [ 'status' => 403 ] );
        }
        return true;
    }

    public function require_teacher( \WP_REST_Request $req ): bool|\WP_Error {
        if ( ! is_user_logged_in() ) {
            return new \WP_Error( 'unauthorized', 'Authentication required.', [ 'status' => 401 ] );
        }
        if ( ! current_user_can( 'cias_teacher' ) && ! current_user_can( 'manage_options' ) ) {
            return new \WP_Error( 'forbidden', 'Teacher access required.', [ 'status' => 403 ] );
        }
        return true;
    }

    // ── Student endpoints ─────────────────────────────────────────────────────

    public function get_courses( \WP_REST_Request $req ): \WP_REST_Response {
        $student_id = get_current_user_id();
        $courses    = EnrollService::get_enrolled_courses( $student_id );
        return rest_ensure_response( [ 'success' => true, 'data' => $courses ] );
    }

    public function get_course( \WP_REST_Request $req ): \WP_REST_Response|\WP_Error {
        $student_id = get_current_user_id();
        $course_id  = (int) $req->get_param( 'id' );

        if ( ! EnrollService::is_enrolled( $student_id, $course_id ) ) {
            return new \WP_Error( 'forbidden', 'Not enrolled in this course.', [ 'status' => 403 ] );
        }

        $course = EnrollService::get_course_with_lessons( $course_id, $student_id );
        return rest_ensure_response( [ 'success' => true, 'data' => $course ] );
    }

    public function enroll( \WP_REST_Request $req ): \WP_REST_Response|\WP_Error {
        $student_id = get_current_user_id();
        $course_id  = (int) $req->get_param( 'course_id' );

        if ( ! $course_id ) {
            return new \WP_Error( 'invalid_params', 'course_id required.', [ 'status' => 400 ] );
        }

        $result = EnrollService::enroll( $student_id, $course_id );
        if ( is_wp_error( $result ) ) return $result;

        return rest_ensure_response( [ 'success' => true, 'message' => 'Enrolled successfully.' ] );
    }

    public function video_token( \WP_REST_Request $req ): \WP_REST_Response|\WP_Error {
        $student_id = get_current_user_id();
        $lesson_id  = (int) $req->get_param( 'lesson_id' );

        if ( ! $lesson_id ) {
            return new \WP_Error( 'invalid_params', 'lesson_id required.', [ 'status' => 400 ] );
        }

        // Ownership: student must be enrolled in the course that contains this lesson
        if ( ! EnrollService::can_access_lesson( $student_id, $lesson_id ) ) {
            return new \WP_Error( 'forbidden', 'Access denied.', [ 'status' => 403 ] );
        }

        $token = VideoService::generate_token(
            $student_id,
            $lesson_id,
            $req->get_header( 'x-forwarded-for' ) ?: $_SERVER['REMOTE_ADDR'],
            $req->get_header( 'user-agent' )
        );

        if ( is_wp_error( $token ) ) return $token;

        return rest_ensure_response( [ 'success' => true, 'data' => $token ] );
    }

    public function pdf_token( \WP_REST_Request $req ): \WP_REST_Response|\WP_Error {
        $student_id = get_current_user_id();
        $lesson_id  = (int) $req->get_param( 'lesson_id' );

        if ( ! EnrollService::can_access_lesson( $student_id, $lesson_id ) ) {
            return new \WP_Error( 'forbidden', 'Access denied.', [ 'status' => 403 ] );
        }

        $token = PDFService::generate_signed_url( $student_id, $lesson_id );
        if ( is_wp_error( $token ) ) return $token;

        return rest_ensure_response( [ 'success' => true, 'data' => $token ] );
    }

    public function zoom_link( \WP_REST_Request $req ): \WP_REST_Response|\WP_Error {
        $student_id = get_current_user_id();
        $lesson_id  = (int) $req->get_param( 'lesson_id' );

        if ( ! EnrollService::can_access_lesson( $student_id, $lesson_id ) ) {
            return new \WP_Error( 'forbidden', 'Access denied.', [ 'status' => 403 ] );
        }

        $link = ZoomService::get_join_link( $lesson_id, $student_id );
        if ( is_wp_error( $link ) ) return $link;

        return rest_ensure_response( [ 'success' => true, 'data' => $link ] );
    }

    public function save_progress( \WP_REST_Request $req ): \WP_REST_Response|\WP_Error {
        $student_id  = get_current_user_id();
        $lesson_id   = (int) $req->get_param( 'lesson_id' );
        $watch_secs  = (int) $req->get_param( 'watch_secs' );
        $completed   = (bool) $req->get_param( 'completed' );

        if ( ! $lesson_id ) {
            return new \WP_Error( 'invalid_params', 'lesson_id required.', [ 'status' => 400 ] );
        }

        ProgressService::upsert( $student_id, $lesson_id, $watch_secs, $completed );
        return rest_ensure_response( [ 'success' => true ] );
    }

    public function get_progress( \WP_REST_Request $req ): \WP_REST_Response {
        $student_id = get_current_user_id();
        $course_id  = (int) $req->get_param( 'course_id' );
        $progress   = ProgressService::get_by_course( $student_id, $course_id );
        return rest_ensure_response( [ 'success' => true, 'data' => $progress ] );
    }

    public function log_security_event( \WP_REST_Request $req ): \WP_REST_Response {
        $student_id = get_current_user_id();
        $lesson_id  = (int) $req->get_param( 'lesson_id' );
        $event_type = sanitize_text_field( $req->get_param( 'event_type' ) );
        $metadata   = $req->get_param( 'metadata' );

        FingerprintService::log_event( $student_id, $lesson_id, $event_type, $metadata );

        return rest_ensure_response( [ 'success' => true ] );
    }

    // ── Admin endpoints ───────────────────────────────────────────────────────

    public function create_course( \WP_REST_Request $req ): \WP_REST_Response|\WP_Error {
        global $wpdb;
        $data = [
            'title'        => sanitize_text_field( $req->get_param( 'title' ) ),
            'slug'         => sanitize_title( $req->get_param( 'title' ) ),
            'description'  => wp_kses_post( $req->get_param( 'description' ) ),
            'access_type'  => in_array( $req->get_param( 'access_type' ), [ 'free', 'enrolled' ], true ) ? $req->get_param( 'access_type' ) : 'enrolled',
            'status'       => 'draft',
            'created_by'   => get_current_user_id(),
        ];

        if ( ! $data['title'] ) {
            return new \WP_Error( 'invalid_params', 'title required.', [ 'status' => 400 ] );
        }

        $wpdb->insert( $wpdb->prefix . 'cias_lms_courses', $data );
        return rest_ensure_response( [ 'success' => true, 'course_id' => $wpdb->insert_id ] );
    }

    public function create_lesson( \WP_REST_Request $req ): \WP_REST_Response|\WP_Error {
        global $wpdb;

        $type = $req->get_param( 'type' );
        if ( ! in_array( $type, [ 'video', 'pdf', 'quiz', 'live' ], true ) ) {
            return new \WP_Error( 'invalid_params', 'Invalid lesson type.', [ 'status' => 400 ] );
        }

        $data = [
            'section_id'     => (int) $req->get_param( 'section_id' ),
            'course_id'      => (int) $req->get_param( 'course_id' ),
            'title'          => sanitize_text_field( $req->get_param( 'title' ) ),
            'type'           => $type,
            'vimeo_video_id' => sanitize_text_field( $req->get_param( 'vimeo_video_id' ) ?? '' ),
            'r2_pdf_key'     => sanitize_text_field( $req->get_param( 'r2_pdf_key' ) ?? '' ),
            'duration_secs'  => (int) $req->get_param( 'duration_secs' ),
            'sort_order'     => (int) $req->get_param( 'sort_order' ),
            'is_preview'     => (int) (bool) $req->get_param( 'is_preview' ),
        ];

        $wpdb->insert( $wpdb->prefix . 'cias_lms_lessons', $data );
        return rest_ensure_response( [ 'success' => true, 'lesson_id' => $wpdb->insert_id ] );
    }
}
