<?php

namespace Admnio\Sunset\Tests\Unit\RateLimiting;

use Admnio\Sunset\RateLimiting\Decision;
use Admnio\Sunset\Tests\TestCase;

class DecisionTest extends TestCase
{
    public function test_admit_carries_reservations_and_sets_admitted_true(): void
    {
        $decision = Decision::admit(['res-1', 'res-2']);

        $this->assertTrue($decision->admitted);
        $this->assertSame(0, $decision->retryAfterSeconds);
        $this->assertSame(['res-1', 'res-2'], $decision->reservations);
        $this->assertSame([], $decision->rolledBackReservations);
    }

    public function test_reject_sets_admitted_false_and_retry_after(): void
    {
        $decision = Decision::reject(45);

        $this->assertFalse($decision->admitted);
        $this->assertSame(45, $decision->retryAfterSeconds);
        $this->assertSame([], $decision->reservations);
        $this->assertSame([], $decision->rolledBackReservations);
    }

    public function test_reject_floors_retry_after_at_one(): void
    {
        $decision = Decision::reject(0);

        $this->assertFalse($decision->admitted);
        $this->assertSame(1, $decision->retryAfterSeconds);
    }

    public function test_merge_of_admits_concatenates_reservations(): void
    {
        $merged = Decision::merge([
            Decision::admit(['a']),
            Decision::admit(['b', 'c']),
        ]);

        $this->assertTrue($merged->admitted);
        $this->assertSame(0, $merged->retryAfterSeconds);
        $this->assertSame(['a', 'b', 'c'], $merged->reservations);
        $this->assertSame([], $merged->rolledBackReservations);
    }

    public function test_merge_with_any_reject_returns_reject_with_max_retry_and_rolled_back_reservations(): void
    {
        $merged = Decision::merge([
            Decision::admit(['a']),
            Decision::reject(30),
            Decision::reject(60),
        ]);

        $this->assertFalse($merged->admitted);
        $this->assertSame(60, $merged->retryAfterSeconds);
        $this->assertSame([], $merged->reservations);
        $this->assertSame(['a'], $merged->rolledBackReservations);
    }
}
