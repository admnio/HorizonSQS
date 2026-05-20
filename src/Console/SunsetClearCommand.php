<?php

namespace Admnio\Sunset\Console;

use Admnio\Sunset\Contracts\JobRepository;
use Illuminate\Console\Command;
use Illuminate\Console\ConfirmableTrait;
use Illuminate\Queue\QueueManager;
use Illuminate\Support\Arr;
use Symfony\Component\Console\Attribute\AsCommand;

/**
 * @internal This class is part of Sunset's internal implementation; signatures
 *           may change between minor releases of v1.x. Consumers should depend
 *           on the published Admnio\Sunset\Contracts\* interfaces instead.
 */
#[AsCommand(name: 'sunset:clear')]
class SunsetClearCommand extends Command
{
    use ConfirmableTrait;

    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'sunset:clear
                            {connection? : The name of the queue connection}
                            {--queue= : The name of the queue to clear}
                            {--force : Force the operation to run when in production}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Delete all of the jobs from the specified queue';

    /**
     * Execute the console command.
     *
     * @param  \Admnio\Sunset\Contracts\JobRepository  $jobRepository
     * @param  \Illuminate\Queue\QueueManager  $manager
     * @return int|null
     */
    public function handle(JobRepository $jobRepository, QueueManager $manager)
    {
        if (! $this->confirmToProceed()) {
            return 1;
        }

        $connection = $this->argument('connection')
            ?: Arr::first($this->laravel['config']->get('horizon.defaults', []));

        if (is_array($connection)) {
            $connection = $connection['connection'] ?? 'redis';
        }

        $connection = $connection ?: 'redis';

        if (method_exists($jobRepository, 'purge')) {
            $jobRepository->purge($queue = $this->getQueue($connection));
        } else {
            $queue = $this->getQueue($connection);
        }

        $count = $manager->connection($connection)->clear($queue);

        $this->components->info('Cleared '.$count.' jobs from the ['.$queue.'] queue.');

        return 0;
    }

    /**
     * Get the queue name to clear.
     *
     * @param  string  $connection
     * @return string
     */
    protected function getQueue($connection)
    {
        return $this->option('queue') ?: $this->laravel['config']->get(
            "queue.connections.{$connection}.queue",
            'default'
        );
    }
}
