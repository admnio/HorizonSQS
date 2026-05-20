<?php

namespace Admnio\Sunset\Console;

use Admnio\Sunset\Contracts\MasterSupervisorRepository;
use Admnio\Sunset\Supervisor\MasterSupervisor;
use Admnio\Sunset\Supervisor\ProvisioningPlan;
use Illuminate\Console\Command;
use Symfony\Component\Console\Attribute\AsCommand;

/**
 * @internal This class is part of Sunset's internal implementation; signatures
 *           may change between minor releases of v1.x. Consumers should depend
 *           on the published Admnio\Sunset\Contracts\* interfaces instead.
 */
#[AsCommand(name: 'sunset:work')]
class SunsetWorkCommand extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'sunset:work {--environment= : The environment name}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Start a master supervisor in the foreground';

    /**
     * Execute the console command.
     *
     * @param  \Admnio\Sunset\Contracts\MasterSupervisorRepository  $masters
     * @return void
     */
    public function handle(MasterSupervisorRepository $masters)
    {
        $client = config('database.redis.client');

        if ($client === 'phpredis' && ! extension_loaded('redis')) {
            return $this->components->error('The PHP Redis extension is not installed.');
        }

        if ($client === 'predis' && ! class_exists(\Predis\Client::class)) {
            return $this->components->error('Predis client is not installed. Run: composer require predis/predis');
        }

        if ($masters->find(MasterSupervisor::name())) {
            return $this->components->warn('A master supervisor is already running on this machine.');
        }

        $environment = $this->option('environment') ?? config('sunset.env') ?? config('app.env');

        $master = (new MasterSupervisor($environment))->handleOutputUsing(function ($type, $line) {
            $this->output->write($line);
        });

        ProvisioningPlan::get(MasterSupervisor::name())->deploy($environment);

        $this->components->info('Sunset started successfully.');

        pcntl_async_signals(true);

        pcntl_signal(SIGINT, function () use ($master) {
            $this->output->writeln('');

            $this->components->info('Shutting down.');

            return $master->terminate();
        });

        $master->monitor();
    }
}
