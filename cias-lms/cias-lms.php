<?php
/**
 * Plugin Name: CIAS LMS
 * Plugin URI:  https://aeonias.com
 * Description: Learning Management System for CIAS — video lectures, PDFs, live classes, quizzes. Part of the CIAS Test Engine ecosystem.
 * Version:     1.0.0
 * Author:      Sumedh Jaiswal / Aeon IAS
 * Text Domain: cias-lms
 * Requires at least: 6.0
 * Requires PHP: 8.1
 */

defined( 'ABSPATH' ) || exit;

// ── Constants ────────────────────────────────────────────────────────────────
define( 'CIAS_LMS_VERSION',   '1.0.0' );
define( 'CIAS_LMS_FILE',      __FILE__ );
define( 'CIAS_LMS_DIR',       plugin_dir_path( __FILE__ ) );
define( 'CIAS_LMS_URL',       plugin_dir_url( __FILE__ ) );
define( 'CIAS_LMS_API_NS',    'cias/v1' );
define( 'CIAS_LMS_API_BASE',  'lms' );

// ── Autoloader ───────────────────────────────────────────────────────────────
spl_autoload_register( function ( string $class ): void {
    $prefix = 'CIAS_LMS\\';
    if ( ! str_starts_with( $class, $prefix ) ) return;

    $relative = str_replace( [ $prefix, '\\' ], [ '', '/' ], $class );
    $file      = CIAS_LMS_DIR . 'includes/' . strtolower( $relative ) . '.php';

    if ( file_exists( $file ) ) {
        require_once $file;
    }
} );

// ── Bootstrap ────────────────────────────────────────────────────────────────
add_action( 'plugins_loaded', function (): void {
    // Abort if CIAS core plugin is not active
    if ( ! defined( 'CIAS_VERSION' ) ) {
        add_action( 'admin_notices', function (): void {
            echo '<div class="notice notice-error"><p>';
            esc_html_e( 'CIAS LMS requires the CIAS Test Engine plugin to be active.', 'cias-lms' );
            echo '</p></div>';
        } );
        return;
    }

    require_once CIAS_LMS_DIR . 'includes/class-loader.php';
    CIAS_LMS\Loader::init();
} );

// ── Activation / Deactivation ─────────────────────────────────────────────────
register_activation_hook( __FILE__, function (): void {
    require_once CIAS_LMS_DIR . 'db/schema.php';
    CIAS_LMS\DB\Schema::install();
    flush_rewrite_rules();
} );

register_deactivation_hook( __FILE__, function (): void {
    flush_rewrite_rules();
} );
