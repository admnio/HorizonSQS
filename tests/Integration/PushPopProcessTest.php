<?php

namespace Admnio\Sunset\Tests\Integration;

use Illuminate\Support\Facades\Queue;
use Admnio\Sunset\Tests\Fixtures\Jobs\RecordingJob;

class PushPopProcessTest extends IntegrationTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->ensureLocalStackAvailable();
        $this->deleteAllQueues();
        $url = $this->createQueue('default');
        config(['queue.connections.sqs.prefix' => str_replace('/default', '', $url)]);
        @unlink(sys_get_temp_dir() . '/horizon-sqs-marker');
    }

    public function test_roundtrip_processes_job(): void
    {
        Queue::push(new RecordingJob('hello'));

        $job = Queue::connection('sqs')->pop('default');
        $this->assertNotNull($job);
        $job->fire();

        $this->assertSame('hello', file_get_contents(sys_get_temp_dir() . '/horizon-sqs-marker'));
    }

    public function test_pushed_payload_contains_horizon_fields(): void
    {
        Queue::push(new RecordingJob('hi'));

        $job = Queue::connection('sqs')->pop('default');
        $decoded = json_decode($job->getRawBody(), true);

        $this->assertArrayHasKey('id', $decoded);
        $this->assertArrayHasKey('pushedAt', $decoded);
        $this->assertArrayHasKey('tags', $decoded);
    }
}
