<?php

namespace Admnio\Sunset\Tests\Unit\SupervisorCommands;

use Admnio\Sunset\SupervisorCommands\Pause;
use Admnio\Sunset\Supervisor\Supervisor;
use Admnio\Sunset\Tests\TestCase;
use Mockery;

class PauseTest extends TestCase
{
    public function test_process_calls_pause_on_supervisor(): void
    {
        $supervisor = Mockery::mock(Supervisor::class);
        $supervisor->shouldReceive('pause')->once();

        (new Pause())->process($supervisor, []);
    }
}
