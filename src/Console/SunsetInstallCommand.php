<?php

namespace Admnio\Sunset\Console;

use Illuminate\Console\Command;
use Symfony\Component\Console\Attribute\AsCommand;

#[AsCommand(name: 'sunset:install')]
class SunsetInstallCommand extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'sunset:install';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Install all of the Sunset resources';

    /**
     * Execute the console command.
     *
     * @return void
     */
    public function handle()
    {
        $this->components->info('Installing Sunset resources.');

        collect([
            'Configuration' => fn () => $this->callSilent('vendor:publish', ['--tag' => 'sunset-config']) == 0,
        ])->each(fn ($task, $description) => $this->components->task($description, $task));

        $this->components->info('Sunset scaffolding installed successfully.');
    }
}
