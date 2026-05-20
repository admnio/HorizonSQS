<?php

namespace Admnio\Sunset\RateLimiting;

final class Decision
{
    /**
     * @param array<int, mixed> $reservations
     * @param array<int, mixed> $rolledBackReservations
     */
    private function __construct(
        public readonly bool $admitted,
        public readonly int $retryAfterSeconds,
        public readonly array $reservations,
        public readonly array $rolledBackReservations = [],
    ) {
    }

    /**
     * @param array<int, mixed> $reservations
     */
    public static function admit(array $reservations = []): self
    {
        return new self(true, 0, $reservations);
    }

    /**
     * @param array<int, mixed> $rolledBack
     */
    public static function reject(int $retryAfterSeconds, array $rolledBack = []): self
    {
        return new self(false, max(1, $retryAfterSeconds), [], $rolledBack);
    }

    /**
     * Merge a sequence of per-limit decisions into a single decision.
     * - All Admits -> one Admit with concatenated reservations.
     * - Any Reject -> Reject with max retry-after, plus the reservations from earlier
     *   Admits exposed as rolledBackReservations for the caller to undo.
     *
     * @param array<int, Decision> $decisions
     */
    public static function merge(array $decisions): self
    {
        $reservations = [];
        $rejectMax = null;

        foreach ($decisions as $d) {
            if (! $d->admitted) {
                $rejectMax = $rejectMax === null
                    ? $d->retryAfterSeconds
                    : max($rejectMax, $d->retryAfterSeconds);
            } else {
                $reservations = array_merge($reservations, $d->reservations);
            }
        }

        if ($rejectMax !== null) {
            return self::reject($rejectMax, $reservations);
        }
        return self::admit($reservations);
    }
}
