<?php

namespace Admnio\Sunset\RateLimiting\Targets;

final class QueueTarget
{
    public function __construct(public readonly string $queueName)
    {
    }
}
