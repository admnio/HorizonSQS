<?php

namespace Admnio\Sunset\Events;

class LongWaitDetected
{
    public function __construct(
        public readonly string $connection,
        public readonly string $queue,
        public readonly int $seconds,
    ) {}
}
