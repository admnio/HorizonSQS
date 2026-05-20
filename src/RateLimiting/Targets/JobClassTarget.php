<?php

namespace Admnio\Sunset\RateLimiting\Targets;

final class JobClassTarget
{
    public function __construct(public readonly string $jobClass)
    {
    }
}
