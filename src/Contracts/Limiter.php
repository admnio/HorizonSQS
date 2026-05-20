<?php

namespace Admnio\Sunset\Contracts;

use Admnio\Sunset\RateLimiting\Decision;
use Admnio\Sunset\RateLimiting\Limit;

/**
 * Backs the rate-limiting check-and-reserve cycle.
 *
 * Implementations must guarantee that check() is atomic per limit so two
 * workers cannot both observe "9/10 used" and both admit.
 */
interface Limiter
{
    /**
     * Evaluate both throttle and concurrency specs on the limit atomically.
     * Returns Decision::admit($reservations) or Decision::reject($retryAfterSeconds).
     */
    public function check(Limit $limit, string $bucketKey): Decision;

    /**
     * Release concurrency slots tied to reservations. Throttle entries are not
     * released — they age out via the sliding window.
     *
     * @param array<int, mixed> $reservations
     */
    public function release(array $reservations): void;

    /**
     * Undo Admits when a later sibling limit Rejects (cross-limit composition).
     *
     * @param array<int, mixed> $reservations
     */
    public function rollback(array $reservations): void;
}
