<?php

namespace Admnio\Sunset\Events;

use Admnio\Sunset\JobPayload;

class JobRateLimited
{
    public function __construct(
        public readonly string $connection,
        public readonly string $queueName,
        public readonly string $limitName,
        public readonly int $retryAfterSeconds,
        public readonly string $strategy,
        public readonly JobPayload $payload,
    ) {
    }
}
