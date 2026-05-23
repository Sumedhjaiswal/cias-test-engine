<?php
/**
 * CIAS Phase B – Scalable AI Backend
 *
 * Architecture: WordPress REST API → MySQL + Cloudflare R2 + Redis (Upstash) + async PHP workers
 *
 * Features:
 *   B1 – DB schema: answer submissions, OCR, evaluations, job queue, analytics
 *   B2 – Cloudflare R2 client (zero-egress file storage)
 *   B3 – Upstash Redis queue (async job dispatch)
 *   B4 – REST endpoint: presign upload URL  POST /cias/v1/upload/presign
 *   B5 – REST endpoint: answer submission   POST /cias/v1/answer/submit
 *   B6 – REST endpoint: AI Guru chat (async) POST /cias/v1/guru/chat
 *   B7 – REST endpoint: status polling       GET  /cias/v1/job/{id}/status
 *   B8 – REST endpoint: chat history         GET  /cias/v1/guru/history
 *   B9 – OCR pipeline (confidence gate)     [worker]
 *   B10 – AI evaluation engine              [worker]
 *   B11 – Analytics aggregator              [worker + cron]
 *   B12 – Teacher review admin              [admin page]
 *   B13 – Worker retry / dead-letter        [worker]
 *
 * RULE: Claude is NEVER called synchronously inside a REST/AJAX request.
 *       Every AI call goes through the job queue.
 *
 * @package CIAS
 * @since   3.19.0
 */

defined( 'ABSPATH' ) || exit;

define( 'CIAS_PHASE_B_VERSION', '1.0.0' );
define( 'CIAS_PHASE_B_DIR',     __DIR__ . '/' );
define( 'CIAS_PHASE_B_URL',     plugin_dir_url( CIAS_PLUGIN_FILE ) . 'phase-b/' );

// ── Table name constants ───────────────────────────────────────────────────
global $wpdb;
define( 'CIAS_SUBMISSIONS',      $wpdb->prefix . 'cias_answer_submissions' );
define( 'CIAS_OCR_RESULTS',      $wpdb->prefix . 'cias_ocr_results' );
define( 'CIAS_AI_EVALUATIONS',   $wpdb->prefix . 'cias_ai_evaluations' );
define( 'CIAS_TEACHER_REVIEWS',  $wpdb->prefix . 'cias_teacher_reviews' );
define( 'CIAS_JOB_QUEUE',        $wpdb->prefix . 'cias_job_queue' );
define( 'CIAS_ANALYTICS_DAILY',  $wpdb->prefix . 'cias_analytics_daily' );
define( 'CIAS_TOPIC_PERF',       $wpdb->prefix . 'cias_topic_performance' );

// ── Load classes ───────────────────────────────────────────────────────────
require_once CIAS_PHASE_B_DIR . 'class-cias-db-phase-b.php';
require_once CIAS_PHASE_B_DIR . 'class-cias-r2.php';
require_once CIAS_PHASE_B_DIR . 'class-cias-queue.php';
require_once CIAS_PHASE_B_DIR . 'class-cias-ocr.php';
require_once CIAS_PHASE_B_DIR . 'class-cias-evaluator.php';
require_once CIAS_PHASE_B_DIR . 'class-cias-rest-upload.php';
require_once CIAS_PHASE_B_DIR . 'class-cias-rest-answers.php';
require_once CIAS_PHASE_B_DIR . 'class-cias-rest-guru.php';
require_once CIAS_PHASE_B_DIR . 'class-cias-rest-status.php';
require_once CIAS_PHASE_B_DIR . 'class-cias-analytics-aggregator.php';
require_once CIAS_PHASE_B_DIR . 'class-cias-teacher-review.php';
require_once CIAS_PHASE_B_DIR . 'class-cias-ops-monitor.php';
require_once CIAS_PHASE_B_DIR . 'class-cias-question-generator.php';

// ── DB install on version mismatch ─────────────────────────────────────────
add_action( 'plugins_loaded', function () {
    if ( get_option( 'cias_phase_b_db_version' ) !== CIAS_PHASE_B_VERSION ) {
        CIAS_DB_Phase_B::install();
    }
}, 5 );

// ── Boot REST routes ───────────────────────────────────────────────────────
add_action( 'rest_api_init', function () {
    CIAS_REST_Upload::register_routes();
    CIAS_REST_Answers::register_routes();
    CIAS_REST_Guru::register_routes();
    CIAS_REST_Status::register_routes();
}, 10 );

// ── Boot admin features ────────────────────────────────────────────────────
add_action( 'plugins_loaded', function () {
    CIAS_Teacher_Review::init();
    CIAS_Analytics_Aggregator::init_cron();
    CIAS_Ops_Monitor::init();
}, 25 );

// ── Register activation for Phase B ───────────────────────────────────────
register_activation_hook( CIAS_PLUGIN_FILE, [ 'CIAS_DB_Phase_B', 'install' ] );

// ══════════════════════════════════════════════════════════════════════════════
// WP-CRON JOB PROCESSOR — fallback when no server cron is configured
//
// Fires every minute via WP-Cron (triggered by page loads).
// Processes pending guru_chat jobs inline using the same logic as the CLI worker.
// Safe: uses a DB lock (transient) to prevent overlapping runs.
// ══════════════════════════════════════════════════════════════════════════════

