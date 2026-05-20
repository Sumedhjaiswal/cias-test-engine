<?php
/**
 * CIAS Phase C – Student Frontend App
 *
 * Renders the full mobile-first student app via shortcode [cias_app].
 * Integrates with Phase A (credits, chat history) and Phase B (REST endpoints,
 * R2 uploads, async evaluation queue).
 *
 * Shortcodes:
 *   [cias_app]              Full 6-tab app (auto-detects logged-in user)
 *   [cias_app tab="tutor"]  Open on a specific tab
 *
 * Usage: Add [cias_app] to any WordPress page. Set it as the student
 * dashboard page for automatic redirect after login.
 *
 * @package CIAS
 * @since   3.19.0
 */

defined( 'ABSPATH' ) || exit;

define( 'CIAS_PHASE_C_VERSION', '3.20.0' );
define( 'CIAS_PHASE_C_DIR',     __DIR__ . '/' );
define( 'CIAS_PHASE_C_URL',     plugin_dir_url( CIAS_PLUGIN_FILE ) . 'phase-c/' );

require_once CIAS_PHASE_C_DIR . 'class-cias-app-data.php';
require_once CIAS_PHASE_C_DIR . 'class-cias-frontend.php';
require_once CIAS_PHASE_C_DIR . 'bridge/class-cias-vocab-bridge.php';

add_action( 'plugins_loaded', function () {
    CIAS_Frontend::init();
    CIAS_Vocab_Bridge::init(); // no-op if vocabulary-app plugin is inactive
}, 30 );

// ── Bridge status admin notice ─────────────────────────────────────────────
add_action( 'admin_notices', function () {
    if ( ! current_user_can( 'manage_options' ) ) return;
    $screen = get_current_screen();
    if ( ! $screen || ! in_array( $screen->id, [ 'toplevel_page_cias-tests', 'cias-tests_page_cias-credit-log', 'cias-tests_page_cias-ai-activity' ], true ) ) return;
    if ( ! class_exists( 'CIAS_Vocab_Bridge' ) ) return;

    $s = CIAS_Vocab_Bridge::status();
    if ( $s['bridge_active'] ) {
        echo '<div class="notice notice-success is-dismissible"><p>'
           . '<strong>Vocab Bridge:</strong> Active — CIAS app is reading live vocabulary data from the Vocabulary App plugin.'
           . '</p></div>';
    } elseif ( $s['vocab_plugin_active'] && ! $s['bridge_active'] ) {
        echo '<div class="notice notice-warning is-dismissible"><p>'
           . '<strong>Vocab Bridge:</strong> Vocabulary App detected but bridge is not active. Try deactivating and reactivating both plugins.'
           . '</p></div>';
    }
} );
