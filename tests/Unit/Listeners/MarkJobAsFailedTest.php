<?php

namespace Admnio\Sunset\Tests\Unit\Listeners;

use Admnio\Sunset\Contracts\FailedJobRepository;
use Admnio\Sunset\Contracts\JobRepository;
use Admnio\Sunset\Events\JobFailed;
use Admnio\Sunset\JobPayload;
use Admnio\Sunset\Listeners\MarkJobAsFailed;
use Admnio\Sunset\Tests\TestCase;
use Mockery;
use RuntimeException;

class MarkJobAsFailedTest extends TestCase
{
    public function test_writes_failed_record_and_marks_completed_for_timeline_consistency(): void
    {
        $payload = new JobPayload(json_encode([
            'uuid' => 'f-1',
            'displayName' => 'App\\Jobs\\X',
            'exception' => 'RuntimeException',
        ]));
        $exception = new RuntimeException('boom');

        // We need the event to carry both the payload AND the exception.
        // The JobFailed event currently has the same signature as other JobEvents
        // (connection, queue, payload). The exception is conveyed via the payload's
        // decoded['exception'] OR we extend the event. For v0.4.0 we read it from
        // the payload decoded['exception_data'] which TranslateJobFailed will populate.
        $payload->set(['exception_data' => json_encode([
            'class' => 'RuntimeException',
            'message' => 'boom',
        ])]);
        $event = new JobFailed('sqs', 'orders', $payload);

        $failed = Mockery::mock(FailedJobRepository::class);
        $jobs = Mockery::mock(JobRepository::class);

        $failed->shouldReceive('failed')->once()
            ->withArgs(function ($e, $c, $q, $p) {
                return $e instanceof \Throwable && $c === 'sqs' && $q === 'orders';
            });
        $jobs->shouldReceive('completed')->once()
            ->withArgs(fn ($p, $silenced) => $silenced === false);

        (new MarkJobAsFailed($failed, $jobs))->handle($event);
    }

    protected function tearDown(): void { Mockery::close(); parent::tearDown(); }
}
