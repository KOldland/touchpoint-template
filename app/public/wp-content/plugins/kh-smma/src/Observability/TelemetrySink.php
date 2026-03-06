<?php
namespace KH_SMMA\Observability;

use function get_option;
use function update_option;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class TelemetrySink {
    public function write( array $event ): bool {
        $events = get_option( 'kh_smma_telemetry_events', array() );
        if ( ! is_array( $events ) ) {
            $events = array();
        }

        $events[] = $event;
        if ( count( $events ) > 300 ) {
            $events = array_slice( $events, -300 );
        }

        update_option( 'kh_smma_telemetry_events', $events );
        return true;
    }
}
