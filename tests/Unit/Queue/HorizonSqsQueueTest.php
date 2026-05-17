<?php

namespace MasonWorkforce\HorizonSqs\Tests\Unit\Queue;

use Aws\Sqs\SqsClient;
use MasonWorkforce\HorizonSqs\Queue\Delay\DelayedJobStore;
use MasonWorkforce\HorizonSqs\Queue\HorizonSqsQueue;
use MasonWorkforce\HorizonSqs\Queue\Payload\ExtendedPayloadHandler;
use MasonWorkforce\HorizonSqs\Queue\Payload\PayloadEnricher;
use MasonWorkforce\HorizonSqs\Support\FifoMessageAttributes;
use MasonWorkforce\HorizonSqs\Tests\TestCase;
use Mockery;

class HorizonSqsQueueTest extends TestCase
{
    public function test_create_payload_adds_horizon_fields(): void
    {
        $queue = $this->makeQueue();

        $json = $queue->createPayload('Illuminate\\Queue\\CallQueuedHandler@call', 'default', new \stdClass());
        $decoded = json_decode($json, true);

        $this->assertArrayHasKey('id', $decoded);
        $this->assertArrayHasKey('pushedAt', $decoded);
        $this->assertArrayHasKey('tags', $decoded);
        $this->assertArrayHasKey('_horizon_nonce', $decoded);
    }

    public function test_push_raw_sends_to_sqs_for_short_delay(): void
    {
        $sqs = Mockery::mock(SqsClient::class);
        $sqs->shouldReceive('sendMessage')
            ->once()
            ->with(Mockery::on(function ($args) {
                return $args['QueueUrl'] === 'http://localhost:4566/000000000000/default'
                    && $args['MessageBody'] === '{"id":"abc"}'
                    && ! isset($args['DelaySeconds']);
            }))
            ->andReturn(new \Aws\Result(['MessageId' => 'mid-1']));

        $queue = $this->makeQueueWithSqs($sqs);

        $result = $queue->pushRaw('{"id":"abc"}', 'default');

        $this->assertSame('mid-1', $result);
    }

    public function test_push_raw_buffers_long_delay_in_redis(): void
    {
        $sqs = Mockery::mock(SqsClient::class);
        $sqs->shouldNotReceive('sendMessage');

        $store = Mockery::mock(DelayedJobStore::class);
        $store->shouldReceive('buffer')
            ->once()
            ->with(
                'default',
                Mockery::on(fn ($p) => is_string($p) && isset(json_decode($p, true)['id'])),
                Mockery::on(fn ($eta) => $eta > microtime(true) + 3500)
            );

        $queue = new HorizonSqsQueue(
            sqs: $sqs,
            default: 'default',
            prefix: 'http://localhost:4566/000000000000',
            suffix: '',
            enricher: new PayloadEnricher(),
            fifoAttributes: new FifoMessageAttributes(['message_group_id' => 'queue-name', 'content_based_dedup' => true]),
            extendedPayload: null,
            delayedStore: $store,
            maxNativeDelay: 900,
            longPollSeconds: 20,
        );

        $queue->later(3600, 'App\\Jobs\\Noop', '', 'default');
    }

    public function test_push_raw_includes_fifo_attributes_for_fifo_queue(): void
    {
        $sqs = Mockery::mock(SqsClient::class);
        $sqs->shouldReceive('sendMessage')
            ->once()
            ->with(Mockery::on(function ($args) {
                return $args['QueueUrl'] === 'http://localhost:4566/000000000000/orders.fifo'
                    && $args['MessageGroupId'] === 'orders.fifo'
                    && isset($args['MessageDeduplicationId']);
            }))
            ->andReturn(new \Aws\Result(['MessageId' => 'mid-2']));

        $queue = $this->makeQueueWithSqs($sqs);

        $queue->pushRaw('{"id":"abc"}', 'orders.fifo');
    }

