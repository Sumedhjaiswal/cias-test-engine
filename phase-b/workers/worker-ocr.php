#!/usr/bin/env php
<?php
/**
 * CIAS Phase B – OCR Worker
 *
 * Processes 'ocr' jobs: downloads image from R2 → Claude Vision → confidence gate
 *
 * Cron entry (Cloudways, every minute):
 *   * * * * * /usr/bin/php /var/www/vhosts/yoursite.com/httpdocs/wp-content/plugins/cias-test-engine/phase-b/workers/worker-ocr.php >> /var/log/cias-ocr.log 2>&1
 *
 * @package CIAS\PhaseB
 */

require_once __DIR__ . '/bootstrap.php';

class CIAS_Worker_OCR extends CIAS_Worker_Base {

    public function __construct() {
        parent::__construct( 'ocr' );
        $this->max_runtime = 50;
        $this->max_jobs    = 5; // Vision calls are slow — limit per run
    }

    protected function process_job( array $payload ): array {
        if ( empty( $payload['submission_id'] ) || empty( $payload['r2_key'] ) ) {
            throw new \InvalidArgumentException( 'Missing submission_id or r2_key in OCR job payload.' );
        }

        $this->log( "OCR for submission #{$payload['submission_id']} key={$payload['r2_key']}" );
        return CIAS_OCR::process( $payload );
    }
}

// ── Run ───────────────────────────────────────────────────────────────────────
( new CIAS_Worker_OCR() )->run();
