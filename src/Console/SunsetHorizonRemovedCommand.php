<?php

namespace Admnio\Sunset\Console;

use Illuminate\Console\Command;
use Symfony\Component\Console\Attribute\AsCommand;

/**
 * @internal This class is part of Sunset's internal implementation; signatures
 *           may change between minor releases of v1.x. Consumers should depend
 *           on the published Admnio\Sunset\Contracts\* interfaces instead.
 */
#[AsCommand(name: 'horizon')]
class SunsetHorizonRemovedCommand extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'horizon {--environment= : The environment name}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = '[Removed in Sunset v0.5.0 — use sunset:work]';

    /**
     * Execute the console command.
     *
     * @return int
     */
    public function handle()
    {
        $this->components->error(
            'The [horizon] command has been removed in Sunset v0.5.0. Use [sunset:work] instead.'
        );

        return 1;
    }
}
