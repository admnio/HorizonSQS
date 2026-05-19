<?php

namespace Admnio\Sunset\Tests\Unit\SupervisorCommands;

use Admnio\Sunset\SupervisorCommands\Terminate;
use Admnio\Sunset\Supervisor\Supervisor;
use Admnio\Sunset\Tests\TestCase;
use Mockery;

class TerminateTest extends TestCase
{
    public function test_process_calls_terminate_with_status_from_options(): void
    {
        $supervisor = Mockery::mock(Supervisor::class);
        $supervisor->shouldReceive('terminate')->with(3)->once();

        (new Terminate())->process($supervisor, ['status' => 3]);
    }

    public function test_process_defaults_status_to_zero_when_not_provided(): void
    {
        $supervisor = Mockery::mock(Supervisor::class);
        $supervisor->shouldReceive('terminate')->with(0)->once();

        (new Terminate())->process($supervisor, []);
    }
}
