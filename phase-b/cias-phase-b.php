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
