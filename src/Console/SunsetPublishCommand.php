<?php

namespace Admnio\Sunset\Console;

use Illuminate\Console\Command;
use Symfony\Component\Console\Attribute\AsCommand;

#[AsCommand(name: 'sunset:publish')]
class SunsetPublishCommand extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'sunset:publish
                            {--force : Overwrite any existing files}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Publish all of the Sunset resources';

    /**
     * Execute the console command.
     *
     * @return void
     */
    public function handle()
    {
        $this->components->info('Publishing Sunset resources.');

        $params = ['--tag' => 'sunset-config'];

        if ($this->option('force')) {
            $params['--force'] = true;
        }

        collect([
            'Configuration' => fn () => $this->callSilent('vendor:publish', $params) == 0,
        ])->each(fn ($task, $description) => $this->components->task($description, $task));

        $this->components->info('Sunset resources published successfully.');
    }
}
