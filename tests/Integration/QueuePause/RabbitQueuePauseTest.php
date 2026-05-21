<?php

namespace Admnio\Sunset\Tests\Integration\QueuePause;

use Admnio\Sunset\Contracts\QueuePauseRepository;
use Admnio\Sunset\Tests\Integration\IntegrationTestCase;
use Admnio\Sunset\Transports\Rabbit\RabbitQueue;
use Illuminate\Contracts\Redis\Factory as RedisFactory;
use Mockery;
use PhpAmqpLib\Channel\AMQPChannel;
use PhpAmqpLib\Connection\AbstractConnection;
use PhpAmqpLib\Message\AMQPMessage;
use VladimirYuldashev\LaravelQueueRabbitMQ\Queue\Jobs\RabbitMQJob;
use VladimirYuldashev\LaravelQueueRabbitMQ\Queue\QueueConfig;

/**
 * Verifies the QueuePauseGate hooks into RabbitQueue::pop() — when the
 * (connection, queue) pair is paused via the repository, pop() must return
 * null WITHOUT touching the AMQP channel (no basic_get call), because the
 * gate is consulted before the broker is hit.
 *
 * The AMQP layer itself is mocked (mirroring RabbitQueueTest::test_pop_returns_null_when_gate_rejects)
 * so this test doesn't need a live RabbitMQ broker. The pause repository runs
 * against the real Redis configured in IntegrationTestCase.
 */
class RabbitQueuePauseTest extends IntegrationTestCase
{
    /** @var \Illuminate\Redis\Connections\Connection */
    private $redis;

    protected function setUp(): void
    {
        parent::setUp();

        $this->redis = $this->app->make(RedisFactory::class)->connection('default');

        foreach ($this->redis->keys('sunset:*') as $key) {
            $name = str_replace($this->redis->_prefix(''), '', $key);
            $this->redis->del($name);
        }
    }

    protected function tearDown(): void
    {
        $this->redis->del('sunset:queues:paused');
        Mockery::close();
        parent::tearDown();
    }

    public function test_pop_returns_null_without_calling_amqp_when_queue_is_paused(): void
    {
        // The channel's basic_get must NEVER be called when the gate
        // short-circuits — shouldNotReceive('basic_get') is the load-bearing
        // assertion.
        $channel = Mockery::mock(AMQPChannel::class);
        $channel->shouldNotReceive('basic_get');

        $queue = $this->makeRabbitQueue($channel);

        $this->app->make(QueuePauseRepository::class)->pause('rabbitmq', 'orders', 'cli');

        $result = $queue->pop('orders');

        $this->assertNull($result, 'pop() must short-circuit to null when the queue is paused');
    }

    public function test_pop_reaches_amqp_when_queue_is_not_paused(): void
    {
        $body = json_encode(['id' => 'xyz', 'displayName' => 'TestJob', 'data' => [], 'attempts' => 0]);
        $channel = Mockery::mock(AMQPChannel::class);
        $channel->shouldReceive('basic_get')->once()->with('orders')->andReturn(new AMQPMessage($body));

        $queue = $this->makeRabbitQueue($channel);

        $result = $queue->pop('orders');

        $this->assertInstanceOf(RabbitMQJob::class, $result);
    }

    public function test_pop_reaches_amqp_after_resume(): void
    {
        $body = json_encode(['id' => 'xyz', 'displayName' => 'TestJob', 'data' => [], 'attempts' => 0]);
        $channel = Mockery::mock(AMQPChannel::class);
        $channel->shouldReceive('basic_get')->once()->with('orders')->andReturn(new AMQPMessage($body));

        $queue = $this->makeRabbitQueue($channel);

        $repo = $this->app->make(QueuePauseRepository::class);
        $repo->pause('rabbitmq', 'orders', 'cli');
        $this->assertNull($queue->pop('orders'));

        $repo->resume('rabbitmq', 'orders', 'cli');
        $this->assertInstanceOf(RabbitMQJob::class, $queue->pop('orders'));
    }

    private function makeRabbitQueue(AMQPChannel $channel): RabbitQueue
    {
        $queueConfig = (new QueueConfig())->setQueue('default');

        /** @var RabbitQueue|\Mockery\MockInterface $queue */
        $queue = Mockery::mock(RabbitQueue::class . '[getChannel]', [$queueConfig, []]);
        $queue->shouldAllowMockingProtectedMethods();
        $queue->shouldReceive('getChannel')->andReturn($channel);

        $conn = Mockery::mock(AbstractConnection::class);
        $conn->shouldReceive('close')->andReturnNull();
        $queue->setConnection($conn);

        $queue->setConnectionName('rabbitmq');
        $queue->setContainer($this->app);

        return $queue;
    }
}
