<?php

namespace Admnio\Sunset\Console;

use Admnio\Sunset\Contracts\MasterSupervisorRepository;
use Illuminate\Console\Command;
use Symfony\Component\Console\Attribute\AsCommand;

/**
 * @internal This class is part of Sunset's internal implementation; signatures
 *           may change between minor releases of v1.x. Consumers should depend
 *           on the published Admnio\Sunset\Contracts\* interfaces instead.
 */
#[AsCommand(name: 'sunset:status')]
class SunsetStatusCommand extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'sunset:status';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Get the current status of Sunset';

    /**
     * Execute the console command.
     *
     * @param  \Admnio\Sunset\Contracts\MasterSupervisorRepository  $masterSupervisorRepository
     * @return int
     */
    public function handle(MasterSupervisorRepository $masterSupervisorRepository)
    {
        if (! $masters = $masterSupervisorRepository->all()) {
            $this->components->error('Sunset is inactive.');

            return 1;
        }

        if (collect($masters)->contains(function ($master) {
            return $master->status === 'paused';
        })) {
            $this->components->warn('Sunset is paused.');

            return 0;
        }

        $this->components->info('Sunset is running.');

        return 0;
    }
}
