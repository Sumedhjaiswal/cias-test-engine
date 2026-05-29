<?php
namespace CIAS_LIVE\API;

defined( 'ABSPATH' ) || exit;

use CIAS_LIVE\Services\LiveClassService;
use CIAS_LIVE\DB\LiveClassDB;

class LiveClassRoutes {

    public static function register( string $ns, string $base ): void {
        $b = $base . '/classes';

        register_rest_route( $ns, '/' . $b, [
            [ 'methods' => 'GET',  'callback' => [ __CLASS__, 'list_classes' ],  'permission_callback' => [ __CLASS__, 'can_manage' ] ],
            [ 'methods' => 'POST', 'callback' => [ __CLASS__, 'create_class' ],  'permission_callback' => [ __CLASS__, 'can_manage' ] ],
        ] );

        register_rest_route( $ns, '/' . $b . '/(?P<id>\d+)', [
            [ 'methods' => 'GET',    'callback' => [ __CLASS__, 'get_class' ],    'permission_callback' => [ __CLASS__, 'can_manage' ] ],
            [ 'methods' => 'PUT',    'callback' => [ __CLASS__, 'update_class' ], 'permission_callback' => [ __CLASS__, 'can_manage' ] ],
            [ 'methods' => 'DELETE', 'callback' => [ __CLASS__, 'cancel_class' ], 'permission_callback' => [ __CLASS__, 'can_manage' ] ],
        ] );

        // Student-facing endpoint
        register_rest_route( $ns, '/' . $base . '/my-classes', [
            'methods'             => 'GET',
            'callback'            => [ __CLASS__, 'my_classes' ],
            'permission_callback' => function() { return is_user_logged_in(); },
        ] );
    }

    // ── Handlers ───────────────────────────────────────────────────────────

    public static function list_classes( \WP_REST_Request $req ): \WP_REST_Response {
        $args = [
            'status'    => sanitize_text_field( $req->get_param('status') ?? '' ),
            'batch_id'  => (int) $req->get_param('batch_id'),
            'date_from' => sanitize_text_field( $req->get_param('date_from') ?? '' ),
            'date_to'   => sanitize_text_field( $req->get_param('date_to') ?? '' ),
            'limit'     => min( (int) ( $req->get_param('limit') ?? 50 ), 100 ),
            'offset'    => (int) ( $req->get_param('offset') ?? 0 ),
        ];

        $classes = LiveClassDB::get_classes( $args );
        $total   = LiveClassDB::count( $args );

        return rest_ensure_response( [
            'classes' => $classes,
            'total'   => $total,
        ] );
    }

    public static function create_class( \WP_REST_Request $req ): \WP_REST_Response {
        $result = LiveClassService::create( $req->get_json_params() ?: $req->get_body_params() );

        if ( ! $result['success'] ) {
            return new \WP_REST_Response( $result, 422 );
        }
        return new \WP_REST_Response( $result, 201 );
    }

    public static function get_class( \WP_REST_Request $req ): \WP_REST_Response {
        $class = LiveClassDB::get_class( (int) $req['id'] );
        if ( ! $class ) return new \WP_REST_Response( [ 'message' => 'Not found' ], 404 );
        return rest_ensure_response( $class );
    }

    public static function update_class( \WP_REST_Request $req ): \WP_REST_Response {
        $result = LiveClassService::update( (int) $req['id'], $req->get_json_params() ?: $req->get_body_params() );
        return new \WP_REST_Response( $result, $result['success'] ? 200 : 422 );
    }

    public static function cancel_class( \WP_REST_Request $req ): \WP_REST_Response {
        $result = LiveClassService::cancel( (int) $req['id'] );
        return new \WP_REST_Response( $result, $result['success'] ? 200 : 422 );
    }

    public static function my_classes( \WP_REST_Request $req ): \WP_REST_Response {
        $classes = LiveClassService::get_for_student( get_current_user_id() );
        return rest_ensure_response( [ 'classes' => $classes ] );
    }

    // ── Permission ─────────────────────────────────────────────────────────

    public static function can_manage(): bool|\WP_Error {
        if ( ! is_user_logged_in() ) {
            return new \WP_Error( 'unauthorized', 'Login required.', [ 'status' => 401 ] );
        }
        if (
            current_user_can( 'manage_options' ) ||
            current_user_can( 'cias_teacher' ) ||
            current_user_can( 'cias_content_manager' )
        ) {
            return true;
        }
        return new \WP_Error( 'forbidden', 'Access denied.', [ 'status' => 403 ] );
    }
}
