<?php

namespace Admnio\Sunset\Tests\Unit\SupervisorCommands;

use Admnio\Sunset\SupervisorCommands\Scale;
use Admnio\Sunset\Supervisor\Supervisor;
use Admnio\Sunset\Tests\TestCase;
use Mockery;

class ScaleTest extends TestCase
{
    public function test_process_calls_scale_on_supervisor_with_count_from_options(): void
    {
        $supervisor = Mockery::mock(Supervisor::class);
        $supervisor->shouldReceive('scale')->with(5)->once();

        (new Scale())->process($supervisor, ['scale' => 5]);
    }
}
