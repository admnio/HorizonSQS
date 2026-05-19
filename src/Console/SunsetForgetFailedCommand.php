<?php

namespace Admnio\Sunset\Console;

use Admnio\Sunset\Contracts\FailedJobRepository;
use Illuminate\Console\Command;
use Symfony\Component\Console\Attribute\AsCommand;

#[AsCommand(name: 'sunset:forget-failed')]
class SunsetForgetFailedCommand extends Command
{
    /**
     * The console command signature.
     *
     * @var string
     */
    protected $signature = 'sunset:forget-failed {id? : The ID of the failed job} {--all : Delete all failed jobs}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Delete a failed queue job';

    /**
     * Execute the console command.
     *
     * @param  \Admnio\Sunset\Contracts\FailedJobRepository  $repository
     * @return int|null
     */
    public function handle(FailedJobRepository $repository)
    {
        if ($this->option('all')) {
            $totalFailedCount = $repository->totalFailed();

            do {
                $failedJobs = collect($repository->getFailed());

                $failedJobs->pluck('id')->each(function ($failedId) use ($repository): void {
                    $repository->deleteFailed($failedId);

                    $this->components->info('Failed job (id): '.$failedId.' deleted successfully!');
                });
            } while ($repository->totalFailed() !== 0 && $failedJobs->isNotEmpty());

            if ($totalFailedCount) {
                $this->components->info($totalFailedCount.' failed jobs deleted successfully!');
            } else {
                $this->components->info('No failed jobs detected.');
            }

            return 0;
        }

        if (! $this->argument('id')) {
            $this->components->error('No failed job ID provided.');

            return 1;
        }

        $repository->deleteFailed($this->argument('id'));

        $this->components->info('Failed job deleted successfully!');

        return 0;
    }
}
