<?php

namespace Admnio\Sunset\Tests\Unit\SupervisorCommands;

use Admnio\Sunset\SupervisorCommands\Restart;
use Admnio\Sunset\Supervisor\Supervisor;
use Admnio\Sunset\Tests\TestCase;
use Mockery;

class RestartTest extends TestCase
{
    public function test_process_calls_restart_on_supervisor(): void
    {
        $supervisor = Mockery::mock(Supervisor::class);
        $supervisor->shouldReceive('restart')->once();

        (new Restart())->process($supervisor, []);
    }
}
