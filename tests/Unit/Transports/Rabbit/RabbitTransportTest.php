<?php

namespace Admnio\Sunset\Tests\Unit\Transports\Rabbit;

use Admnio\Sunset\Tests\TestCase;
use Admnio\Sunset\Transports\Rabbit\RabbitQueue;
use Admnio\Sunset\Transports\Rabbit\RabbitTransport;
use Mockery;
use PhpAmqpLib\Connection\AMQPLazyConnection;
use Psr\Log\LoggerInterface;

class RabbitTransportTest extends TestCase
{
    public function test_name_returns_rabbitmq(): void
    {
        $transport = $this->makeTransport();
        $this->assertSame('rabbitmq', $transport->name());
    }

    public function test_connect_returns_rabbit_queue(): void
    {
        $transport = $this->makeTransport();

        $queue = $transport->connect([
            'driver' => 'rabbitmq',
            'queue' => 'default',
            // Use the lazy connection so no real TCP traffic is required for
            // construction. The lazy connection only attempts to talk to AMQP
            // when the first channel operation runs, which we never trigger
            // from a pure unit test.
            'connection' => AMQPLazyConnection::class,
            'hosts' => [[
                'host' => '127.0.0.1',
                'port' => 5672,
                'user' => 'guest',
                'password' => 'guest',
                'vhost' => '/',
            ]],
            'options' => [],
            'worker' => 'default',
        ]);

        $this->assertInstanceOf(RabbitQueue::class, $queue);
    }

    public function test_workload_logs_and_returns_zero_when_host_unreachable(): void
    {
        // Force the AMQP connect to fail by pointing at a port nothing listens on.
        $this->app['config']->set('queue.connections.rabbitmq', [
            'driver' => 'rabbitmq',
            'queue' => 'default',
            'connection' => 'default',
            'hosts' => [[
                'host' => '127.0.0.1',
                'port' => 19999,
                'user' => 'guest',
                'password' => 'guest',
                'vhost' => '/',
            ]],
            'options' => [],
            'worker' => 'default',
        ]);

        $logger = Mockery::spy(LoggerInterface::class);

        $transport = new RabbitTransport(
            container: $this->app,
            packageConfig: config('sunset'),
            logger: $logger,
        );

        $workload = $transport->workload(['orders', 'default']);
        $byName = collect($workload)->keyBy('name')->all();

        $this->assertSame(0, $byName['orders']['length']);
        $this->assertSame(0, $byName['default']['length']);
        $this->assertSame(0, $byName['orders']['processes']);
        $this->assertSame(0, $byName['default']['wait']);
        $this->assertNull($byName['orders']['split_queues']);

        $logger->shouldHaveReceived('warning')->atLeast()->once();
    }

    public function test_workload_passive_declare_happy_path_covered_by_integration(): void
    {
        $this->markTestSkipped(
            'Passive queue_declare happy path is exercised end-to-end by the '
            . 'RabbitMQ integration test suite (T5/T7); unit-mocking AMQPChannel '
            . 'is brittle and provides no additional coverage.'
        );
    }

    private function makeTransport(): RabbitTransport
    {
        return new RabbitTransport(
            container: $this->app,
            packageConfig: config('sunset'),
            logger: null,
        );
    }

    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }
}
