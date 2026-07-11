<?php

namespace Panda\Audit;

use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;

class AuditLogger
{
    private LoggerInterface $logger;

    public function __construct(?LoggerInterface $logger = null)
    {
        $this->logger = $logger ?? new NullLogger();
    }

    /**
     * Log an audit event.
     */
    public function log(
        string $actor,
        string $field,
        string $kid,
        string $eventType,
        string $outcome,
        array $extra = []
    ): void {
        $event = array_merge([
            'timestamp' => date('c'),
            'actor' => $actor,
            'field' => $field,
            'kid' => $kid,
            'eventType' => $eventType, // decrypt_masked | decrypt_raw | encrypt | rotate | shred
            'outcome' => $outcome, // success | auth_failure | malformed_envelope | key_not_found
        ], $extra);

        $this->logger->info(json_encode($event));
    }
}
