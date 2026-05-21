<?php

namespace Admnio\Sunset\Console;

use Admnio\Sunset\Contracts\QueuePauseRepository;
use Illuminate\Console\Command;

/**
 * @internal This class is part of Sunset's internal implementation; signatures
 *           may change between minor releases of v1.x. Consumers should depend
 *           on the published Admnio\Sunset\Contracts\QueuePauseRepository
 *           contract or the `sunset:resume-queue` command name itself, which
 *           is part of the stable v1.x dashboard surface.
 *
 * v1.3.0 — operator-facing CLI for resuming a single (connection, queue) pair.
 * Mirrors SunsetPauseQueueCommand; tags the dispatched QueueResumed event
 * with actor='cli' so the activity log distinguishes scripted resumes from
 * dashboard-driven ones.
 */
class SunsetResumeQueueCommand extends Command
{
    protected $signature = 'sunset:resume-queue {connection : Queue connection name} {queue : Queue name}';

    protected $description = 'Resume a queue. Workers start popping new jobs from this (connection, queue) again.';

    public function __construct(private QueuePauseRepository $repository)
    {
        parent::__construct();
    }

    public function handle(): int
    {
        $connection = (string) $this->argument('connection');
        $queue = (string) $this->argument('queue');

        $this->repository->resume($connection, $queue, 'cli');

        $this->info(sprintf('Resumed %s:%s', $connection, $queue));

        return self::SUCCESS;
    }
}
