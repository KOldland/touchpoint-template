<?php
namespace KH_SMMA\Observability;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class EventQueue {
    private array $queue = array();

    public function enqueue( array $event ): void {
        $this->queue[] = $event;
    }

    public function dequeue(): ?array {
        if ( empty( $this->queue ) ) {
            return null;
        }

        return array_shift( $this->queue );
    }
}
