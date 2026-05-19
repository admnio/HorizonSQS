<?php

namespace Admnio\Sunset\Tests\Integration;

use Admnio\Sunset\Tests\Fixtures\Jobs\RecordingJob;
use Illuminate\Contracts\Redis\Factory as RedisFactory;
use Illuminate\Support\Facades\Queue;
use Laravel\Horizon\Horizon;

class HorizonDashboardCompatTest extends IntegrationTestCase
{
    protected function defineEnvironment($app): void
    {
        parent::defineEnvironment($app);

        // Horizon routes go through the full HTTP kernel which boots encryption.
        $app['config']->set('app.key', 'base64:' . base64_encode(random_bytes(32)));
    }

    protected function setUp(): void
    {
        parent::setUp();
        Horizon::auth(fn () => true);

        $factory = $this->app->make(RedisFactory::class);
        $conn = $factory->connection('default');
        foreach (['sunset:*', 'horizon:*'] as $pattern) {
            foreach ($conn->keys($pattern) as $key) {
                $name = str_replace($conn->_prefix(''), '', $key);
                $conn->del($name);
            }
        }
        $conn->del('queues:sunset-dash-test');

        config([
            'queue.default' => 'redis',
            'queue.connections.redis.queue' => 'sunset-dash-test',
        ]);
    }

    public function test_dashboard_pending_endpoint_serves_sunset_data_via_adapter(): void
    {
        Queue::push(new RecordingJob('pending-test'));

        usleep(200_000); // let listeners flush

        $response = $this->get('/horizon/api/jobs/pending');
        $response->assertOk();

        $data = $response->json();
        $this->assertNotEmpty($data['jobs'] ?? [],
            '/horizon/api/jobs/pending should return our sunset-recorded job through the adapter chain');
    }
}
