<?php

namespace Admnio\Sunset\Tests\Unit\Listeners;

use Admnio\Sunset\Contracts\JobRepository;
use Admnio\Sunset\Events\JobReleased;
use Admnio\Sunset\JobPayload;
use Admnio\Sunset\Listeners\MarkJobAsReleased;
use Admnio\Sunset\Tests\TestCase;
use Mockery;

class MarkJobAsReleasedTest extends TestCase
{
    public function test_delegates_released_with_delay_from_payload(): void
    {
        $payload = new JobPayload(json_encode(['uuid' => 'rel-1', 'delay' => 30]));
        $event = new JobReleased('sqs', 'orders', $payload);

        $jobs = Mockery::mock(JobRepository::class);
        $jobs->shouldReceive('released')->once()->with('sqs', 'orders', $payload, 30);

        (new MarkJobAsReleased($jobs))->handle($event);
    }

    public function test_defaults_delay_to_zero_when_absent(): void
    {
        $payload = new JobPayload(json_encode(['uuid' => 'rel-2']));
        $event = new JobReleased('sqs', 'orders', $payload);

        $jobs = Mockery::mock(JobRepository::class);
        $jobs->shouldReceive('released')->once()->with('sqs', 'orders', $payload, 0);

        (new MarkJobAsReleased($jobs))->handle($event);
    }

    protected function tearDown(): void { Mockery::close(); parent::tearDown(); }
}
