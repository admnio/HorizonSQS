<?php

namespace Admnio\Sunset\Tests\Integration;

use Admnio\Sunset\Tests\Fixtures\Jobs\RecordingJob;
use Illuminate\Contracts\Redis\Factory as RedisFactory;
use Illuminate\Support\Facades\Queue;
use Laravel\Horizon\Horizon;

class RedisRoundtripTest extends IntegrationTestCase
{
    protected function defineEnvironment($app): void
    {
        parent::defineEnvironment($app);

        // Register a supervisor pointing at the redis connection + test queue
        // so ServiceProvider::resolveQueueList() finds it for dashboard endpoints.
        $app['config']->set('horizon.environments.testing.supervisor-1', [
            'connection' => 'redis',
            'queue' => ['sunset-redis-test'],
        ]);

        // Horizon routes go through the full HTTP kernel which boots encryption.
        $app['config']->set('app.key', 'base64:' . base64_encode(random_bytes(32)));
    }

    protected function setUp(): void
    {
        parent::setUp();

        // Bypass Horizon's auth gate for dashboard API tests
        Horizon::auth(fn () => true);

        // Clean any leftover state in the test queue
        $factory = $this->app->make(RedisFactory::class);
        $conn = $factory->connection('default');
        $conn->del('queues:sunset-redis-test');
        $conn->del('queues:sunset-redis-test:delayed');
        $conn->del('queues:sunset-redis-test:reserved');

        @unlink(sys_get_temp_dir() . '/sunset-marker');

        // Use Redis as the default queue + a unique queue name
        config([
            'queue.default' => 'redis',
            'queue.connections.redis.queue' => 'sunset-redis-test',
        ]);
    }

    public function test_push_pop_process_roundtrip_via_redis_driver(): void
    {
        Queue::push(new RecordingJob('hello-from-redis'));

        $job = Queue::connection('redis')->pop('sunset-redis-test');
        $this->assertNotNull($job, 'Expected to pop a job from sunset-redis-test');

        $job->fire();

        $this->assertSame(
            'hello-from-redis',
            file_get_contents(sys_get_temp_dir() . '/sunset-marker')
        );
    }

    public function test_dashboard_pending_endpoint_lists_redis_job(): void
    {
        // Pre-clean Horizon's Redis state so prior test runs don't pollute results.
        app(\Laravel\Horizon\Contracts\JobRepository::class)->trimRecentJobs();

        // Push a job; do NOT pop it — leave it pending
        Queue::push(new RecordingJob('pending-test'));

        // Give Horizon's event listeners a moment to record
        usleep(200_000);

        $response = $this->get('/horizon/api/jobs/pending');
        $response->assertOk();

        $data = $response->json();
        $this->assertNotEmpty(
            $data['jobs'] ?? [],
            'Expected /horizon/api/jobs/pending to include the redis-driven job'
        );
    }
}
