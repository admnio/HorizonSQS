<?php

namespace Admnio\Sunset\Tests\Unit\Listeners;

use Admnio\Sunset\Contracts\JobRepository;
use Admnio\Sunset\Contracts\TagRepository;
use Admnio\Sunset\Events\JobQueueing;
use Admnio\Sunset\JobPayload;
use Admnio\Sunset\Listeners\StorePendingJob;
use Admnio\Sunset\Tests\TestCase;
use Mockery;

class StorePendingJobTest extends TestCase
{
    public function test_handles_dispatches_pushed_and_temporary_tags(): void
    {
        $payload = new JobPayload(json_encode(['uuid' => 'p-1', 'tags' => ['a', 'b']]));
        $event = new JobQueueing('sqs', 'orders', $payload);

        $jobs = Mockery::mock(JobRepository::class);
        $tags = Mockery::mock(TagRepository::class);

        $jobs->shouldReceive('pushed')->once()->with('sqs', 'orders', $payload);
        $tags->shouldReceive('addTemporary')->once()
            ->withArgs(fn ($expiresAt, $id, $tagArr) => $id === 'p-1' && $tagArr === ['a', 'b']);

        (new StorePendingJob($jobs, $tags))->handle($event);
    }

    public function test_skips_tag_write_when_no_tags(): void
    {
        $payload = new JobPayload(json_encode(['uuid' => 'p-2']));
        $event = new JobQueueing('sqs', 'orders', $payload);

        $jobs = Mockery::mock(JobRepository::class);
        $tags = Mockery::mock(TagRepository::class);
        $jobs->shouldReceive('pushed')->once();
        $tags->shouldNotReceive('addTemporary');

        (new StorePendingJob($jobs, $tags))->handle($event);
    }

    protected function tearDown(): void { Mockery::close(); parent::tearDown(); }
}
