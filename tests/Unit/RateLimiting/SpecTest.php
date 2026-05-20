<?php

namespace Admnio\Sunset\Tests\Unit\RateLimiting;

use Admnio\Sunset\RateLimiting\ConcurrencySpec;
use Admnio\Sunset\RateLimiting\ThrottleSpec;
use Admnio\Sunset\Tests\TestCase;
use InvalidArgumentException;

class SpecTest extends TestCase
{
    public function test_throttle_spec_carries_fields(): void
    {
        $spec = new ThrottleSpec(10, 60);

        $this->assertSame(10, $spec->max);
        $this->assertSame(60, $spec->windowSeconds);
    }

    public function test_throttle_spec_rejects_zero_max(): void
    {
        $this->expectException(InvalidArgumentException::class);

        new ThrottleSpec(0, 60);
    }

    public function test_concurrency_spec_carries_fields(): void
    {
        $spec = new ConcurrencySpec(3, 120);

        $this->assertSame(3, $spec->max);
        $this->assertSame(120, $spec->slotTtlSeconds);
    }

    public function test_concurrency_spec_rejects_zero_slot_ttl(): void
    {
        $this->expectException(InvalidArgumentException::class);

        new ConcurrencySpec(3, 0);
    }
}
