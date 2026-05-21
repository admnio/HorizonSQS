<?php

namespace Admnio\Sunset\Tests\Integration\QueuePause;

use Admnio\Sunset\Contracts\QueuePauseRepository;
use Admnio\Sunset\Tests\Integration\IntegrationTestCase;
use Admnio\Sunset\Transports\Sqs\Delay\DelayedJobStore;
use Admnio\Sunset\Transports\Sqs\FifoMessageAttributes;
use Admnio\Sunset\Transports\Sqs\SqsQueue;
use Aws\Sqs\SqsClient;
use Illuminate\Contracts\Redis\Factory as RedisFactory;
use Mockery;

/**
 * Verifies the QueuePauseGate hooks into SqsQueue::pop() — when the
 * (connection, queue) pair is paused via the repository, pop() must return
 * null WITHOUT touching the SqsClient (no receiveMessage call), because the
 * gate is consulted before the SQS API is hit.
 *
 * The SQS API itself is mocked (mirroring SqsQueueTest::test_pop_returns_null_when_gate_rejects)
 * so this test doesn't need a live LocalStack. The pause repository runs
 * against the real Redis configured in IntegrationTestCase.
 */
class SqsQueuePauseTest extends IntegrationTestCase
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

    public function test_pop_returns_null_without_calling_sqs_when_queue_is_paused(): void
    {
        // The SqsClient must NEVER be touched when the gate short-circuits —
        // shouldNotReceive('receiveMessage') is the load-bearing assertion.
        $sqs = Mockery::mock(SqsClient::class);
        $sqs->shouldNotReceive('receiveMessage');

        $queue = $this->makeSqsQueue($sqs);

        $this->app->make(QueuePauseRepository::class)->pause('sqs', 'default', 'cli');

        $result = $queue->pop('default');

        $this->assertNull($result, 'pop() must short-circuit to null when the queue is paused');
    }

    public function test_pop_reaches_sqs_when_queue_is_not_paused(): void
    {
        $sqs = Mockery::mock(SqsClient::class);
        $sqs->shouldReceive('receiveMessage')
            ->once()
            ->andReturn(new \Aws\Result([
                'Messages' => [[
                    'MessageId' => 'mid-1',
                    'ReceiptHandle' => 'rh-1',
                    'Body' => '{"id":"abc","tags":[]}',
                    'Attributes' => ['ApproximateReceiveCount' => 1],
                ]],
            ]));

        $queue = $this->makeSqsQueue($sqs);

        // No pause, so the gate returns false and pop() proceeds normally.
        $job = $queue->pop('default');

        $this->assertNotNull($job, 'unpaused pop() must reach the SqsClient');
    }

    public function test_pop_reaches_sqs_after_resume(): void
    {
        $sqs = Mockery::mock(SqsClient::class);
        $sqs->shouldReceive('receiveMessage')
            ->once()
            ->andReturn(new \Aws\Result([
                'Messages' => [[
                    'MessageId' => 'mid-1',
                    'ReceiptHandle' => 'rh-1',
                    'Body' => '{"id":"abc","tags":[]}',
                    'Attributes' => ['ApproximateReceiveCount' => 1],
                ]],
            ]));

        $queue = $this->makeSqsQueue($sqs);

        $repo = $this->app->make(QueuePauseRepository::class);
        $repo->pause('sqs', 'default', 'cli');
        $this->assertNull($queue->pop('default'));

        $repo->resume('sqs', 'default', 'cli');
        $this->assertNotNull($queue->pop('default'));
    }

    private function makeSqsQueue(SqsClient $sqs): SqsQueue
    {
        $queue = new SqsQueue(
            sqs: $sqs,
            default: 'default',
            prefix: 'http://localhost:4566/000000000000',
            suffix: '',
            fifoAttributes: new FifoMessageAttributes(['message_group_id' => 'queue-name', 'content_based_dedup' => true]),
            extendedPayload: null,
            delayedStore: Mockery::mock(DelayedJobStore::class),
            maxNativeDelay: 900,
            longPollSeconds: 20,
        );
        $queue->setContainer($this->app);
        $queue->setConnectionName('sqs');

        return $queue;
    }
}
