<?php
namespace CIAS_LMS;

defined( 'ABSPATH' ) || exit;

class Loader {

    public static function init(): void {
        // Load all sub-components
        self::load_db();
        self::load_services();
        self::load_api();
        self::load_assets();
        self::load_admin();
    }

    private static function load_db(): void {
        require_once CIAS_LMS_DIR . 'db/schema.php';
    }

    private static function load_services(): void {
        require_once CIAS_LMS_DIR . 'services/lms/class-enroll-service.php';
        require_once CIAS_LMS_DIR . 'services/lms/class-video-service.php';
        require_once CIAS_LMS_DIR . 'services/lms/class-pdf-service.php';
        require_once CIAS_LMS_DIR . 'services/lms/class-zoom-service.php';
        require_once CIAS_LMS_DIR . 'services/lms/class-progress-service.php';
        require_once CIAS_LMS_DIR . 'services/lms/class-fingerprint-service.php';
        require_once CIAS_LMS_DIR . 'services/lms/class-notification-service.php';
    }

    private static function load_api(): void {
        require_once CIAS_LMS_DIR . 'api/class-rest-controller.php';
        add_action( 'rest_api_init', [ \CIAS_LMS\API\RestController::class, 'register_routes' ] );
    }

    private static function load_assets(): void {
        add_action( 'wp_enqueue_scripts', [ __CLASS__, 'enqueue_frontend' ] );
    }

    private static function load_admin(): void {
        if ( is_admin() ) {
            require_once CIAS_LMS_DIR . 'includes/class-admin.php';
            Admin::init();
        }
    }

    public static function enqueue_frontend(): void {
        if ( ! self::is_lms_page() ) return;

        wp_enqueue_style(
            'cias-lms',
            CIAS_LMS_URL . 'assets/css/lms.css',
            [],
            CIAS_LMS_VERSION
        );

        // Core modules
        foreach ( [ 'api', 'state', 'polling', 'lms-player', 'lms-pdf', 'lms-security' ] as $mod ) {
            wp_enqueue_script(
                "cias-lms-{$mod}",
                CIAS_LMS_URL . "assets/js/{$mod}.js",
                [],
                CIAS_LMS_VERSION,
                true
            );
        }

        // Inline config — never expose Vimeo IDs or signed tokens here
        wp_localize_script( 'cias-lms-api', 'CIAS_LMS', [
            'apiBase'   => esc_url( rest_url( CIAS_LMS_API_NS . '/' . CIAS_LMS_API_BASE ) ),
            'nonce'     => wp_create_nonce( 'wp_rest' ),
            'studentId' => get_current_user_id(),
            'version'   => CIAS_LMS_VERSION,
        ] );
    }

    private static function is_lms_page(): bool {
        // Check for CIAS LMS shortcode or custom template
        global $post;
        if ( ! $post ) return false;
        return has_shortcode( $post->post_content, 'cias_lms' )
            || get_post_meta( $post->ID, '_cias_lms_page', true );
    }
}
