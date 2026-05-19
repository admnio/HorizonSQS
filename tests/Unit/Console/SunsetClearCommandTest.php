<?php

namespace Admnio\Sunset\Tests\Unit\Console;

use Admnio\Sunset\Console\SunsetClearCommand;
use Admnio\Sunset\Contracts\JobRepository;
use Admnio\Sunset\Tests\TestCase;
use Illuminate\Console\Application as Artisan;
use Illuminate\Queue\QueueManager;
use Mockery;

class SunsetClearCommandTest extends TestCase
{
    public function test_command_has_correct_signature(): void
    {
        $command = new SunsetClearCommand();

        $this->assertSame('sunset:clear', $command->getName());
    }

    public function test_command_description_mentions_queue(): void
    {
        $command = new SunsetClearCommand();

        $this->assertStringContainsStringIgnoringCase('queue', $command->getDescription());
    }

    public function test_command_has_force_option(): void
    {
        $command = new SunsetClearCommand();

        $this->assertTrue($command->getDefinition()->hasOption('force'));
    }

    public function test_handle_clears_queue_and_reports_count(): void
    {
        $fakeQueue = Mockery::mock();
        $fakeQueue->shouldReceive('clear')->once()->with('default')->andReturn(5);

        $queueManager = Mockery::mock(QueueManager::class);
        $queueManager->shouldReceive('connection')->with('redis')->andReturn($fakeQueue);

        $jobRepo = Mockery::mock(JobRepository::class);

        $this->app->instance(QueueManager::class, $queueManager);
        $this->app->instance(JobRepository::class, $jobRepo);

        Artisan::starting(function ($artisan) {
            $artisan->resolveCommands([SunsetClearCommand::class]);
        });

        $this->artisan('sunset:clear', ['--force' => true])->assertExitCode(0);
    }

    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }
}
