<?php

namespace Admnio\Sunset\Tests\Unit\Events;

use Admnio\Sunset\Events\JobCompleted;
use Admnio\Sunset\Events\JobFailed;
use Admnio\Sunset\Events\JobQueued;
use Admnio\Sunset\Events\JobQueueing;
use Admnio\Sunset\Events\JobReleased;
use Admnio\Sunset\Events\JobReserved;
use Admnio\Sunset\JobPayload;
use Admnio\Sunset\Tests\TestCase;

class JobEventTest extends TestCase
{
    public function test_each_concrete_event_carries_connection_queue_and_payload(): void
    {
        $payload = new JobPayload(json_encode(['uuid' => 'a']));

        foreach ([
            JobQueueing::class,
            JobQueued::class,
            JobReserved::class,
            JobReleased::class,
            JobCompleted::class,
            JobFailed::class,
        ] as $eventClass) {
            $event = new $eventClass('redis', 'orders', $payload);

            $this->assertSame('redis', $event->connectionName, $eventClass . '->connectionName');
            $this->assertSame('orders', $event->queue, $eventClass . '->queue');
            $this->assertSame($payload, $event->payload, $eventClass . '->payload');
        }
    }
}
