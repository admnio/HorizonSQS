<?php

namespace Admnio\Sunset\Tests\Unit\Listeners;

use Admnio\Sunset\Contracts\JobRepository;
use Admnio\Sunset\Events\JobReserved;
use Admnio\Sunset\JobPayload;
use Admnio\Sunset\Listeners\MarkJobAsReserved;
use Admnio\Sunset\Tests\TestCase;
use Mockery;

class MarkJobAsReservedTest extends TestCase
{
    public function test_delegates_reserved(): void
    {
        $payload = new JobPayload(json_encode(['uuid' => 'r-1']));
        $event = new JobReserved('sqs', 'orders', $payload);

        $jobs = Mockery::mock(JobRepository::class);
        $jobs->shouldReceive('reserved')->once()->with('sqs', 'orders', $payload);

        (new MarkJobAsReserved($jobs))->handle($event);

        $this->addToAssertionCount(Mockery::getContainer()->mockery_getExpectationCount());
    }

    protected function tearDown(): void { Mockery::close(); parent::tearDown(); }
}
