<?php

namespace Admnio\Sunset\Tests\Unit\Transports\Rabbit;

use Admnio\Sunset\Events\JobQueued;
use Admnio\Sunset\Events\JobQueueing;
use Admnio\Sunset\Events\JobReserved;
use Admnio\Sunset\Tests\TestCase;
use Admnio\Sunset\Transports\Rabbit\RabbitQueue;
use Admnio\Sunset\Transports\Sqs\Delay\DelayedJobStore;
use Illuminate\Support\Facades\Event;
use Mockery;
use PhpAmqpLib\Channel\AMQPChannel;
use PhpAmqpLib\Connection\AbstractConnection;
use PhpAmqpLib\Message\AMQPMessage;
use VladimirYuldashev\LaravelQueueRabbitMQ\Queue\Jobs\RabbitMQJob;
use VladimirYuldashev\LaravelQueueRabbitMQ\Queue\QueueConfig;

class RabbitQueueTest extends TestCase
{
    public function test_push_raw_dispatches_sunset_events_around_send(): void
    {
        Event::fake([JobQueueing::class, JobQueued::class]);

        // Partial-mock the protected AMQP internals (declareDestination /
        // publishBasic) so parent::pushRaw() runs without hitting a real
        // broker. Our own pushRaw override stays live so we can verify the
        // events fire around the parent call.
        $queue = $this->makeQueueWithoutBroker([
            'declareDestination',
            'publishBasic',
        ]);
        $queue->shouldAllowMockingProtectedMethods();
        $queue->shouldReceive('declareDestination')->andReturnNull();
        $queue->shouldReceive('publishBasic')->andReturn(1);

        $queue->setConnectionName('rabbitmq');
        $queue->setContainer($this->app);

        $payload = json_encode(['id' => 'abc', 'displayName' => 'TestJob', 'data' => []]);

        $queue->pushRaw($payload, 'orders');

        Event::assertDispatched(JobQueueing::class, function ($e) {
            return $e->connectionName === 'rabbitmq' && $e->queue === 'orders';
        });
        Event::assertDispatched(JobQueued::class, function ($e) {
            return $e->connectionName === 'rabbitmq' && $e->queue === 'orders';
        });
    }

    public function test_pop_dispatches_job_reserved_when_a_job_is_returned(): void
    {
        Event::fake([JobReserved::class]);

        // Stub getChannel() so parent::pop() reads a synthetic AMQPMessage
        // off the channel and constructs a real RabbitMQJob. This is the
        // closest we can get to exercising the real override without a
        // live broker.
        $body = json_encode([
            'id' => 'xyz',
            'displayName' => 'TestJob',
            'data' => [],
            'attempts' => 0,
        ]);
        $message = new AMQPMessage($body);

        $queue = $this->makeQueueWithoutBroker(['getChannel']);
        $queue->shouldAllowMockingProtectedMethods();

        $channel = Mockery::mock(AMQPChannel::class);
        $channel->shouldReceive('basic_get')->with('orders')->andReturn($message);
        $queue->shouldReceive('getChannel')->andReturn($channel);

        $queue->setConnectionName('rabbitmq');
        $queue->setContainer($this->app);

        $result = $queue->pop('orders');

        $this->assertInstanceOf(RabbitMQJob::class, $result);
        Event::assertDispatched(JobReserved::class, function ($e) {
            return $e->connectionName === 'rabbitmq' && $e->queue === 'orders';
        });
    }

    public function test_pop_does_not_dispatch_reserved_when_queue_is_empty(): void
    {
        Event::fake([JobReserved::class]);

        $queue = $this->makeQueueWithoutBroker(['getChannel']);
        $queue->shouldAllowMockingProtectedMethods();

        $channel = Mockery::mock(AMQPChannel::class);
        $channel->shouldReceive('basic_get')->with('orders')->andReturn(null);
        $queue->shouldReceive('getChannel')->andReturn($channel);

        $queue->setConnectionName('rabbitmq');
        $queue->setContainer($this->app);

        $result = $queue->pop('orders');

        $this->assertNull($result);
        Event::assertNotDispatched(JobReserved::class);
    }

