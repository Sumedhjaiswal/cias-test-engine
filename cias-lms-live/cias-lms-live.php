<?php
/**
 * Plugin Name: CIAS LMS Live
 * Plugin URI:  https://aeonias.com
 * Description: Live classes, Zoom host pool, auto-recording pipeline for CIAS LMS ecosystem.
 * Version:     1.0.0
 * Author:      Sumedh Jaiswal / Aeon IAS
 * Text Domain: cias-lms-live
 * Requires at least: 6.0
 * Requires PHP: 8.1
 */

defined( 'ABSPATH' ) || exit;

// ── Constants ─────────────────────────────────────────────────────────────────
define( 'CIAS_LIVE_VERSION',  '1.0.0' );
define( 'CIAS_LIVE_FILE',     __FILE__ );
define( 'CIAS_LIVE_DIR',      plugin_dir_path( __FILE__ ) );
define( 'CIAS_LIVE_URL',      plugin_dir_url( __FILE__ ) );
define( 'CIAS_LIVE_API_NS',   'cias/v1' );
define( 'CIAS_LIVE_API_BASE', 'lms-live' );

// ── Autoloader ────────────────────────────────────────────────────────────────
spl_autoload_register( function ( string $class ): void {
    $prefix = 'CIAS_LIVE\\';
    if ( ! str_starts_with( $class, $prefix ) ) return;
    $relative = str_replace( [ $prefix, '\\' ], [ '', '/' ], $class );
    $file      = CIAS_LIVE_DIR . 'includes/' . strtolower( $relative ) . '.php';
    if ( file_exists( $file ) ) require_once $file;
} );

// ── Bootstrap ─────────────────────────────────────────────────────────────────
add_action( 'plugins_loaded', function (): void {

    // Require CIAS LMS core
    if ( ! defined( 'CIAS_LMS_VERSION' ) ) {
        add_action( 'admin_notices', function (): void {
            echo '<div class="notice notice-error"><p>';
            esc_html_e( 'CIAS LMS Live requires CIAS LMS plugin to be active.', 'cias-lms-live' );
            echo '</p></div>';
        } );
        return;
    }

    require_once CIAS_LIVE_DIR . 'includes/class-loader.php';
    CIAS_LIVE\Loader::init();
} );

// ── Activation ────────────────────────────────────────────────────────────────
register_activation_hook( __FILE__, function (): void {
    require_once CIAS_LIVE_DIR . 'db/schema.php';
    CIAS_LIVE\DB\Schema::install();
    // Schedule cron for reminders and recording checks
    if ( ! wp_next_scheduled( 'cias_live_cron' ) ) {
        wp_schedule_event( time(), 'every_five_minutes', 'cias_live_cron' );
    }
    flush_rewrite_rules();
} );

register_deactivation_hook( __FILE__, function (): void {
    wp_clear_scheduled_hook( 'cias_live_cron' );
    flush_rewrite_rules();
} );

// ── Custom cron interval ──────────────────────────────────────────────────────
add_filter( 'cron_schedules', function ( array $schedules ): array {
    $schedules['every_five_minutes'] = [
        'interval' => 300,
        'display'  => 'Every 5 Minutes',
    ];
    return $schedules;
} );
