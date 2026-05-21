<?php

namespace Admnio\Sunset\Tests\Integration\QueuePause;

use Admnio\Sunset\Contracts\QueuePauseRepository;
use Admnio\Sunset\Tests\Integration\IntegrationTestCase;
use Admnio\Sunset\Transports\Redis\RedisQueue;
use Illuminate\Contracts\Redis\Factory as RedisFactory;

/**
 * Verifies the QueuePauseGate hooks into RedisQueue::pop() — when the
 * (connection, queue) pair is paused via the repository, pop() must return
 * null without draining the underlying Redis queue. Unpausing must restore
 * the normal pop() path.
 *
 * Mirrors the rate-limit gate's RedisQueueTest::test_pop_returns_null_when_gate_rejects
 * style — same pattern, different gate.
 */
class RedisQueuePauseTest extends IntegrationTestCase
{
    /** @var \Illuminate\Redis\Connections\Connection */
    private $redis;

    private RedisQueue $queue;

    private string $queueName;

    protected function setUp(): void
    {
        parent::setUp();

        $factory = $this->app->make(RedisFactory::class);
        $this->redis = $factory->connection('default');

        // Wipe any leftover sunset:* keys from prior runs so the pause set
        // starts empty.
        foreach ($this->redis->keys('sunset:*') as $key) {
            $name = str_replace($this->redis->_prefix(''), '', $key);
            $this->redis->del($name);
        }

        $this->queueName = 'rq-pause-test-' . uniqid();

        $this->queue = new RedisQueue($factory, 'default', 'default');
        $this->queue->setContainer($this->app);
        $this->queue->setConnectionName('redis');
    }

    protected function tearDown(): void
    {
        // Best-effort cleanup of the worker queue + pause set.
        $this->redis->del("queues:{$this->queueName}");
        $this->redis->del("queues:{$this->queueName}:reserved");
        $this->redis->del("queues:{$this->queueName}:delayed");
        $this->redis->del('sunset:queues:paused');

        parent::tearDown();
    }

    public function test_pop_returns_null_when_queue_is_paused(): void
    {
        // Enqueue a real job so we know pop() WOULD have returned something
        // if the gate weren't blocking.
        $this->redis->rpush(
            "queues:{$this->queueName}",
            json_encode(['id' => 'abc', 'displayName' => 'TestJob', 'data' => [], 'attempts' => 0])
        );

        // Pause the (connection, queue) pair via the repo so the gate's
        // isPaused() check returns true on the next pop.
        $this->app->make(QueuePauseRepository::class)->pause('redis', $this->queueName, 'cli');

        $result = $this->queue->pop($this->queueName);

        $this->assertNull($result, 'pop() must short-circuit to null when the queue is paused');

        // The underlying queue must NOT have been drained — the job is still
        // there waiting for the resume.
        $this->assertSame(
            1,
            (int) $this->redis->llen("queues:{$this->queueName}"),
            'paused pop() must not consume the underlying queue list'
        );
    }

    public function test_pop_returns_job_after_resume(): void
    {
        $this->redis->rpush(
            "queues:{$this->queueName}",
            json_encode(['id' => 'abc', 'displayName' => 'TestJob', 'data' => [], 'attempts' => 0])
        );

        $repo = $this->app->make(QueuePauseRepository::class);
        $repo->pause('redis', $this->queueName, 'cli');
        $this->assertNull($this->queue->pop($this->queueName));

        $repo->resume('redis', $this->queueName, 'cli');

        $job = $this->queue->pop($this->queueName);
        $this->assertNotNull($job, 'pop() must reach the underlying transport after resume');
    }

    public function test_pop_returns_job_when_a_different_queue_is_paused(): void
    {
        $this->redis->rpush(
            "queues:{$this->queueName}",
            json_encode(['id' => 'abc', 'displayName' => 'TestJob', 'data' => [], 'attempts' => 0])
        );

        // Pause an unrelated queue on the same connection. Cross-queue
        // isolation: the gate keys on the exact (connection, queue) pair.
        $this->app->make(QueuePauseRepository::class)->pause('redis', 'some-other-queue', 'cli');

        $job = $this->queue->pop($this->queueName);
        $this->assertNotNull($job, 'unrelated queue pause must not block this queue');
    }
}
