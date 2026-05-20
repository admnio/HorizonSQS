<?php

namespace Admnio\Sunset\Console;

use Illuminate\Console\Command;
use Symfony\Component\Console\Attribute\AsCommand;

/**
 * @internal This class is part of Sunset's internal implementation; signatures
 *           may change between minor releases of v1.x. Consumers should depend
 *           on the published Admnio\Sunset\Contracts\* interfaces instead.
 */
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

        $bundle = __DIR__ . '/../../public-dist/app.js';
        if (! file_exists($bundle)) {
            $this->components->warn(
                'Dashboard bundle not found at ' . $bundle . '. '
                . 'The dashboard JS/CSS will not be published until the bundle '
                . 'is built (npm run build) and shipped with the package.'
            );
        }

        collect([
            'Configuration' => fn () => $this->callSilent('vendor:publish', ['--tag' => 'sunset-config']) == 0,
            'Dashboard assets' => fn () => $this->callSilent('vendor:publish', ['--tag' => 'sunset-assets', '--force' => true]) == 0,
        ])->each(fn ($task, $description) => $this->components->task($description, $task));

        $this->components->info('Sunset scaffolding installed successfully. Visit /sunset to see the dashboard.');
    }
}
