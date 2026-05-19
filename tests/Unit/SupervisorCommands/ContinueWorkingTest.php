<?php

namespace Admnio\Sunset\Tests\Unit\SupervisorCommands;

use Admnio\Sunset\SupervisorCommands\ContinueWorking;
use Admnio\Sunset\Supervisor\Supervisor;
use Admnio\Sunset\Tests\TestCase;
use Mockery;

class ContinueWorkingTest extends TestCase
{
    public function test_process_calls_continue_on_supervisor(): void
    {
        $supervisor = Mockery::mock(Supervisor::class);
        $supervisor->shouldReceive('continue')->once();

        (new ContinueWorking())->process($supervisor, []);
    }
}
