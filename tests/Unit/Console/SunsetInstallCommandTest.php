<?php

namespace Admnio\Sunset\Tests\Unit\Console;

use Admnio\Sunset\Console\SunsetInstallCommand;
use Admnio\Sunset\Tests\TestCase;
use Illuminate\Console\Application as Artisan;

class SunsetInstallCommandTest extends TestCase
{
    public function test_command_has_correct_signature(): void
    {
        $command = new SunsetInstallCommand();

        $this->assertSame('sunset:install', $command->getName());
    }

    public function test_command_description_mentions_sunset(): void
    {
        $command = new SunsetInstallCommand();

        $this->assertStringContainsStringIgnoringCase('sunset', $command->getDescription());
    }

    public function test_handle_runs_and_exits_zero(): void
    {
        Artisan::starting(function ($artisan) {
            $artisan->resolveCommands([SunsetInstallCommand::class]);
        });

        $this->artisan('sunset:install')->assertExitCode(0);
    }
}
