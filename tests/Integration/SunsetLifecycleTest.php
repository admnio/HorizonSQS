<?php

namespace Admnio\Sunset\Tests\Integration;

use Admnio\Sunset\Tests\Fixtures\Jobs\RecordingJob;
use Illuminate\Contracts\Redis\Factory as RedisFactory;
use Illuminate\Queue\Events\JobProcessed as LaravelJobProcessed;
use Illuminate\Support\Facades\Queue;

class SunsetLifecycleTest extends IntegrationTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        $factory = $this->app->make(RedisFactory::class);
        $conn = $factory->connection('default');
        foreach (['sunset:*', 'horizon:*'] as $pattern) {
            foreach ($conn->keys($pattern) as $key) {
                $name = str_replace($conn->_prefix(''), '', $key);
                $conn->del($name);
            }
        }
        $conn->del('queues:sunset-lifecycle-test');
        $conn->del('queues:sunset-lifecycle-test:delayed');
        $conn->del('queues:sunset-lifecycle-test:reserved');

        @unlink(sys_get_temp_dir() . '/sunset-marker');

        config([
            'queue.default' => 'redis',
            'queue.connections.redis.queue' => 'sunset-lifecycle-test',
        ]);
    }

    public function test_push_writes_pending_record_to_sunset_keyspace(): void
    {
        Queue::push(new RecordingJob('hello'));

        $factory = $this->app->make(RedisFactory::class);
        $conn = $factory->connection('default');

        $this->assertGreaterThan(0, $conn->zcard('sunset:recent_jobs'),
            'JobQueueing → StorePendingJob should write to sunset:recent_jobs');
        $this->assertGreaterThan(0, $conn->zcard('sunset:pending_jobs'),
            'JobQueueing → StorePendingJob should write to sunset:pending_jobs');
        $this->assertSame(0, $conn->zcard('horizon:recent_jobs'),
            'no data should land in horizon:* keys');
    }

    public function test_pop_marks_job_reserved_in_sunset_keyspace(): void
    {
        Queue::push(new RecordingJob('hello'));

        $job = Queue::connection('redis')->pop('sunset-lifecycle-test');
        $this->assertNotNull($job);

        $factory = $this->app->make(RedisFactory::class);
        $conn = $factory->connection('default');
        $jobId = json_decode($job->getRawBody(), true)['uuid'] ?? null;
        $this->assertNotNull($jobId);

        $hash = $conn->hgetall("sunset:job:{$jobId}");
        $this->assertSame('reserved', $hash['status']);
    }

    public function test_process_completes_record_in_sunset_keyspace(): void
    {
        Queue::push(new RecordingJob('complete-me'));

        $job = Queue::connection('redis')->pop('sunset-lifecycle-test');
        $job->fire();

        // Job::fire() runs the handler directly but does NOT dispatch JobProcessed —
        // that is the Worker's responsibility. Fire it ourselves to simulate the
        // full worker lifecycle and trigger TranslateJobProcessed → JobCompleted →
        // MarkJobAsComplete.
        event(new LaravelJobProcessed('redis', $job));

        $factory = $this->app->make(RedisFactory::class);
        $conn = $factory->connection('default');

        $this->assertSame('complete-me', file_get_contents(sys_get_temp_dir() . '/sunset-marker'));

        $this->assertGreaterThan(0, $conn->zcard('sunset:completed_jobs'));
    }
}
