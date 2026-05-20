<?php

namespace Admnio\Sunset\Console;

use Admnio\Sunset\Contracts\SupervisorRepository;
use Illuminate\Console\Command;
use Symfony\Component\Console\Attribute\AsCommand;

/**
 * @internal This class is part of Sunset's internal implementation; signatures
 *           may change between minor releases of v1.x. Consumers should depend
 *           on the published Admnio\Sunset\Contracts\* interfaces instead.
 */
#[AsCommand(name: 'sunset:supervisors')]
class SunsetSupervisorsCommand extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'sunset:supervisors';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'List all of the supervisors';

    /**
     * Execute the console command.
     *
     * @param  \Admnio\Sunset\Contracts\SupervisorRepository  $supervisors
     * @return void
     */
    public function handle(SupervisorRepository $supervisors)
    {
        $supervisorList = $supervisors->all();

        if (empty($supervisorList)) {
            return $this->components->info('No supervisors are running.');
        }

        $this->output->writeln('');

        $this->table([
            'Name', 'PID', 'Status', 'Workers', 'Balancing',
        ], collect($supervisorList)->map(function ($supervisor) {
            return [
                $supervisor->name,
                $supervisor->pid,
                $supervisor->status,
                collect($supervisor->processes)->map(function ($count, $queue) {
                    return $queue.' ('.$count.')';
                })->implode(', '),
                $supervisor->options['balance'],
            ];
        })->all());

        $this->output->writeln('');
    }
}
