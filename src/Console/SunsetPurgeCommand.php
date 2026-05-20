<?php

namespace Admnio\Sunset\Console;

use Admnio\Sunset\Contracts\MasterSupervisorRepository;
use Admnio\Sunset\Contracts\ProcessRepository;
use Admnio\Sunset\Contracts\SupervisorRepository;
use Admnio\Sunset\Supervisor\MasterSupervisor;
use Illuminate\Console\Command;
use Illuminate\Support\Str;
use Symfony\Component\Console\Attribute\AsCommand;

/**
 * @internal This class is part of Sunset's internal implementation; signatures
 *           may change between minor releases of v1.x. Consumers should depend
 *           on the published Admnio\Sunset\Contracts\* interfaces instead.
 */
#[AsCommand(name: 'sunset:purge')]
class SunsetPurgeCommand extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'sunset:purge
                            {--signal=SIGTERM : The signal to send to the rogue processes}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Terminate any rogue Sunset processes';

    /**
     * @var \Admnio\Sunset\Contracts\SupervisorRepository
     */
    private $supervisors;

    /**
     * @var \Admnio\Sunset\Contracts\ProcessRepository
     */
    private $processes;

    /**
     * Create a new command instance.
     *
     * @param  \Admnio\Sunset\Contracts\SupervisorRepository  $supervisors
     * @param  \Admnio\Sunset\Contracts\ProcessRepository  $processes
     * @return void
     */
    public function __construct(
        SupervisorRepository $supervisors,
        ProcessRepository $processes,
    ) {
        parent::__construct();

        $this->supervisors = $supervisors;
        $this->processes = $processes;
    }

    /**
     * Execute the console command.
     *
     * @param  \Admnio\Sunset\Contracts\MasterSupervisorRepository  $masters
     * @return void
     */
    public function handle(MasterSupervisorRepository $masters)
    {
        $rawSignal = $this->option('signal');
        $signal = is_numeric($rawSignal)
            ? (int) $rawSignal
            : (defined($rawSignal) ? constant($rawSignal) : 15); // 15 = SIGTERM fallback

        foreach ($masters->names() as $master) {
            if (Str::startsWith($master, MasterSupervisor::basename())) {
                $this->purge($master, $signal);
            }
        }
    }

    /**
     * Purge any orphan processes.
     *
     * @param  string  $master
     * @param  int  $signal
     * @return void
     */
    public function purge($master, $signal = 15)
    {
        $expired = $this->processes->orphanedFor(
            $master, $this->supervisors->longestActiveTimeout()
        );

        collect($expired)
            ->whenNotEmpty(fn () => $this->components->info('Sending TERM signal to expired processes of ['.$master.']'))
            ->each(function ($processId) use ($master, $signal) {
                $this->components->task("Process: $processId", function () use ($processId, $signal) {
                    exec("kill -s {$signal} {$processId}");
                });

                $this->processes->forgetOrphans($master, [$processId]);
            })->whenNotEmpty(fn () => $this->output->writeln(''));
    }
}
