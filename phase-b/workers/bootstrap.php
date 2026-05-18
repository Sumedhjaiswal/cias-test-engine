<?php
/**
 * CIAS Phase B – Worker Bootstrap
 *
 * Minimal WordPress bootstrap for CLI worker processes.
 * Workers are called by real system cron (not wp-cron):
 *
 * Cloudways cron entries (add via Cloudways → Server → Cron Job Manager):
 *   @every-minute: php .../worker-ocr.php >> /var/log/cias-ocr.log 2>&1
 *   @every-minute: php .../worker-evaluate.php >> /var/log/cias-eval.log 2>&1
 *   @every-5min:   php .../worker-analytics.php >> /var/log/cias-analytics.log 2>&1
 *   @every-10min:  php .../worker-retry.php >> /var/log/cias-retry.log 2>&1
 *
 * Each worker:
 * - Runs for max 50 seconds (safely under 1-minute cron interval)
 * - Claims one job at a time (atomic UPDATE prevents double-processing)
 * - On fatal error: marks job as failed, exits
 * - Uses unique worker_id to detect stale "processing" jobs
 *
 * @package CIAS\PhaseB
 * @since   3.19.0
 */

// ── Must be run from CLI ──────────────────────────────────────────────────────
if ( php_sapi_name() !== 'cli' ) {
    http_response_code( 403 );
    exit( 'Workers must run from CLI.' );
}

// ── Locate WordPress ──────────────────────────────────────────────────────────
// Walk up the directory tree from plugin root to find wp-load.php
$plugin_root = dirname( dirname( dirname( __FILE__ ) ) ); // wp-content/plugins/cias-test-engine
$wp_load     = null;

for ( $i = 0; $i < 6; $i++ ) {
    $candidate = $plugin_root . str_repeat( '/..', $i ) . '/wp-load.php';
    if ( file_exists( $candidate ) ) {
        $wp_load = realpath( $candidate );
        break;
    }
}

if ( ! $wp_load ) {
    fwrite( STDERR, "[CIAS Worker] Cannot find wp-load.php. Adjust CIAS_WORKER_WP_ROOT in worker script.\n" );
    exit( 1 );
}

// ── Boot WordPress (minimal — no themes, no most plugins needed) ─────────────
// SHORTINIT would be too minimal (no WPDB, no options). Load full WP but suppress output.
define( 'DOING_CRON', true );     // Suppress some hooks
define( 'ABSPATH', dirname( $wp_load ) . '/' );

ob_start();
require_once $wp_load;
ob_end_clean();

// ── Worker base class ─────────────────────────────────────────────────────────

abstract class CIAS_Worker_Base {

    protected string $job_type;
    protected string $worker_id;
    protected int    $max_runtime = 50; // seconds (stay under 60s cron interval)
    protected int    $max_jobs    = 10; // max jobs per run (prevent monopoly)
    protected float  $start_time;

    public function __construct( string $job_type ) {
        $this->job_type  = $job_type;
        $this->worker_id = gethostname() . ':' . getmypid() . ':' . time();
        $this->start_time = microtime(true);

        // Recover stale jobs from previous crashed workers
        $this->recover_stale_jobs();
    }

    abstract protected function process_job( array $payload ): array;

    public function run(): void {
        $this->log( "Worker started. Type={$this->job_type} ID={$this->worker_id}" );

        $jobs_processed = 0;

        while ( $this->has_time() && $jobs_processed < $this->max_jobs ) {
            $job = CIAS_DB_Phase_B::claim_next_job( $this->job_type, $this->worker_id );

            if ( ! $job ) {
                $this->log( "No pending jobs. Exiting." );
                break;
            }

            $this->log( "Processing job #{$job->id}" );
            $payload = json_decode( $job->payload_json, true ) ?: [];

            try {
                $result = $this->process_job( $payload );
                CIAS_DB_Phase_B::complete_job( (int)$job->id, $result );
                $this->log( "Job #{$job->id} completed." );
            } catch ( \Throwable $e ) {
                $error = get_class($e) . ': ' . $e->getMessage() . ' [' . $e->getFile() . ':' . $e->getLine() . ']';
                $this->log( "Job #{$job->id} FAILED: {$error}" );
                CIAS_DB_Phase_B::fail_job( (int)$job->id, $error );
            }

            $jobs_processed++;
        }

        $elapsed = round( microtime(true) - $this->start_time, 2 );
        $this->log( "Worker finished. Jobs={$jobs_processed} Elapsed={$elapsed}s" );
    }

    protected function has_time(): bool {
        return ( microtime(true) - $this->start_time ) < $this->max_runtime;
    }

    /**
     * Recover jobs stuck in 'processing' state for more than 5 minutes.
     * This handles worker crashes without graceful shutdown.
     */
    protected function recover_stale_jobs(): void {
        global $wpdb;
        $recovered = $wpdb->query( $wpdb->prepare(
            "UPDATE `" . CIAS_JOB_QUEUE . "`
             SET status='pending', worker_id=NULL, started_at=NULL
             WHERE type=%s AND status='processing'
               AND started_at < DATE_SUB(NOW(), INTERVAL 5 MINUTE)
               AND attempts < max_attempts",
            $this->job_type
        ) );
        if ( $recovered ) {
            $this->log( "Recovered {$recovered} stale jobs." );
        }
    }

    protected function log( string $message ): void {
        $ts = gmdate( 'Y-m-d H:i:s' );
        echo "[{$ts}][{$this->job_type}] {$message}\n";
    }
}
