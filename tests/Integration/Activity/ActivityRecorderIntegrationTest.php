<?php

namespace Admnio\Sunset\Tests\Integration\Activity;

use Admnio\Sunset\Activity\ActivityEventFactory;
use Admnio\Sunset\Activity\ActivityRecorder;
use Admnio\Sunset\Contracts\ActivityRepository;
use Admnio\Sunset\Events\ActivityRecorded;
use Admnio\Sunset\Events\JobFailed;
use Admnio\Sunset\JobPayload;
use Admnio\Sunset\Repositories\Redis\RedisActivityRepository;
use Admnio\Sunset\Tests\Integration\IntegrationTestCase;
use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Contracts\Redis\Factory as RedisFactory;
use Illuminate\Support\Facades\Event;
use Psr\Log\NullLogger;

/**
 * Wires the recorder into the live event bus (manually here; the
 * SunsetServiceProvider does this for real in T7) and asserts that firing a
 * Sunset event lands an ActivityEvent in the Redis-backed buffer and dispatches
 * an ActivityRecorded event for consumer subscribers.
 */
class ActivityRecorderIntegrationTest extends IntegrationTestCase
{
    /** @var \Illuminate\Redis\Connections\Connection */
    private $redis;

    protected function setUp(): void
    {
        parent::setUp();

        $factory = $this->app->make(RedisFactory::class);
        $this->redis = $factory->connection('default');

        // FLUSHDB-equivalent: wipe any leftover sunset:* keys from prior runs.
        foreach ($this->redis->keys('sunset:*') as $key) {
            $name = str_replace($this->redis->_prefix(''), '', $key);
            $this->redis->del($name);
        }

        config(['sunset.activity.enabled' => true]);
        config(['sunset.activity.stream_buffer_size' => 100]);

        // Bind the contract → concrete write-capable class so app(...) resolves
        // the same instance both layers see. T7 moves this into the SP.
        $this->app->singleton(
            RedisActivityRepository::class,
            fn ($app) => new RedisActivityRepository($app->make(RedisFactory::class)),
        );
        $this->app->alias(RedisActivityRepository::class, ActivityRepository::class);

        $this->app->singleton(
            ActivityEventFactory::class,
            fn () => new ActivityEventFactory(fn () => time()),
        );

        $this->app->singleton(ActivityRecorder::class, function ($app) {
            return new ActivityRecorder(
                factory: $app->make(ActivityEventFactory::class),
                repository: $app->make(RedisActivityRepository::class),
                events: $app->make(Dispatcher::class),
                logger: new NullLogger(),
                enabled: (bool) config('sunset.activity.enabled', true),
            );
        });

        // Subscribe the recorder. T7 will do this for the full 8-event set in
        // the service provider; here we just wire what this test exercises.
        Event::listen(JobFailed::class, [ActivityRecorder::class, 'handle']);
    }

    public function test_firing_job_failed_records_an_activity_event_in_the_buffer(): void
    {
        $payload = new JobPayload(json_encode([
            'uuid' => 'job-xyz',
            'displayName' => 'App\\Jobs\\SendEmail',
            'exception_data' => json_encode([
                'class' => 'RuntimeException',
                'message' => 'boom',
            ]),
        ]));

        event(new JobFailed('sqs', 'orders', $payload));

        $recent = $this->app->make(ActivityRepository::class)->recent(1);

        $this->assertCount(1, $recent);
        $this->assertSame('job_failed', $recent[0]->type);
        $this->assertSame('job-xyz', $recent[0]->payload['job_id']);
        $this->assertSame('sqs', $recent[0]->payload['connection']);
        $this->assertSame('orders', $recent[0]->payload['queue']);
        $this->assertGreaterThan(0, $recent[0]->id);
    }

    public function test_firing_job_failed_dispatches_activity_recorded_event(): void
    {
        // Fake only ActivityRecorded — we still want JobFailed to reach our
        // listener so the recorder runs end-to-end against real Redis.
        Event::fake([ActivityRecorded::class]);

        $payload = new JobPayload(json_encode([
            'uuid' => 'job-abc',
            'displayName' => 'App\\Jobs\\Other',
        ]));

        event(new JobFailed('sqs', 'default', $payload));

        Event::assertDispatched(ActivityRecorded::class, function (ActivityRecorded $event) {
            return $event->event->type === 'job_failed'
                && $event->event->payload['job_id'] === 'job-abc'
                && $event->event->id > 0;
        });
    }
}
