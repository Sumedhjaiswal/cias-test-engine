#!/usr/bin/env php
<?php
/**
 * CIAS Phase B – Retry / Dead-Letter Worker
 *
 * Cron entry (every 10 minutes):
 *   @every-10min: /usr/bin/php /var/.../worker-retry.php >> /var/log/cias-retry.log 2>&1
 *
 * Responsibilities:
 *   1. Alert admin if dead jobs exceed threshold
 *   2. Attempt manual recovery on certain failure types
 *   3. Clean up completed jobs older than 7 days
 *   4. Detect stale 'processing' jobs from crashed workers
 */
require_once __DIR__ . '/bootstrap.php';

$dead_jobs = [];
global $wpdb;

// ── 1. Find dead jobs ─────────────────────────────────────────────────────────
$dead_jobs = $wpdb->get_results(
    "SELECT id, type, error_message, payload_json, attempts, created_at
     FROM " . CIAS_JOB_QUEUE . "
     WHERE status = 'dead'
       AND created_at > DATE_SUB(NOW(), INTERVAL 24 HOUR)
     ORDER BY created_at DESC LIMIT 50"
);

$worker = new class extends CIAS_Worker_Base {
    public function __construct() { parent::__construct('dead_letter'); }
    protected function process_job(array $p): array { return []; } // Not used
    public function expose_log(string $msg): void { $this->log($msg); }
};

if ( ! empty($dead_jobs) ) {
    $worker->expose_log( "Found " . count($dead_jobs) . " dead jobs in last 24h." );

    // Admin alert (once per hour max)
    $alerted = get_transient('cias_dead_job_alert');
    if ( ! $alerted && count($dead_jobs) >= (int)get_option('cias_dead_job_alert_threshold', 5) ) {
        $admin_email = get_option('admin_email');
        $site_name   = get_bloginfo('name');
        $summary     = implode("\n", array_map(fn($j) => "  #{$j->id} [{$j->type}]: " . substr($j->error_message ?? '', 0, 100), $dead_jobs));
        wp_mail(
            $admin_email,
            "[{$site_name}] CIAS: " . count($dead_jobs) . " dead background jobs",
            "Dead jobs in the last 24 hours:\n\n{$summary}\n\nCheck: WP Admin → CIAS → AI Activity → Job Queue"
        );
        set_transient('cias_dead_job_alert', 1, HOUR_IN_SECONDS);
        $worker->expose_log("Alert email sent to {$admin_email}.");
    }
}

// ── 2. Recover all stale 'processing' jobs across all types ───────────────────
$stale = $wpdb->query(
    "UPDATE " . CIAS_JOB_QUEUE . "
     SET status='pending', worker_id=NULL, started_at=NULL
     WHERE status='processing'
       AND started_at < DATE_SUB(NOW(), INTERVAL 8 MINUTE)
       AND attempts < max_attempts"
);
if ( $stale ) $worker->expose_log("Recovered {$stale} stale processing jobs.");

// ── 3. Clean completed jobs older than 7 days ─────────────────────────────────
$deleted = $wpdb->query(
    "DELETE FROM " . CIAS_JOB_QUEUE . "
     WHERE status IN ('done', 'dead')
       AND finished_at < DATE_SUB(NOW(), INTERVAL 7 DAY)
     LIMIT 500"
);
if ( $deleted ) $worker->expose_log("Cleaned {$deleted} old completed jobs.");

// ── 4. Summary ────────────────────────────────────────────────────────────────
$queue_summary = $wpdb->get_results(
    "SELECT type, status, COUNT(*) AS cnt
     FROM " . CIAS_JOB_QUEUE . "
     WHERE status != 'done'
     GROUP BY type, status"
);
foreach ( $queue_summary as $row ) {
    $worker->expose_log("Queue: {$row->type} / {$row->status} = {$row->cnt}");
}

$worker->expose_log("Retry worker finished.");
