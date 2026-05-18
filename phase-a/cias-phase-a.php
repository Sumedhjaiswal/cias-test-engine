<?php
/**
 * CIAS Phase A – Credits shown in AI Guru tab
 *
 * Bootstraps all Phase A features into the core CIAS Test Engine plugin.
 * Loaded from cias-test-engine.php immediately after the core includes.
 *
 * Features:
 *   A1 – Purchase confirmation email
 *   A2 – Admin credit log (manual vs purchased) under CIAS Tests menu
 *   A3 – AI Guru entry card on student admin profile & front-end profile
 *   A4 – Chat history recording (silent DB write)
 *   A5 – Image attachment in AI Guru chat → WP Media Library
 *   A6 – Auto-classification of message type
 *   A7 – Teacher dashboard AI activity summary per student
 *
 * @package CIAS
 * @since   3.18.0
 */

defined( 'ABSPATH' ) || exit;

define( 'CIAS_PHASE_A_VERSION', '1.0.0' );
define( 'CIAS_PHASE_A_DIR',     __DIR__ . '/' );
define( 'CIAS_PHASE_A_URL',     plugin_dir_url( CIAS_PLUGIN_FILE ) . 'phase-a/' );

// ── Load Phase A classes ─────────────────────────────────────────────────────
require_once CIAS_PHASE_A_DIR . 'class-cias-credit-email.php';         // A1
require_once CIAS_PHASE_A_DIR . 'class-cias-credit-log-admin.php';     // A2
require_once CIAS_PHASE_A_DIR . 'class-cias-student-profile-card.php'; // A3
require_once CIAS_PHASE_A_DIR . 'class-cias-message-classifier.php';   // A6 engine
require_once CIAS_PHASE_A_DIR . 'class-cias-chat-history.php';         // A4 + A5 + A6 wiring
require_once CIAS_PHASE_A_DIR . 'class-cias-teacher-ai-summary.php';   // A7

// ── Boot all features after core is ready ────────────────────────────────────
add_action( 'plugins_loaded', function () {
    CIAS_Credit_Email::init();
    CIAS_Credit_Log_Admin::init();
    CIAS_Student_Profile_Card::init();
    CIAS_Chat_History::init();
    CIAS_Teacher_AI_Summary::init();
}, 25 );
