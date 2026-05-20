<?php

namespace Admnio\Sunset\Tests\Unit\RateLimiting;

use Admnio\Sunset\Contracts\Limiter;
use Admnio\Sunset\Events\JobRateLimited;
use Admnio\Sunset\Exceptions\RateLimitExceededException;
use Admnio\Sunset\RateLimiting\ConcurrencySpec;
use Admnio\Sunset\RateLimiting\Decision;
use Admnio\Sunset\RateLimiting\Limit;
use Admnio\Sunset\RateLimiting\LimitRegistry;
use Admnio\Sunset\RateLimiting\RateLimitGate;
use Admnio\Sunset\RateLimiting\Targets\QueueTarget;
use Admnio\Sunset\RateLimiting\ThrottleSpec;
use Admnio\Sunset\Tests\TestCase;
use Illuminate\Contracts\Queue\Job as JobContract;
use Illuminate\Contracts\Redis\Factory as RedisFactory;
use Illuminate\Support\Facades\Event;
use Mockery;
use RuntimeException;
use Throwable;

class RateLimitGateTest extends TestCase
{
    private RedisFactory $factory;
    private string $connectionName;
    private mixed $conn;

    protected function setUp(): void
    {
        parent::setUp();

        $this->factory = $this->app->make(RedisFactory::class);
        $this->connectionName = config('sunset.redis_connection', 'default');

        try {
            $conn = $this->factory->connection($this->connectionName);
            $pong = $conn->ping();
            if ($pong !== true && (string) $pong !== 'PONG' && (string) $pong !== '+PONG') {
                $this->markTestSkipped('Redis not reachable (unexpected ping response).');
            }
        } catch (Throwable $e) {
            $this->markTestSkipped('Redis not reachable: ' . $e->getMessage());
        }

        $this->conn = $conn;
        $this->flushSunsetRlKeys();
    }

    protected function tearDown(): void
    {
        try {
            $this->flushSunsetRlKeys();
        } catch (Throwable) {
            // ignore
        }
        Mockery::close();
        parent::tearDown();
    }

    private function flushSunsetRlKeys(): void
    {
        $keys = $this->conn->keys('sunset:rl:*');
        if (! empty($keys)) {
            $this->conn->del(...$keys);
        }
    }

    private function buildGate(LimitRegistry $registry, Limiter $limiter, bool $failClosed = false): RateLimitGate
    {
        return new RateLimitGate(
            registry: $registry,
            limiter: $limiter,
            redis: $this->factory,
            redisConnection: $this->connectionName,
            failClosed: $failClosed,
        );
    }

    private function jobMock(string $jobId = 'job-1'): Mockery\MockInterface
    {
        $job = Mockery::mock(JobContract::class);
        $job->shouldReceive('getJobId')->andReturn($jobId);
        return $job;
    }

    public function test_empty_registry_is_zero_overhead_admit(): void
    {
        $registry = new LimitRegistry();
        $limiter = Mockery::mock(Limiter::class);
        $limiter->shouldNotReceive('check');

        $gate = $this->buildGate($registry, $limiter);

        $job = Mockery::mock(JobContract::class);
        $job->shouldNotReceive('release');
        $job->shouldNotReceive('fail');
        $job->shouldNotReceive('delete');

        $decision = $gate->admit($job, ['connection' => 'sqs'], 'q', []);

        $this->assertTrue($decision->admitted);
    }

    public function test_admit_stores_reservations_keyed_by_job_id(): void
    {
        $limit = new Limit(
            name: 'admit-limit',
            target: new QueueTarget('q'),
            concurrency: new ConcurrencySpec(5, 120),
        );
        $registry = new LimitRegistry();
        $registry->upsert($limit);

        $reservation = [
            'type' => 'concurrency',
            'setKey' => 'sunset:rl:c:admit-limit:global',
            'slotKey' => 'sunset:rl:slot:abc',
            'slotId' => 'abc',
        ];

        $limiter = Mockery::mock(Limiter::class);
        $limiter->shouldReceive('check')
            ->once()
            ->andReturn(Decision::admit([$reservation]));

        $gate = $this->buildGate($registry, $limiter);

        $job = $this->jobMock('job-xyz');

        $decision = $gate->admit($job, ['connection' => 'sqs'], 'q', []);

        $this->assertTrue($decision->admitted);

        $raw = $this->conn->get('sunset:rl:reservations:job-xyz');
        $this->assertNotEmpty($raw);
        $decoded = json_decode($raw, true);
        $this->assertIsArray($decoded);
        $this->assertCount(1, $decoded);
        $this->assertSame('concurrency', $decoded[0]['type']);
        $this->assertSame('abc', $decoded[0]['slotId']);
    }

    public function test_reject_fires_JobRateLimited_event_and_calls_release_on_job(): void
    {
        Event::fake([JobRateLimited::class]);

        $limit = new Limit(
            name: 'reject-limit',
            target: new QueueTarget('q'),
            throttle: new ThrottleSpec(1, 60),
            overLimit: 'release-computed',
        );
        $registry = new LimitRegistry();
        $registry->upsert($limit);

        $limiter = Mockery::mock(Limiter::class);
        $limiter->shouldReceive('check')
            ->once()
            ->andReturn(Decision::reject(45));

        $gate = $this->buildGate($registry, $limiter);

        $job = $this->jobMock('job-r');
        $job->shouldReceive('release')->once()->with(45);
        $job->shouldNotReceive('fail');
        $job->shouldNotReceive('delete');

        $decision = $gate->admit($job, ['connection' => 'sqs'], 'q', []);

        $this->assertFalse($decision->admitted);
        Event::assertDispatched(JobRateLimited::class, function (JobRateLimited $e) {
            return $e->limitName === 'reject-limit'
                && $e->strategy === 'release-computed'
                && $e->retryAfterSeconds === 45
                && $e->queueName === 'q'
                && $e->connection === 'sqs';
        });
    }

