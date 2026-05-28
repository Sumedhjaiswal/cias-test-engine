<?php
namespace CIAS_LIVE;

defined( 'ABSPATH' ) || exit;

class Loader {

    public static function init(): void {
        self::load_services();
        self::load_api();
        self::load_admin();
        self::load_cron();
        self::load_assets();
    }

    private static function load_services(): void {
        require_once CIAS_LIVE_DIR . 'services/live/class-zoom-host-pool.php';
        require_once CIAS_LIVE_DIR . 'services/live/class-live-class-service.php';
        require_once CIAS_LIVE_DIR . 'services/live/class-recording-pipeline.php';
        require_once CIAS_LIVE_DIR . 'services/live/class-services.php'; // contains ShareService, AttendanceService, NotificationService
    }

    private static function load_api(): void {
        require_once CIAS_LIVE_DIR . 'api/class-rest-controller.php';
        add_action( 'rest_api_init', [ \CIAS_LIVE\API\RestController::class, 'register_routes' ] );
    }

    private static function load_admin(): void {
        if ( is_admin() ) {
            require_once CIAS_LIVE_DIR . 'includes/class-admin.php';
            Admin::init();
        }
    }

    private static function load_cron(): void {
        add_action( 'cias_live_cron', [ \CIAS_LIVE\Services\RecordingPipeline::class, 'process_pending' ] );
        add_action( 'cias_live_cron', [ \CIAS_LIVE\Services\NotificationService::class, 'send_reminders' ] );
    }

    private static function load_assets(): void {
        add_action( 'admin_enqueue_scripts', [ __CLASS__, 'enqueue_admin' ] );
    }

    public static function enqueue_admin( string $hook ): void {
        if ( ! str_contains( $hook, 'cias-lms-live' ) ) return;
        wp_enqueue_style( 'cias-live-admin', CIAS_LIVE_URL . 'assets/css/live-admin.css', [], CIAS_LIVE_VERSION );
        wp_enqueue_script( 'cias-live-admin', CIAS_LIVE_URL . 'assets/js/live-admin.js', [], CIAS_LIVE_VERSION, true );
        wp_localize_script( 'cias-live-admin', 'CIAS_LIVE', [
            'apiBase' => esc_url( rest_url( CIAS_LIVE_API_NS . '/' . CIAS_LIVE_API_BASE ) ),
            'nonce'   => wp_create_nonce( 'wp_rest' ),
        ] );
    }
}
