#!/usr/bin/env php
<?php
/**
 * CIAS Phase B – Question Generation Worker
 *
 * Processes 'generate_questions' jobs: calls Claude to author UPSC-style
 * MCQs and saves them as source='ai', status='ai_pending_review' (hidden
 * from students until a teacher approves them).
 *
 * Cron entry (every minute):
 *   * * * * * /usr/bin/php /var/.../phase-b/workers/worker-generate.php >> /var/log/cias-generate.log 2>&1
 *
 * @package CIAS\PhaseB
 */
require_once __DIR__ . '/bootstrap.php';

class CIAS_Worker_Generate extends CIAS_Worker_Base {

    public function __construct() {
        parent::__construct( 'generate_questions' );
        $this->max_runtime = 55;
        $this->max_jobs    = 3; // generation is heavy; keep per-run small
    }

    protected function process_job( array $payload ): array {
        if ( ! class_exists( 'CIAS_Question_Generator' ) ) {
            // Defensive: ensure the service is loaded even if run standalone.
            $svc = dirname( __DIR__ ) . '/class-cias-question-generator.php';
            if ( file_exists( $svc ) ) require_once $svc;
        }
        if ( ! class_exists( 'CIAS_Question_Generator' ) ) {
            return [ 'generated' => 0, 'reason' => 'Generator service unavailable.' ];
        }

        $this->log( sprintf(
            'Generating %d questions for subject=%d topic=%d subtopic=%d',
            (int) ( $payload['count'] ?? 0 ),
            (int) ( $payload['subject_id'] ?? 0 ),
            (int) ( $payload['topic_id'] ?? 0 ),
            (int) ( $payload['subtopic_id'] ?? 0 )
        ) );

        return CIAS_Question_Generator::generate( $payload );
    }
}

( new CIAS_Worker_Generate() )->run();
