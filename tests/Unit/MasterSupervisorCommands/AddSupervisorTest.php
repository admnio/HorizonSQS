<?php

namespace Admnio\Sunset\Tests\Unit\MasterSupervisorCommands;

use Admnio\Sunset\MasterSupervisorCommands\AddSupervisor;
use Admnio\Sunset\Supervisor\MasterSupervisor;
use Admnio\Sunset\Supervisor\SupervisorOptions;
use Admnio\Sunset\Tests\TestCase;
use Mockery;

class AddSupervisorTest extends TestCase
{
    public function test_process_builds_supervisor_options_and_calls_add_supervisor(): void
    {
        $master = Mockery::mock(MasterSupervisor::class);
        $master->shouldReceive('addSupervisor')
            ->once()
            ->withArgs(function ($options) {
                return $options instanceof SupervisorOptions
                    && $options->name === 'test-supervisor';
            });

        $command = new AddSupervisor();
        $command->process($master, ['name' => 'test-supervisor', 'connection' => 'sqs']);
    }
}
