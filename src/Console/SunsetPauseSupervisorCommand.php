<?php

namespace Admnio\Sunset\Console;

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
#[AsCommand(name: 'sunset:pause-supervisor')]
class SunsetPauseSupervisorCommand extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'sunset:pause-supervisor
                            {name : The name of the supervisor to pause}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Pause a supervisor';

    /**
     * Execute the console command.
     *
     * @param  \Admnio\Sunset\Contracts\SupervisorRepository  $supervisors
     * @return int|void
     */
    public function handle(SupervisorRepository $supervisors)
    {
        $processId = optional(collect($supervisors->all())->first(function ($supervisor) {
            return Str::startsWith($supervisor->name, MasterSupervisor::basename())
                    && Str::endsWith($supervisor->name, $this->argument('name'));
        }))->pid;

        if (is_null($processId)) {
            $this->components->error('Failed to find a supervisor with this name');

            return 1;
        }

        $this->components->info("Sending pause signal to process: {$processId}");

        if (function_exists('posix_kill') && ! posix_kill($processId, SIGUSR2)) {
            $this->components->error("Failed to send USR2 signal to process: {$processId}");
        }
    }
}
