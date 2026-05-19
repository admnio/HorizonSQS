<?php

namespace Admnio\Sunset\Events;

use Admnio\Sunset\JobPayload;

abstract class JobEvent
{
    public function __construct(
        public readonly string $connectionName,
        public readonly string $queue,
        public readonly JobPayload $payload,
    ) {
    }
}
