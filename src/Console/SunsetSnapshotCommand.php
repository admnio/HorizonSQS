<?php

namespace Admnio\Sunset\Console;

use Admnio\Sunset\Contracts\MetricsRepository;
use Illuminate\Console\Command;
use Symfony\Component\Console\Attribute\AsCommand;

/**
 * @internal This class is part of Sunset's internal implementation; signatures
 *           may change between minor releases of v1.x. Consumers should depend
 *           on the published Admnio\Sunset\Contracts\* interfaces instead.
 */
#[AsCommand(name: 'sunset:snapshot')]
class SunsetSnapshotCommand extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'sunset:snapshot';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Store a snapshot of the queue metrics';

    /**
     * Execute the console command.
     *
     * @param  \Admnio\Sunset\Contracts\MetricsRepository  $metrics
     * @return void
     */
    public function handle(MetricsRepository $metrics)
    {
        $metrics->snapshot();

        $this->components->info('Metrics snapshot stored successfully.');
    }
}
