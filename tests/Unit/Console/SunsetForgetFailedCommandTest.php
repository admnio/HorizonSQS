<?php

namespace Admnio\Sunset\Tests\Unit\Console;

use Admnio\Sunset\Console\SunsetForgetFailedCommand;
use Admnio\Sunset\Contracts\FailedJobRepository;
use Admnio\Sunset\Tests\TestCase;
use Illuminate\Console\Application as Artisan;
use Mockery;

class SunsetForgetFailedCommandTest extends TestCase
{
    public function test_command_has_correct_signature(): void
    {
        $command = new SunsetForgetFailedCommand();

        $this->assertSame('sunset:forget-failed', $command->getName());
    }

    public function test_command_has_all_option(): void
    {
        $command = new SunsetForgetFailedCommand();

        $this->assertTrue($command->getDefinition()->hasOption('all'));
    }

    public function test_handle_returns_one_when_no_id_provided(): void
    {
        $repo = Mockery::mock(FailedJobRepository::class);

        $this->app->instance(FailedJobRepository::class, $repo);

        Artisan::starting(function ($artisan) {
            $artisan->resolveCommands([SunsetForgetFailedCommand::class]);
        });

        $this->artisan('sunset:forget-failed')->assertExitCode(1);
    }

    public function test_handle_deletes_failed_job_by_id(): void
    {
        $repo = Mockery::mock(FailedJobRepository::class);
        $repo->shouldReceive('deleteFailed')->once()->with('abc-123');

        $this->app->instance(FailedJobRepository::class, $repo);

        Artisan::starting(function ($artisan) {
            $artisan->resolveCommands([SunsetForgetFailedCommand::class]);
        });

        $this->artisan('sunset:forget-failed', ['id' => 'abc-123'])->assertExitCode(0);
    }

    public function test_handle_deletes_all_failed_jobs(): void
    {
        $repo = Mockery::mock(FailedJobRepository::class);
        $repo->shouldReceive('totalFailed')->twice()->andReturn(1, 0);
        $repo->shouldReceive('getFailed')->once()->andReturn(collect([(object) ['id' => 'job-1']]));
        $repo->shouldReceive('deleteFailed')->once()->with('job-1');

        $this->app->instance(FailedJobRepository::class, $repo);

        Artisan::starting(function ($artisan) {
            $artisan->resolveCommands([SunsetForgetFailedCommand::class]);
        });

        $this->artisan('sunset:forget-failed', ['--all' => true])->assertExitCode(0);
    }

    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }
}
