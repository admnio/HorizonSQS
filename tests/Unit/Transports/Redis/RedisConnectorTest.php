<?php

namespace Admnio\Sunset\Tests\Unit\Transports\Redis;

use Admnio\Sunset\Contracts\Transport;
use Admnio\Sunset\Support\TransportRegistry;
use Admnio\Sunset\Transports\Redis\RedisConnector;
use Admnio\Sunset\Transports\Redis\RedisQueue;
use Admnio\Sunset\Tests\TestCase;
use Mockery;

class RedisConnectorTest extends TestCase
{
    public function test_connect_delegates_to_redis_transport(): void
    {
        $config = [
            'queue' => 'default',
            'connection' => 'default',
            'retry_after' => 60,
        ];

        $expected = Mockery::mock(RedisQueue::class);

        $transport = Mockery::mock(Transport::class);
        $transport->shouldReceive('name')->andReturn('redis');
        $transport->shouldReceive('connect')->with($config)->once()->andReturn($expected);

        $registry = new TransportRegistry();
        $registry->register($transport);

        $this->app->instance(TransportRegistry::class, $registry);

        $connector = new RedisConnector($this->app->make(TransportRegistry::class));
        $queue = $connector->connect($config);

        $this->assertSame($expected, $queue);
    }

    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }
}
