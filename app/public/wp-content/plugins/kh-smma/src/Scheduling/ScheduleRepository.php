<?php
namespace KH_SMMA\Scheduling;

use WP_Query;

use function get_post_meta;
use function update_post_meta;
use function wp_reset_postdata;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class ScheduleRepository {
    public function flag_schedules_for_rereview( int $corpus_version, int $updated_by ): int {
        $query = new WP_Query( array(
            'post_type'      => 'kh_smma_schedule',
            'post_status'    => 'publish',
            'posts_per_page' => 200,
            'fields'         => 'ids',
        ) );

        $updated = 0;
        foreach ( $query->posts as $schedule_id ) {
            $approval_status = (string) get_post_meta( $schedule_id, '_kh_smma_approval_status', true );
            if ( 'approved' !== $approval_status ) {
                continue;
            }

            update_post_meta( $schedule_id, '_kh_smma_requires_rereview', 1 );
            update_post_meta( $schedule_id, '_kh_smma_rereview_reason', 'compliance_rules_version_changed' );
            update_post_meta( $schedule_id, '_kh_smma_rereview_corpus_version', $corpus_version );
            update_post_meta( $schedule_id, '_kh_smma_rereview_updated_by', $updated_by );
            update_post_meta( $schedule_id, '_kh_smma_approval_status', 'pending' );
            update_post_meta( $schedule_id, '_kh_smma_schedule_status', 'awaiting_approval' );
            $updated++;
        }

        wp_reset_postdata();

        return $updated;
    }
}
