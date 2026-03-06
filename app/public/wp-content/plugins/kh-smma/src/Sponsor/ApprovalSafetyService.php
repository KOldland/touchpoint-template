<?php
namespace KH_SMMA\Sponsor;

use KH_SMMA\Scheduling\ScheduleRepository;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class ApprovalSafetyService {
    private ScheduleRepository $schedules;

    public function __construct( ?ScheduleRepository $schedules = null ) {
        $this->schedules = $schedules ?: new ScheduleRepository();
    }

    public function trigger_rereview_for_corpus_version( int $corpus_version, int $updated_by ): int {
        return $this->schedules->flag_schedules_for_rereview( $corpus_version, $updated_by );
    }
}
