<?php

namespace Admnio\Sunset\Tests\Unit\Telemetry;

use Admnio\Sunset\Repositories\Redis\RedisWorkerMetricsRepository;
use Admnio\Sunset\Tests\TestCase;
use Illuminate\Contracts\Redis\Factory as RedisFactory;
use Mockery;
use RuntimeException;

/**
 * Read-path failure semantics: when Redis is unavailable, the repository
 * degrades gracefully so the dashboard renders "—" cells instead of 500ing.
 *
 * record() intentionally does NOT swallow — the listener is responsible for
 * silencing telemetry write failures, per the v1.1.0 spec.
 */
class RedisWorkerMetricsRepositoryFailureTest extends TestCase
{
    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }

    public function test_all_returns_empty_array_when_factory_throws(): void
    {
        $repo = new RedisWorkerMetricsRepository($this->throwingFactory());

        $this->assertSame([], $repo->all());
    }

    public function test_find_returns_null_when_factory_throws(): void
    {
        $repo = new RedisWorkerMetricsRepository($this->throwingFactory());

        $this->assertNull($repo->find(42));
    }

    public function test_series_returns_empty_array_when_factory_throws(): void
    {
        $repo = new RedisWorkerMetricsRepository($this->throwingFactory());

        $this->assertSame([], $repo->series(42, 'rss'));
        $this->assertSame([], $repo->series(42, 'cpu'));
    }

    private function throwingFactory(): RedisFactory
    {
        $factory = Mockery::mock(RedisFactory::class);
        $factory->shouldReceive('connection')
            ->andThrow(new RuntimeException('Redis unavailable'));

        return $factory;
    }
}
