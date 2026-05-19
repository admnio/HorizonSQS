<?php

namespace Admnio\Sunset\Tests\Unit\Events;

use Admnio\Sunset\Events\LongWaitDetected;
use Admnio\Sunset\Events\MasterSupervisorDeployed;
use Admnio\Sunset\Events\MasterSupervisorLooped;
use Admnio\Sunset\Events\SupervisorLooped;
use Admnio\Sunset\Tests\TestCase;

class SupervisorEventsTest extends TestCase
{
    public function test_master_supervisor_deployed_carries_master_name(): void
    {
        $event = new MasterSupervisorDeployed('master-abc');
        $this->assertSame('master-abc', $event->master);
    }

    public function test_master_supervisor_looped_carries_master_instance(): void
    {
        $master = new \stdClass();
        $master->name = 'master-x';
        $event = new MasterSupervisorLooped($master);
        $this->assertSame($master, $event->master);
    }

    public function test_supervisor_looped_carries_supervisor_instance(): void
    {
        $supervisor = new \stdClass();
        $event = new SupervisorLooped($supervisor);
        $this->assertSame($supervisor, $event->supervisor);
    }

    public function test_long_wait_detected_carries_connection_queue_and_wait(): void
    {
        $event = new LongWaitDetected('sqs', 'default', 60);
        $this->assertSame('sqs', $event->connection);
        $this->assertSame('default', $event->queue);
        $this->assertSame(60, $event->seconds);
    }
}
