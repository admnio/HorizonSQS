<?php

namespace Admnio\Sunset\Tests\Unit\SupervisorCommands;

use Admnio\Sunset\SupervisorCommands\Balance;
use Admnio\Sunset\Supervisor\Supervisor;
use Admnio\Sunset\Tests\TestCase;
use Mockery;

class BalanceTest extends TestCase
{
    public function test_process_calls_balance_on_supervisor_with_options(): void
    {
        $options = ['default' => 2, 'high' => 3];

        $supervisor = Mockery::mock(Supervisor::class);
        $supervisor->shouldReceive('balance')->with($options)->once();

        (new Balance())->process($supervisor, $options);
    }
}
