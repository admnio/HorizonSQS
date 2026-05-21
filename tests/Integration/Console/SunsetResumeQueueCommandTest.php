<?php

namespace Admnio\Sunset\Tests\Integration\Console;

use Admnio\Sunset\Contracts\QueuePauseRepository;
use Admnio\Sunset\Events\QueueResumed;
use Admnio\Sunset\Tests\Integration\IntegrationTestCase;
use Illuminate\Contracts\Redis\Factory as RedisFactory;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Event;

/**
 * v1.3.0 — proves `sunset:resume-queue {connection} {queue}` clears the pause
 * via the QueuePauseRepository, dispatches QueueResumed with actor === 'cli',
 * and surfaces the "Resumed {conn}:{queue}" confirmation on stdout. Mirror of
 * SunsetPauseQueueCommandTest with a pre-paused fixture.
 */
class SunsetResumeQueueCommandTest extends IntegrationTestCase
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
    }

    public function test_command_resumes_a_paused_queue_and_exits_zero(): void
    {
        $repo = $this->app->make(QueuePauseRepository::class);
        $repo->pause('redis', 'default');
        $this->assertTrue($repo->isPaused('redis', 'default'));

        $exit = Artisan::call('sunset:resume-queue', [
            'connection' => 'redis',
            'queue' => 'default',
        ]);

        $this->assertSame(0, $exit);
        $this->assertFalse(
            $repo->isPaused('redis', 'default'),
            'sunset:resume-queue should remove the queue from the paused set',
        );
    }

    public function test_command_outputs_confirmation_with_connection_and_queue(): void
    {
        $this->app->make(QueuePauseRepository::class)->pause('redis', 'default');

        Artisan::call('sunset:resume-queue', [
            'connection' => 'redis',
            'queue' => 'default',
        ]);

        $this->assertStringContainsString('Resumed redis:default', Artisan::output());
    }

    public function test_command_dispatches_queue_resumed_with_actor_cli(): void
    {
        // Pre-pause via direct SADD so we don't resolve the repository
        // singleton (and bake in the real Dispatcher) before Event::fake swaps
        // the facade. The same constraint shows up in RedisQueuePauseRepositoryTest.
        $this->redis->sadd('sunset:queues:paused', 'redis:default');

        Event::fake([QueueResumed::class]);
        $this->app->forgetInstance(QueuePauseRepository::class);
        $this->app->forgetInstance(\Admnio\Sunset\Repositories\Redis\RedisQueuePauseRepository::class);

        Artisan::call('sunset:resume-queue', [
            'connection' => 'redis',
            'queue' => 'default',
        ]);

        Event::assertDispatched(
            QueueResumed::class,
            fn (QueueResumed $e) => $e->connection === 'redis'
                && $e->queue === 'default'
                && $e->actor === 'cli',
        );
    }
}
