<?php

namespace Admnio\Sunset\Tests\Unit\Listeners;

use Admnio\Sunset\Events\JobFailed as SunsetJobFailed;
use Admnio\Sunset\Listeners\TranslateJobFailed;
use Admnio\Sunset\Tests\TestCase;
use Illuminate\Contracts\Queue\Job;
use Illuminate\Queue\Events\JobFailed as LaravelJobFailed;
use Illuminate\Support\Facades\Event;
use Mockery;
use RuntimeException;

class TranslateJobFailedTest extends TestCase
{
    public function test_dispatches_sunset_job_failed_and_embeds_exception_data(): void
    {
        Event::fake([SunsetJobFailed::class]);

        $job = Mockery::mock(Job::class);
        $job->shouldReceive('getRawBody')->andReturn(json_encode(['uuid' => 'f-1']));
        $job->shouldReceive('getQueue')->andReturn('orders');

        $exception = new RuntimeException('boom');
        $event = new LaravelJobFailed('sqs', $job, $exception);
        (new TranslateJobFailed())->handle($event);

        Event::assertDispatched(SunsetJobFailed::class, function (SunsetJobFailed $e) {
            $decoded = json_decode($e->payload->decoded['exception_data'] ?? '', true);
            return $e->connectionName === 'sqs'
                && $e->queue === 'orders'
                && $e->payload->id() === 'f-1'
                && ($decoded['class'] ?? null) === 'RuntimeException'
                && ($decoded['message'] ?? null) === 'boom';
        });
    }

    protected function tearDown(): void { Mockery::close(); parent::tearDown(); }
}
