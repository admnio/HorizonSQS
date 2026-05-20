<?php

namespace Admnio\Sunset\Console;

use Admnio\Sunset\Contracts\MasterSupervisorRepository;
use Admnio\Sunset\Supervisor\MasterSupervisor;
use Illuminate\Console\Command;
use Illuminate\Support\Arr;
use Illuminate\Support\Str;
use Symfony\Component\Console\Attribute\AsCommand;

/**
 * @internal This class is part of Sunset's internal implementation; signatures
 *           may change between minor releases of v1.x. Consumers should depend
 *           on the published Admnio\Sunset\Contracts\* interfaces instead.
 */
#[AsCommand(name: 'sunset:continue')]
class SunsetContinueCommand extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'sunset:continue';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Instruct the master supervisor to continue processing jobs';

    /**
     * Execute the console command.
     *
     * @param  \Admnio\Sunset\Contracts\MasterSupervisorRepository  $masters
     * @return void
     */
    public function handle(MasterSupervisorRepository $masters)
    {
        $masterRecords = collect($masters->all())
            ->filter(fn ($master) => Str::startsWith($master->name, MasterSupervisor::basename()))
            ->all();

        collect(Arr::pluck($masterRecords, 'pid'))
            ->whenNotEmpty(fn () => $this->components->info('Sending continue signal to Sunset...'))
            ->whenEmpty(fn () => $this->components->info('No processes to continue.'))
            ->each(function ($processId) {
                $result = true;

                $this->components->task("Process: $processId", function () use ($processId, &$result) {
                    if (! function_exists('posix_kill')) {
                        return $result = false;
                    }

                    return $result = posix_kill($processId, SIGCONT);
                });

                if (! $result) {
                    $this->components->error("Failed to signal process: {$processId}");
                }
            })->whenNotEmpty(fn () => $this->output->writeln(''));
    }
}
