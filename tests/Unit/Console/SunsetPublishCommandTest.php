<?php

namespace Admnio\Sunset\Tests\Unit\Console;

use Admnio\Sunset\Console\SunsetPublishCommand;
use Admnio\Sunset\Tests\TestCase;
use Illuminate\Console\Application as Artisan;

class SunsetPublishCommandTest extends TestCase
{
    public function test_command_has_correct_signature(): void
    {
        $command = new SunsetPublishCommand();

        $this->assertSame('sunset:publish', $command->getName());
    }

    public function test_command_has_force_option(): void
    {
        $command = new SunsetPublishCommand();

        $this->assertTrue($command->getDefinition()->hasOption('force'));
    }

    public function test_command_description_mentions_sunset(): void
    {
        $command = new SunsetPublishCommand();

        $this->assertStringContainsStringIgnoringCase('sunset', $command->getDescription());
    }

    public function test_handle_runs_and_exits_zero(): void
    {
        Artisan::starting(function ($artisan) {
            $artisan->resolveCommands([SunsetPublishCommand::class]);
        });

        $this->artisan('sunset:publish')->assertExitCode(0);
    }
}
