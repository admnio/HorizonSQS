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
#[AsCommand(name: 'sunset:continue-supervisor')]
class SunsetContinueSupervisorCommand extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'sunset:continue-supervisor
                            {name : The name of the supervisor to resume}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Instruct the supervisor to continue processing jobs';

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

        $this->components->info("Sending continue signal to process: {$processId}");

        if (function_exists('posix_kill') && ! posix_kill($processId, SIGCONT)) {
            $this->components->error("Failed to send CONT signal to process: {$processId}");
        }
    }
}
