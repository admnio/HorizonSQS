<?php

namespace Admnio\Sunset\Tests\Unit\Listeners;

use Admnio\Sunset\Contracts\JobRepository;
use Admnio\Sunset\Contracts\MetricsRepository;
use Admnio\Sunset\Events\JobCompleted;
use Admnio\Sunset\JobPayload;
use Admnio\Sunset\Listeners\MarkJobAsComplete;
use Admnio\Sunset\Tests\TestCase;
use Mockery;

class MarkJobAsCompleteTest extends TestCase
{
    public function test_calls_completed_and_increments_throughput(): void
    {
        $payload = new JobPayload(json_encode([
            'uuid' => 'c-1',
            'displayName' => 'App\\Jobs\\SendEmail',
            'silenced' => false,
            'pushedAt' => (string) (microtime(true) - 0.5),
        ]));
        $event = new JobCompleted('sqs', 'orders', $payload);

        $jobs = Mockery::mock(JobRepository::class);
        $metrics = Mockery::mock(MetricsRepository::class);

        $jobs->shouldReceive('completed')->once()
            ->withArgs(fn ($p, $silenced) => $p === $payload && $silenced === false);
        $metrics->shouldReceive('incrementThroughput')->once()
            ->withArgs(fn ($job, $queue, $runtime) =>
                $job === 'App\\Jobs\\SendEmail' && $queue === 'orders' && $runtime > 0);

        (new MarkJobAsComplete($jobs, $metrics))->handle($event);
    }

    public function test_passes_silenced_flag_through(): void
    {
        $payload = new JobPayload(json_encode([
            'uuid' => 'c-2',
            'displayName' => 'App\\Jobs\\X',
            'silenced' => true,
            'pushedAt' => (string) microtime(true),
        ]));
        $event = new JobCompleted('sqs', 'orders', $payload);

        $jobs = Mockery::mock(JobRepository::class);
        $metrics = Mockery::mock(MetricsRepository::class);
        $jobs->shouldReceive('completed')->once()
            ->withArgs(fn ($p, $silenced) => $silenced === true);
        $metrics->shouldReceive('incrementThroughput')->once();

        (new MarkJobAsComplete($jobs, $metrics))->handle($event);
    }

    protected function tearDown(): void { Mockery::close(); parent::tearDown(); }
}
