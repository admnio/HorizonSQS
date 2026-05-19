<?php

namespace Admnio\Sunset\Tests\Integration;

use Illuminate\Contracts\Redis\Factory as RedisFactory;

class MigrateHorizonKeysTest extends IntegrationTestCase
{
    public function test_seeded_horizon_keys_end_up_at_sunset_after_migrate(): void
    {
        $factory = $this->app->make(RedisFactory::class);
        $conn = $factory->connection('default');
        foreach (['horizon:*', 'sunset:*'] as $pattern) {
            foreach ($conn->keys($pattern) as $key) {
                $name = str_replace($conn->_prefix(''), '', $key);
                $conn->del($name);
            }
        }

        $conn->set('horizon:job_id', '42');
        $conn->zadd('horizon:recent_jobs', 1000, 'jobA');
        $conn->zadd('horizon:recent_jobs', 2000, 'jobB');
        $conn->hmset('horizon:jobA', ['id' => 'jobA', 'payload' => '{"x":1}', 'status' => 'completed']);

        $this->artisan('sunset:migrate-horizon-keys')->assertExitCode(0);

        $this->assertSame('42', $conn->get('sunset:job_id'));
        $this->assertSame(2, $conn->zcard('sunset:recent_jobs'));
        $this->assertSame('completed', $conn->hgetall('sunset:job:jobA')['status']);
        $this->assertSame(0, $conn->exists('horizon:job_id'));
        $this->assertSame(0, $conn->exists('horizon:recent_jobs'));
        $this->assertSame(0, $conn->exists('horizon:jobA'));
    }
}
