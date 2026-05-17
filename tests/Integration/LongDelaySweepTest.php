<?php

namespace MasonWorkforce\HorizonSqs\Tests\Integration;

use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Redis;
use MasonWorkforce\HorizonSqs\Queue\Delay\DelayedJobReenqueuer;

class LongDelaySweepTest extends IntegrationTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->ensureLocalStackAvailable();
        $this->deleteAllQueues();
        $url = $this->createQueue('default');
        config(['queue.connections.sqs.prefix' => str_replace('/default', '', $url)]);
        Redis::connection('default')->del('horizon-sqs:delayed');
    }

    public function test_long_delay_is_buffered_and_swept(): void
    {
        $now = time();
        Queue::later(3600, '{"id":"abc","tags":[],"_horizon_nonce":"n"}', '', 'default');

        $this->assertGreaterThan(0, Redis::connection('default')->zcard('horizon-sqs:delayed'));

        $reenqueuer = $this->app->make(DelayedJobReenqueuer::class);
        $reenqueuer->sweep($now + 3700);

        $this->assertSame(0, Redis::connection('default')->zcard('horizon-sqs:delayed'));

        sleep(1);
        $job = Queue::connection('sqs')->pop('default');
        $this->assertNotNull($job);
    }
}
