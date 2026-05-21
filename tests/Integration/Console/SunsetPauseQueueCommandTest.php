<?php

namespace Admnio\Sunset\Tests\Integration\Console;

use Admnio\Sunset\Contracts\QueuePauseRepository;
use Admnio\Sunset\Events\QueuePaused;
use Admnio\Sunset\Tests\Integration\IntegrationTestCase;
use Illuminate\Contracts\Redis\Factory as RedisFactory;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Event;

/**
 * v1.3.0 — proves `sunset:pause-queue {connection} {queue}` writes through to
 * the QueuePauseRepository, dispatches QueuePaused with actor === 'cli', and
 * surfaces the "Paused {conn}:{queue}" confirmation on stdout. The command is
 * an ops-facing surface (scripted pauses outside the dashboard), so its
 * end-to-end behaviour belongs in the integration suite rather than a unit
 * test of the handle() method.
 */
class SunsetPauseQueueCommandTest extends IntegrationTestCase
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

    public function test_command_pauses_the_queue_and_exits_zero(): void
    {
        $exit = Artisan::call('sunset:pause-queue', [
            'connection' => 'redis',
            'queue' => 'default',
        ]);

        $this->assertSame(0, $exit);

        $this->assertTrue(
            $this->app->make(QueuePauseRepository::class)->isPaused('redis', 'default'),
            'sunset:pause-queue should leave the queue in the paused set',
        );
    }

    public function test_command_outputs_confirmation_with_connection_and_queue(): void
    {
        Artisan::call('sunset:pause-queue', [
            'connection' => 'redis',
            'queue' => 'default',
        ]);

        $this->assertStringContainsString('Paused redis:default', Artisan::output());
    }

    public function test_command_dispatches_queue_paused_with_actor_cli(): void
    {
        Event::fake([QueuePaused::class]);

        Artisan::call('sunset:pause-queue', [
            'connection' => 'redis',
            'queue' => 'default',
        ]);

        Event::assertDispatched(
            QueuePaused::class,
            fn (QueuePaused $e) => $e->connection === 'redis'
                && $e->queue === 'default'
                && $e->actor === 'cli',
        );
    }
}