    public function test_push_raw_spills_large_payload_to_s3(): void
    {
        $sqs = Mockery::mock(SqsClient::class);
        $sqs->shouldReceive('sendMessage')
            ->once()
            ->with(Mockery::on(function ($args) {
                $body = json_decode($args['MessageBody'], true);
                return isset($body['s3PointerKey']);
            }))
            ->andReturn(new \Aws\Result(['MessageId' => 'mid-3']));

        $extended = Mockery::mock(ExtendedPayloadHandler::class);
        $extended->shouldReceive('maybeStore')
            ->once()
            ->andReturn('{"s3PointerKey":"horizon-sqs-payloads/abc","size":300000}');

        $queue = new HorizonSqsQueue(
            sqs: $sqs,
            default: 'default',
            prefix: 'http://localhost:4566/000000000000',
            suffix: '',
            enricher: new PayloadEnricher(),
            fifoAttributes: new FifoMessageAttributes(['message_group_id' => 'queue-name', 'content_based_dedup' => true]),
            extendedPayload: $extended,
            delayedStore: Mockery::mock(DelayedJobStore::class),
            maxNativeDelay: 900,
            longPollSeconds: 20,
        );

        $queue->pushRaw(str_repeat('a', 300_000), 'default');
    }

    private function makeQueueWithSqs(SqsClient $sqs): HorizonSqsQueue
    {
        return new HorizonSqsQueue(
            sqs: $sqs,
            default: 'default',
            prefix: 'http://localhost:4566/000000000000',
            suffix: '',
            enricher: new PayloadEnricher(),
            fifoAttributes: new FifoMessageAttributes(['message_group_id' => 'queue-name', 'content_based_dedup' => true]),
            extendedPayload: null,
            delayedStore: Mockery::mock(DelayedJobStore::class),
            maxNativeDelay: 900,
            longPollSeconds: 20,
        );
    }

    public function test_pop_unwraps_extended_payload(): void
    {
        $sqs = Mockery::mock(SqsClient::class);
        $sqs->shouldReceive('receiveMessage')
            ->once()
            ->andReturn(new \Aws\Result([
                'Messages' => [[
                    'MessageId' => 'mid-1',
                    'ReceiptHandle' => 'rh-1',
                    'Body' => '{"s3PointerKey":"horizon-sqs-payloads/abc","size":300000}',
                    'Attributes' => ['ApproximateReceiveCount' => 1],
                ]],
            ]));

        $extended = Mockery::mock(ExtendedPayloadHandler::class);
        $extended->shouldReceive('maybeFetch')
            ->once()
            ->andReturn('{"id":"abc","tags":[]}');

        $queue = new HorizonSqsQueue(
            sqs: $sqs,
            default: 'default',
            prefix: 'http://localhost:4566/000000000000',
            suffix: '',
            enricher: new PayloadEnricher(),
            fifoAttributes: new FifoMessageAttributes(['message_group_id' => 'queue-name', 'content_based_dedup' => true]),
            extendedPayload: $extended,
            delayedStore: Mockery::mock(DelayedJobStore::class),
            maxNativeDelay: 900,
            longPollSeconds: 20,
        );
        $queue->setContainer($this->app);

        $job = $queue->pop('default');

        $this->assertNotNull($job);
        $this->assertSame('{"id":"abc","tags":[]}', $job->getRawBody());
    }

    private function makeQueue(): HorizonSqsQueue
    {
        return new HorizonSqsQueue(
            sqs: Mockery::mock(SqsClient::class),
            default: 'default',
            prefix: 'http://localhost:4566/000000000000',
            suffix: '',
            enricher: new PayloadEnricher(),
            fifoAttributes: new FifoMessageAttributes(['message_group_id' => 'queue-name', 'content_based_dedup' => true]),
            extendedPayload: null,
            delayedStore: Mockery::mock(DelayedJobStore::class),
            maxNativeDelay: 900,
            longPollSeconds: 20,
        );
    }

    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }
}
