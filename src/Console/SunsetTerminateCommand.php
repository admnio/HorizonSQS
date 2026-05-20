<?php

namespace Admnio\Sunset\Console;

use Admnio\Sunset\Contracts\MasterSupervisorRepository;
use Admnio\Sunset\Supervisor\MasterSupervisor;
use Illuminate\Console\Command;
use Illuminate\Contracts\Cache\Factory as CacheFactory;
use Illuminate\Support\Arr;
use Illuminate\Support\InteractsWithTime;
use Illuminate\Support\Str;
use Symfony\Component\Console\Attribute\AsCommand;

/**
 * @internal This class is part of Sunset's internal implementation; signatures
 *           may change between minor releases of v1.x. Consumers should depend
 *           on the published Admnio\Sunset\Contracts\* interfaces instead.
 */
#[AsCommand(name: 'sunset:terminate')]
class SunsetTerminateCommand extends Command
{
    use InteractsWithTime;

    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'sunset:terminate
                            {--wait : Wait for all workers to terminate}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Terminate the master supervisor so it can be restarted';

    /**
     * Execute the console command.
     *
     * @param  \Illuminate\Contracts\Cache\Factory  $cache
     * @param  \Admnio\Sunset\Contracts\MasterSupervisorRepository  $masters
     * @return void
     */
    public function handle(CacheFactory $cache, MasterSupervisorRepository $masters)
    {
        if (config('sunset.fast_termination')) {
            $cache->forever(
                'sunset:terminate:wait', $this->option('wait')
            );
        }

        $masterRecords = collect($masters->all())
            ->filter(fn ($master) => Str::startsWith($master->name, MasterSupervisor::basename()))
            ->all();

        collect(Arr::pluck($masterRecords, 'pid'))
            ->whenNotEmpty(fn () => $this->components->info('Sending TERM signal to processes.'))
            ->whenEmpty(fn () => $this->components->info('No processes to terminate.'))
            ->each(function ($processId) {
                $result = true;

                $this->components->task("Process: $processId", function () use ($processId, &$result) {
                    if (! function_exists('posix_kill')) {
                        return $result = false;
                    }

                    return $result = posix_kill($processId, SIGTERM);
                });

                if (! $result) {
                    $this->components->error("Failed to terminate process: {$processId}");
                }
            })->whenNotEmpty(fn () => $this->output->writeln(''));

        $this->laravel['cache']->forever('illuminate:queue:restart', $this->currentTime());
    }
}
