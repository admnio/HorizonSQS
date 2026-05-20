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
#[AsCommand(name: 'sunset:supervisor-status')]
class SunsetSupervisorStatusCommand extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'sunset:supervisor-status
                            {name : The name of the supervisor}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Show the status for a given supervisor';

    /**
     * Execute the console command.
     *
     * @param  \Admnio\Sunset\Contracts\SupervisorRepository  $supervisors
     * @return int|void
     */
    public function handle(SupervisorRepository $supervisors)
    {
        $name = $this->argument('name');

        $supervisorStatus = optional(collect($supervisors->all())->first(function ($supervisor) use ($name) {
            return Str::startsWith($supervisor->name, MasterSupervisor::basename()) &&
                Str::endsWith($supervisor->name, $name);
        }))->status;

        if (is_null($supervisorStatus)) {
            $this->components->error('Unable to find a supervisor with this name.');

            return 1;
        }

        $this->components->info("{$name} is {$supervisorStatus}");
    }
}
