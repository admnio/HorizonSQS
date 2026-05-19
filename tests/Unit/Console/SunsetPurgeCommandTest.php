<?php

namespace Admnio\Sunset\Tests\Unit\Console;

use Admnio\Sunset\Console\SunsetPurgeCommand;
use Admnio\Sunset\Contracts\MasterSupervisorRepository;
use Admnio\Sunset\Contracts\ProcessRepository;
use Admnio\Sunset\Contracts\SupervisorRepository;
use Admnio\Sunset\Tests\TestCase;
use Illuminate\Console\Application as Artisan;
use Mockery;

class SunsetPurgeCommandTest extends TestCase
{
    public function test_command_has_correct_signature(): void
    {
        $supervisors = Mockery::mock(SupervisorRepository::class);
        $processes = Mockery::mock(ProcessRepository::class);
        $command = new SunsetPurgeCommand($supervisors, $processes);

        $this->assertSame('sunset:purge', $command->getName());
    }

    public function test_command_description_mentions_processes(): void
    {
        $supervisors = Mockery::mock(SupervisorRepository::class);
        $processes = Mockery::mock(ProcessRepository::class);
        $command = new SunsetPurgeCommand($supervisors, $processes);

        $this->assertStringContainsStringIgnoringCase('processes', $command->getDescription());
    }

    public function test_handle_does_nothing_when_no_masters(): void
    {
        $masters = Mockery::mock(MasterSupervisorRepository::class);
        $masters->shouldReceive('names')->once()->andReturn([]);

        $supervisors = Mockery::mock(SupervisorRepository::class);
        $processes = Mockery::mock(ProcessRepository::class);

        $this->app->instance(MasterSupervisorRepository::class, $masters);
        $this->app->instance(SupervisorRepository::class, $supervisors);
        $this->app->instance(ProcessRepository::class, $processes);

        Artisan::starting(function ($artisan) {
            $artisan->resolveCommands([SunsetPurgeCommand::class]);
        });

        $this->artisan('sunset:purge')->assertExitCode(0);
    }

    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }
}
