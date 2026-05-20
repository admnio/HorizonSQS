<?php

namespace Admnio\Sunset\Exceptions;

use RuntimeException;

class RateLimitExceededException extends RuntimeException
{
    public function __construct(
        public readonly string $limitName,
        public readonly int $retryAfterSeconds,
    ) {
        parent::__construct("Rate limit '{$limitName}' exceeded; retryAfter={$retryAfterSeconds}s.");
    }
}
