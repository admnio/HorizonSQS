<?php

namespace Admnio\Sunset\Tests\Integration;

use Illuminate\Support\Facades\Queue;

class FifoOrderingTest extends IntegrationTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->ensureLocalStackAvailable();
        $this->deleteAllQueues();
        $url = $this->createQueue('orders.fifo', fifo: true);
        config(['queue.connections.sqs.prefix' => str_replace('/orders.fifo', '', $url)]);
    }

    public function test_fifo_preserves_order_within_group(): void
    {
        $sqs = Queue::connection('sqs');

        // Pre-shape payloads with displayName so Horizon's StoreJob listener
        // (which now fires on JobPending) can record them.
        $payloads = [];
        for ($i = 1; $i <= 3; $i++) {
            $payloads[] = json_encode([
                'uuid' => "u{$i}",
                'id' => (string) $i,
                'displayName' => 'FifoOrderingJob',
                'job' => 'Illuminate\\Queue\\CallQueuedHandler@call',
                'data' => ['commandName' => 'FifoOrderingJob', 'command' => ''],
                'seq' => $i,
                '_horizon_nonce' => "a{$i}",
            ]);
        }
        $sqs->pushRaw($payloads[0], 'orders.fifo');
        $sqs->pushRaw($payloads[1], 'orders.fifo');
        $sqs->pushRaw($payloads[2], 'orders.fifo');

        $received = [];
        for ($i = 0; $i < 3; $i++) {
            $job = $sqs->pop('orders.fifo');
            $this->assertNotNull($job, "expected job #{$i}");
            $body = json_decode($job->getRawBody(), true);
            $received[] = $body['seq'];
            // FIFO queues block subsequent receives in the same MessageGroupId
            // until the in-flight message is deleted (or visibility expires).
            $job->delete();
        }

        $this->assertSame([1, 2, 3], $received);
    }
}
