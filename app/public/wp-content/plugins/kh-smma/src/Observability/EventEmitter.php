<?php
namespace KH_SMMA\Observability;

use function do_action;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class EventEmitter {
    private EventQueue $queue;
    private RetryService $retry;
    private TelemetrySink $sink;

    public function __construct( ?EventQueue $queue = null, ?RetryService $retry = null, ?TelemetrySink $sink = null ) {
        $this->queue = $queue ?: new EventQueue();
        $this->retry = $retry ?: new RetryService();
        $this->sink  = $sink ?: new TelemetrySink();
    }

    public function emit( string $event_name, array $payload ): bool {
        $event = array(
            'event'   => $event_name,
            'payload' => $payload,
        );

        $this->queue->enqueue( $event );
        $queued = $this->queue->dequeue();
        if ( ! is_array( $queued ) ) {
            return false;
        }

        $ok = $this->retry->run( function () use ( $queued ) {
            return $this->sink->write( $queued );
        }, 3 );

        do_action( 'kh_smma_telemetry_event', $event_name, $payload );

        return $ok;
    }
}
