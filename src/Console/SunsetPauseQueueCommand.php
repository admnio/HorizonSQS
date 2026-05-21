<?php

namespace Admnio\Sunset\Console;

use Admnio\Sunset\Contracts\QueuePauseRepository;
use Illuminate\Console\Command;

/**
 * @internal This class is part of Sunset's internal implementation; signatures
 *           may change between minor releases of v1.x. Consumers should depend
 *           on the published Admnio\Sunset\Contracts\QueuePauseRepository
 *           contract or the `sunset:pause-queue` command name itself, which is
 *           part of the stable v1.x dashboard surface.
 *
 * v1.3.0 — operator-facing CLI for pausing a single (connection, queue) pair.
 * Useful for scripting pauses outside the dashboard (e.g. a deploy script that
 * pauses a queue before a downstream maintenance window). Tags the dispatched
 * QueuePaused event with actor='cli' so the activity log distinguishes
 * scripted pauses from dashboard-driven ones.
 */
class SunsetPauseQueueCommand extends Command
{
    protected $signature = 'sunset:pause-queue {connection : Queue connection name} {queue : Queue name}';

    protected $description = 'Pause a queue. Workers stop popping new jobs from this (connection, queue) until resumed.';

    public function __construct(private QueuePauseRepository $repository)
    {
        parent::__construct();
    }

    public function handle(): int
    {
        $connection = (string) $this->argument('connection');
        $queue = (string) $this->argument('queue');

        $this->repository->pause($connection, $queue, 'cli');

        $this->info(sprintf('Paused %s:%s', $connection, $queue));

        return self::SUCCESS;
    }
}
