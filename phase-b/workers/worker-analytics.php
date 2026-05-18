#!/usr/bin/env php
<?php
/**
 * CIAS Phase B – Analytics Worker
 *
 * Cron entry (every 5 minutes):
 *   @every-5min: /usr/bin/php /var/.../worker-analytics.php >> /var/log/cias-analytics.log 2>&1
 */
require_once __DIR__ . '/bootstrap.php';

class CIAS_Worker_Analytics extends CIAS_Worker_Base {
    public function __construct() {
        parent::__construct( 'analytics' );
        $this->max_runtime = 55;
        $this->max_jobs    = 3;
    }
    protected function process_job( array $payload ): array {
        $type = $payload['type'] ?? 'nightly_rebuild';
        if ( $type === 'nightly_rebuild' ) {
            $date = $payload['date'] ?? gmdate('Y-m-d', strtotime('yesterday'));
            $this->log( "Nightly rebuild for {$date}" );
            return CIAS_Analytics_Aggregator::run_nightly_rebuild( $date );
        }
        if ( $type === 'single_user' ) {
            $uid  = (int)( $payload['user_id'] ?? 0 );
            $date = $payload['date'] ?? current_time('Y-m-d');
            CIAS_Analytics_Aggregator::aggregate_user_day( $uid, $date );
            return [ 'user_id' => $uid, 'date' => $date ];
        }
        return [ 'skipped' => true, 'reason' => 'Unknown analytics job type.' ];
    }
}

( new CIAS_Worker_Analytics() )->run();
