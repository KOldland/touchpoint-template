<?php
namespace KH_SMMA\Observability;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class RetryService {
    public function run( callable $operation, int $attempts = 3 ): bool {
        $attempts = max( 1, $attempts );
        for ( $i = 0; $i < $attempts; $i++ ) {
            if ( true === $operation() ) {
                return true;
            }
        }

        return false;
    }
}