    public function test_drop_with_dropAsFailure_calls_job_fail(): void
    {
        Event::fake([JobRateLimited::class]);

        $limit = new Limit(
            name: 'drop-fail',
            target: new QueueTarget('q'),
            throttle: new ThrottleSpec(1, 60),
            overLimit: 'drop',
            dropAsFailure: true,
        );
        $registry = new LimitRegistry();
        $registry->upsert($limit);

        $limiter = Mockery::mock(Limiter::class);
        $limiter->shouldReceive('check')
            ->once()
            ->andReturn(Decision::reject(10));

        $gate = $this->buildGate($registry, $limiter);

        $job = $this->jobMock('job-df');
        $job->shouldReceive('fail')
            ->once()
            ->with(Mockery::type(RateLimitExceededException::class));
        $job->shouldNotReceive('release');
        $job->shouldNotReceive('delete');

        $decision = $gate->admit($job, ['connection' => 'sqs'], 'q', []);

        $this->assertFalse($decision->admitted);
    }

    public function test_drop_silent_calls_job_delete(): void
    {
        Event::fake([JobRateLimited::class]);

        $limit = new Limit(
            name: 'drop-silent',
            target: new QueueTarget('q'),
            throttle: new ThrottleSpec(1, 60),
            overLimit: 'drop',
            dropAsFailure: false,
        );
        $registry = new LimitRegistry();
        $registry->upsert($limit);

        $limiter = Mockery::mock(Limiter::class);
        $limiter->shouldReceive('check')
            ->once()
            ->andReturn(Decision::reject(10));

        $gate = $this->buildGate($registry, $limiter);

        $job = $this->jobMock('job-ds');
        $job->shouldReceive('delete')->once();
        $job->shouldNotReceive('fail');
        $job->shouldNotReceive('release');

        $decision = $gate->admit($job, ['connection' => 'sqs'], 'q', []);

        $this->assertFalse($decision->admitted);
    }

    public function test_release_fixed_uses_fixed_backoff_not_computed(): void
    {
        Event::fake([JobRateLimited::class]);

        $limit = new Limit(
            name: 'fixed-limit',
            target: new QueueTarget('q'),
            throttle: new ThrottleSpec(1, 60),
            overLimit: 'release-fixed',
            fixedBackoffSeconds: 90,
        );
        $registry = new LimitRegistry();
        $registry->upsert($limit);

        $limiter = Mockery::mock(Limiter::class);
        $limiter->shouldReceive('check')
            ->once()
            ->andReturn(Decision::reject(45));

        $gate = $this->buildGate($registry, $limiter);

        $job = $this->jobMock('job-f');
        $job->shouldReceive('release')->once()->with(90);
        $job->shouldNotReceive('fail');
        $job->shouldNotReceive('delete');

        $decision = $gate->admit($job, ['connection' => 'sqs'], 'q', []);

        $this->assertFalse($decision->admitted);
    }

    public function test_admit_when_limiter_throws_and_failClosed_is_false(): void
    {
        $limit = new Limit(
            name: 'fail-open',
            target: new QueueTarget('q'),
            throttle: new ThrottleSpec(5, 60),
        );
        $registry = new LimitRegistry();
        $registry->upsert($limit);

        $limiter = Mockery::mock(Limiter::class);
        $limiter->shouldReceive('check')
            ->once()
            ->andThrow(new RuntimeException('boom'));

        $gate = $this->buildGate($registry, $limiter, failClosed: false);

        $job = $this->jobMock('job-fo');
        $job->shouldNotReceive('release');
        $job->shouldNotReceive('fail');
        $job->shouldNotReceive('delete');

        $decision = $gate->admit($job, ['connection' => 'sqs'], 'q', []);

        $this->assertTrue($decision->admitted);
    }

    public function test_reject_when_limiter_throws_and_failClosed_is_true(): void
    {
        Event::fake([JobRateLimited::class]);

        $limit = new Limit(
            name: 'fail-closed',
            target: new QueueTarget('q'),
            throttle: new ThrottleSpec(5, 60),
            overLimit: 'release-computed',
        );
        $registry = new LimitRegistry();
        $registry->upsert($limit);

        $limiter = Mockery::mock(Limiter::class);
        $limiter->shouldReceive('check')
            ->once()
            ->andThrow(new RuntimeException('boom'));

        $gate = $this->buildGate($registry, $limiter, failClosed: true);

        $job = $this->jobMock('job-fc');
        $job->shouldReceive('release')->once()->with(30);
        $job->shouldNotReceive('fail');
        $job->shouldNotReceive('delete');

        $decision = $gate->admit($job, ['connection' => 'sqs'], 'q', []);

        $this->assertFalse($decision->admitted);
        $this->assertSame(30, $decision->retryAfterSeconds);
    }

    public function test_readReservations_returns_empty_array_when_missing(): void
    {
        $registry = new LimitRegistry();
        $limiter = Mockery::mock(Limiter::class);
        $gate = $this->buildGate($registry, $limiter);

        $this->assertSame([], $gate->readReservations('nonexistent-job-id'));
    }

    public function test_clearReservations_removes_the_key(): void
    {
        $registry = new LimitRegistry();
        $limiter = Mockery::mock(Limiter::class);
        $gate = $this->buildGate($registry, $limiter);

        $key = 'sunset:rl:reservations:to-clear';
        $this->conn->set($key, json_encode([['type' => 'concurrency']]));
        $this->assertNotEmpty($this->conn->get($key));

        $gate->clearReservations('to-clear');

        $value = $this->conn->get($key);
        $this->assertTrue($value === null || $value === false || $value === '');
    }
}
