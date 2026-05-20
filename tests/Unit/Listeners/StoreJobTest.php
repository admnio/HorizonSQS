<?php

namespace Admnio\Sunset\Tests\Unit\Listeners;

use Admnio\Sunset\Contracts\JobRepository;
use Admnio\Sunset\Events\JobQueued;
use Admnio\Sunset\JobPayload;
use Admnio\Sunset\Listeners\StoreJob;
use Admnio\Sunset\Tests\TestCase;
use Mockery;

class StoreJobTest extends TestCase
{
    public function test_delegates_pushed_to_job_repository(): void
    {
        $payload = new JobPayload(json_encode(['uuid' => 'q-1']));
        $event = new JobQueued('sqs', 'orders', $payload);

        $jobs = Mockery::mock(JobRepository::class);
        $jobs->shouldReceive('pushed')->once()->with('sqs', 'orders', $payload);

        (new StoreJob($jobs))->handle($event);

        $this->addToAssertionCount(Mockery::getContainer()->mockery_getExpectationCount());
    }

    protected function tearDown(): void { Mockery::close(); parent::tearDown(); }
}
