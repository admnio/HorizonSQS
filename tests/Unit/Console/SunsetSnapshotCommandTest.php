<?php

namespace Admnio\Sunset\Tests\Unit\Console;

use Admnio\Sunset\Console\SunsetSnapshotCommand;
use Admnio\Sunset\Contracts\MetricsRepository;
use Admnio\Sunset\Tests\TestCase;
use Illuminate\Console\Application as Artisan;
use Mockery;

class SunsetSnapshotCommandTest extends TestCase
{
    public function test_command_has_correct_signature(): void
    {
        $command = new SunsetSnapshotCommand();

        $this->assertSame('sunset:snapshot', $command->getName());
    }

    public function test_command_description_mentions_snapshot(): void
    {
        $command = new SunsetSnapshotCommand();

        $this->assertStringContainsStringIgnoringCase('snapshot', $command->getDescription());
    }

    public function test_handle_calls_snapshot_and_reports_success(): void
    {
        $metrics = Mockery::mock(MetricsRepository::class);
        $metrics->shouldReceive('snapshot')->once();

        $this->app->instance(MetricsRepository::class, $metrics);

        Artisan::starting(function ($artisan) {
            $artisan->resolveCommands([SunsetSnapshotCommand::class]);
        });

        $this->artisan('sunset:snapshot')->assertExitCode(0);
    }

    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }
}
