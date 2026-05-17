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