    public function test_later_writes_to_delayed_job_store_and_skips_amqp(): void
    {
        Event::fake([JobQueueing::class, JobQueued::class]);

        $capturedPayload = null;
        $store = Mockery::mock(DelayedJobStore::class);
        $store->shouldReceive('buffer')
            ->once()
            ->withArgs(function (string $queueName, string $connection, string $payload, float $eta) use (&$capturedPayload) {
                $capturedPayload = $payload;
                $decoded = json_decode($payload, true);
                return $queueName === 'orders'
                    // RabbitQueue must pass its connection name so the
                    // reenqueuer routes swept jobs back to RabbitMQ rather
                    // than the previously-hardcoded SQS destination.
                    && $connection === 'rabbitmq'
                    && is_array($decoded)
                    && array_key_exists('id', $decoded)
                    && array_key_exists('type', $decoded)
                    && array_key_exists('tags', $decoded)
                    && array_key_exists('pushedAt', $decoded)
                    && $eta >= (float) time();
            });

        $this->app->instance(DelayedJobStore::class, $store);

        // Partial mock that asserts the AMQP publish path is never invoked.
        // The Mockery partial-mock stubs these protected vendor methods as
        // no-ops, so `shouldNotReceive` confirms later() never invoked them —
        // i.e. delayed dispatch never touched declareDestination / declareQueue
        // / publishBasic / laterRaw / pushRaw and never reached the broker.
        $queue = $this->makeQueueWithoutBroker([
            'declareDestination',
            'declareQueue',
            'publishBasic',
            'laterRaw',
            'pushRaw',
        ]);
        $queue->shouldAllowMockingProtectedMethods();
        $queue->shouldNotReceive('declareDestination');
        $queue->shouldNotReceive('declareQueue');
        $queue->shouldNotReceive('publishBasic');
        $queue->shouldNotReceive('laterRaw');
        $queue->shouldNotReceive('pushRaw');

        $queue->setConnectionName('rabbitmq');
        $queue->setContainer($this->app);

        $id = $queue->later(60, new \stdClass(), '', 'orders');

        // The return value MUST be the prepared payload's canonical id (not a
        // random synthetic string), so dispatch sites get a real persistent id
        // tied to the buffered job. JobPayload::id() returns `uuid` in
        // preference to `id` (the vendor RabbitMQQueue::createPayloadArray
        // injects its own `id` field; Sunset's canonical identifier is `uuid`,
        // matching SqsQueue's behavior).
        $this->assertIsString($id);
        $this->assertNotEmpty($id);
        $this->assertNotNull($capturedPayload);
        $decoded = json_decode($capturedPayload, true);
        $this->assertSame($decoded['uuid'], $id);

        // JobQueueing fires BEFORE buffer; JobQueued fires AFTER buffer.
        // Asserting both dispatched proves Sunset's instrumentation is
        // applied immediately at buffer time (not deferred to reap time).
        Event::assertDispatched(JobQueueing::class, function ($e) {
            return $e->connectionName === 'rabbitmq' && $e->queue === 'orders';
        });
        Event::assertDispatched(JobQueued::class, function ($e) {
            return $e->connectionName === 'rabbitmq' && $e->queue === 'orders';
        });
    }

    /**
     * Build a {@see RabbitQueue} partial-mock with no live AMQP broker. The
     * AMQP connection itself is a mock so any accidental passthrough call
     * lands on Mockery rather than crashing.
     *
     * @param  list<string>  $stubMethods  Method names to stub via Mockery
     *   partial-mock (you can chain `->shouldReceive(...)` afterwards).
     */
    private function makeQueueWithoutBroker(array $stubMethods = []): RabbitQueue
    {
        $queueConfig = (new QueueConfig())->setQueue('default');

        if (! empty($stubMethods)) {
            $mockSpec = sprintf('%s[%s]', RabbitQueue::class, implode(',', $stubMethods));
            /** @var RabbitQueue|\Mockery\MockInterface $queue */
            $queue = Mockery::mock($mockSpec, [$queueConfig, []]);
        } else {
            /** @var RabbitQueue|\Mockery\MockInterface $queue */
            $queue = Mockery::mock(RabbitQueue::class, [$queueConfig, []])->makePartial();
        }

        $conn = Mockery::mock(AbstractConnection::class);
        $conn->shouldReceive('close')->andReturnNull();
        $queue->setConnection($conn);

        return $queue;
    }

    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }
}
