<?php

namespace Admnio\Sunset\Tests\Unit\Listeners;

use Admnio\Sunset\Contracts\TagRepository;
use Admnio\Sunset\Events\JobQueueing;
use Admnio\Sunset\JobPayload;
use Admnio\Sunset\Listeners\MonitorTag;
use Admnio\Sunset\Tests\TestCase;
use Mockery;

class MonitorTagTest extends TestCase
{
    public function test_promotes_to_permanent_when_any_tag_is_monitored(): void
    {
        $payload = new JobPayload(json_encode(['uuid' => 'm-1', 'tags' => ['vip', 'other']]));
        $event = new JobQueueing('sqs', 'orders', $payload);

        $tags = Mockery::mock(TagRepository::class);
        $tags->shouldReceive('monitored')->andReturn(['vip']);
        $tags->shouldReceive('addPermanent')->once()->with('m-1', ['vip', 'other']);

        (new MonitorTag($tags))->handle($event);
    }

    public function test_does_nothing_when_no_payload_tags_are_monitored(): void
    {
        $payload = new JobPayload(json_encode(['uuid' => 'm-2', 'tags' => ['plain']]));
        $event = new JobQueueing('sqs', 'orders', $payload);

        $tags = Mockery::mock(TagRepository::class);
        $tags->shouldReceive('monitored')->andReturn(['vip']);
        $tags->shouldNotReceive('addPermanent');

        (new MonitorTag($tags))->handle($event);
    }

    protected function tearDown(): void { Mockery::close(); parent::tearDown(); }
}