// Register a 1-minute WP-Cron interval if not already defined
add_filter( 'cron_schedules', function ( $schedules ) {
    if ( ! isset( $schedules['cias_every_minute'] ) ) {
        $schedules['cias_every_minute'] = [
            'interval' => 60,
            'display'  => 'Every Minute (CIAS)',
        ];
    }
    return $schedules;
} );

// Schedule the cron event on plugin load if not already scheduled
add_action( 'plugins_loaded', function () {
    if ( ! wp_next_scheduled( 'cias_process_jobs' ) ) {
        wp_schedule_event( time(), 'cias_every_minute', 'cias_process_jobs' );
    }
}, 30 );

// The cron callback — processes pending guru_chat jobs
add_action( 'cias_process_jobs', 'cias_run_wpcron_job_processor' );

function cias_run_wpcron_job_processor(): void {
    // Lock: prevent overlapping runs (transient expires in 90s)
    if ( get_transient( 'cias_job_processor_running' ) ) return;
    set_transient( 'cias_job_processor_running', 1, 90 );

    try {
        $worker_id  = 'wpcron:' . getmypid() . ':' . time();
        $max_jobs   = 5;
        $processed  = 0;
        $start      = microtime( true );

        while ( $processed < $max_jobs && ( microtime( true ) - $start ) < 25 ) {
            // Process guru_chat jobs first (fast, user-facing)
            $job = CIAS_DB_Phase_B::claim_next_job( 'guru_chat', $worker_id );
            if ( $job ) {
                $payload = json_decode( $job->payload_json, true ) ?: [];
                try {
                    $result = cias_process_guru_chat_job( $payload );
                    CIAS_DB_Phase_B::complete_job( (int) $job->id, $result );
                } catch ( \Throwable $e ) {
                    CIAS_DB_Phase_B::fail_job( (int) $job->id, $e->getMessage() );
                }
                $processed++;
                continue;
            }

            // Then process generate_questions jobs (heavier, admin-facing)
            $gen = CIAS_DB_Phase_B::claim_next_job( 'generate_questions', $worker_id );
            if ( $gen ) {
                $payload = json_decode( $gen->payload_json, true ) ?: [];
                try {
                    if ( ! class_exists( 'CIAS_Question_Generator' ) ) {
                        require_once CIAS_PHASE_B_DIR . 'class-cias-question-generator.php';
                    }
                    $result = CIAS_Question_Generator::generate( $payload );
                    CIAS_DB_Phase_B::complete_job( (int) $gen->id, $result );
                } catch ( \Throwable $e ) {
                    CIAS_DB_Phase_B::fail_job( (int) $gen->id, $e->getMessage() );
                }
                $processed++;
                continue;
            }

            // No jobs of either type — stop
            break;
        }
    } finally {
        delete_transient( 'cias_job_processor_running' );
    }
}

function cias_process_guru_chat_job( array $payload ): array {
    $user_id    = (int) ( $payload['user_id']    ?? 0 );
    $session_id = (string) ( $payload['session_id'] ?? '' );
    $message    = (string) ( $payload['message']    ?? '' );

    if ( ! $user_id || ! $message ) {
        throw new \InvalidArgumentException( 'Missing user_id or message in guru_chat payload.' );
    }

    // Load student profile
    $profile = method_exists( 'CAIG_Data', 'get_profile' )
        ? CAIG_Data::get_profile( $user_id )
        : [];

    // Load last 6 messages from chat history for context
    global $wpdb;
    $rows = $wpdb->get_results( $wpdb->prepare(
        "SELECT role, body AS content
         FROM {$wpdb->prefix}cias_chat_messages
         WHERE user_id = %d AND session_id = %s
         ORDER BY created_at DESC LIMIT 6",
        $user_id, $session_id
    ) );
    $history = array_reverse(
        array_map( fn( $r ) => [ 'role' => $r->role, 'content' => $r->content ], $rows )
    );

    // Call Claude via CAIG_AI::guru_chat (same as sync path)
    $response = CAIG_AI::guru_chat( $profile, $message, $history );

    // Persist assistant reply to chat history
    do_action( 'cias_guru_assistant_message', [
        'session_id' => $session_id,
        'user_id'    => $user_id,
        'body'       => $response,
        'tokens'     => null,
    ] );

    return [
        'response'   => $response,
        'session_id' => $session_id,
        'user_id'    => $user_id,
    ];
}

// Also trigger immediately when a guru_chat job is pushed (via shutdown hook)
// This gives sub-minute response times on active pages without waiting for cron
add_action( 'cias_guru_job_pushed', function ( $job_id ) {
    // Fire async HTTP request to wp-cron so it processes without blocking the user
    if ( ! get_transient( 'cias_job_processor_running' ) ) {
        wp_schedule_single_event( time(), 'cias_process_jobs' );
        spawn_cron();
    }
} );

// Trigger immediate processing when a generate_questions job is queued from admin.
add_action( 'cias_generate_job_pushed', function ( $job_id ) {
    if ( ! get_transient( 'cias_job_processor_running' ) ) {
        wp_schedule_single_event( time(), 'cias_process_jobs' );
        spawn_cron();
    }
} );
