<?php

namespace Admnio\Sunset\Tests\Unit\Listeners;

use Admnio\Sunset\Events\JobCompleted;
use Admnio\Sunset\Listeners\TranslateJobProcessed;
use Admnio\Sunset\Tests\TestCase;
use Illuminate\Contracts\Queue\Job;
use Illuminate\Queue\Events\JobProcessed;
use Illuminate\Support\Facades\Event;
use Mockery;

class TranslateJobProcessedTest extends TestCase
{
    public function test_dispatches_sunset_job_completed_with_payload(): void
    {
        Event::fake([JobCompleted::class]);

        $job = Mockery::mock(Job::class);
        $job->shouldReceive('getRawBody')->andReturn(json_encode(['uuid' => 'c-1']));
        $job->shouldReceive('getQueue')->andReturn('orders');
        $job->shouldReceive('resolveName')->andReturn('App\\Jobs\\SendEmail');

        $event = new JobProcessed('sqs', $job);
        (new TranslateJobProcessed())->handle($event);

        Event::assertDispatched(JobCompleted::class, function (JobCompleted $e) {
            return $e->connectionName === 'sqs'
                && $e->queue === 'orders'
                && $e->payload->id() === 'c-1';
        });
    }

    protected function tearDown(): void { Mockery::close(); parent::tearDown(); }
}
