<?php

namespace Admnio\Sunset;

use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Contracts\Cache\Repository as Cache;
use Illuminate\Contracts\Queue\Factory as QueueFactory;
use Illuminate\Contracts\Redis\Factory as RedisFactory;
use Illuminate\Queue\QueueManager;
use Illuminate\Support\ServiceProvider;
use Laravel\Horizon\Contracts\MetricsRepository;
use Laravel\Horizon\Contracts\SupervisorRepository;
use Laravel\Horizon\Contracts\WorkloadRepository;
use Admnio\Sunset\Adapters\Horizon\HorizonJobRepositoryAdapter;
use Admnio\Sunset\Adapters\Horizon\HorizonTagRepositoryAdapter;
use Admnio\Sunset\Adapters\Horizon\HorizonMetricsRepositoryAdapter;
use Admnio\Sunset\Adapters\Horizon\HorizonMasterSupervisorRepositoryAdapter;
use Admnio\Sunset\Adapters\Horizon\HorizonSupervisorRepositoryAdapter;
use Admnio\Sunset\Adapters\Horizon\HorizonProcessRepositoryAdapter;
use Admnio\Sunset\Adapters\Horizon\HorizonSupervisorCommandQueueAdapter;
use Admnio\Sunset\Console\SunsetMigrateHorizonKeysCommand;
use Admnio\Sunset\Console\SunsetMigrateRedisKeysCommand;
use Admnio\Sunset\Console\SweepDelayedCommand;
use Admnio\Sunset\Console\SunsetWorkCommand;
use Admnio\Sunset\Console\SunsetSuperviseCommand;
use Admnio\Sunset\Console\SunsetWorkerCommand;
use Admnio\Sunset\Console\SunsetPauseCommand;
use Admnio\Sunset\Console\SunsetContinueCommand;
use Admnio\Sunset\Console\SunsetPauseSupervisorCommand;
use Admnio\Sunset\Console\SunsetContinueSupervisorCommand;
use Admnio\Sunset\Console\SunsetStatusCommand;
use Admnio\Sunset\Console\SunsetSupervisorsCommand;
use Admnio\Sunset\Console\SunsetSupervisorStatusCommand;
use Admnio\Sunset\Console\SunsetTerminateCommand;
use Admnio\Sunset\Console\SunsetClearCommand;
use Admnio\Sunset\Console\SunsetPurgeCommand;
use Admnio\Sunset\Console\SunsetSnapshotCommand;
use Admnio\Sunset\Console\SunsetForgetFailedCommand;
use Admnio\Sunset\Console\SunsetInstallCommand;
use Admnio\Sunset\Console\SunsetPublishCommand;
use Admnio\Sunset\Console\SunsetMigrateHorizonConfigCommand;
use Admnio\Sunset\Console\SunsetHorizonRemovedCommand;
use Admnio\Sunset\Contracts\JobRepository as SunsetJobRepository;
use Admnio\Sunset\Contracts\FailedJobRepository as SunsetFailedJobRepository;
use Admnio\Sunset\Contracts\TagRepository as SunsetTagRepository;
use Admnio\Sunset\Contracts\MetricsRepository as SunsetMetricsRepository;
use Admnio\Sunset\Contracts\MasterSupervisorRepository as SunsetMasterSupervisorRepository;
use Admnio\Sunset\Contracts\SupervisorRepository as SunsetSupervisorRepository;
use Admnio\Sunset\Contracts\ProcessRepository as SunsetProcessRepository;
use Admnio\Sunset\Contracts\SupervisorCommandQueue as SunsetSupervisorCommandQueue;
use Admnio\Sunset\Repositories\Redis\RedisMasterSupervisorRepository;
use Admnio\Sunset\Repositories\Redis\RedisSupervisorRepository;
use Admnio\Sunset\Repositories\Redis\RedisProcessRepository;
use Admnio\Sunset\Repositories\Redis\RedisSupervisorCommandQueue;
use Admnio\Sunset\Events\JobQueueing;
use Admnio\Sunset\Events\JobQueued;
use Admnio\Sunset\Events\JobReserved;
use Admnio\Sunset\Events\JobReleased;
use Admnio\Sunset\Events\JobCompleted;
use Admnio\Sunset\Events\JobFailed as SunsetJobFailed;
use Admnio\Sunset\Exceptions\InvalidConfigurationException;
use Admnio\Sunset\Listeners\CleanupExtendedPayload;
use Admnio\Sunset\Listeners\StorePendingJob;
use Admnio\Sunset\Listeners\MonitorTag;
use Admnio\Sunset\Listeners\StoreJob;
use Admnio\Sunset\Listeners\MarkJobAsReserved;
use Admnio\Sunset\Listeners\MarkJobAsReleased;
use Admnio\Sunset\Listeners\MarkJobAsComplete;
use Admnio\Sunset\Listeners\MarkJobAsFailed;
use Admnio\Sunset\Listeners\TranslateJobProcessed;
use Admnio\Sunset\Listeners\TranslateJobFailed;
use Admnio\Sunset\Repositories\Redis\RedisJobRepository;
use Admnio\Sunset\Repositories\Redis\RedisFailedJobRepository;
use Admnio\Sunset\Repositories\Redis\RedisTagRepository;
use Admnio\Sunset\Repositories\Redis\RedisMetricsRepository;
use Admnio\Sunset\Transports\Sqs\Delay\DelayedJobReenqueuer;
use Admnio\Sunset\Transports\Sqs\Delay\DelayedJobStore;
use Admnio\Sunset\Support\TransportRegistry;
use Admnio\Sunset\Transports\Sqs\SqsConnector;
use Admnio\Sunset\Transports\Sqs\SqsTransport;
use Admnio\Sunset\Transports\Sqs\Payload\ExtendedPayloadHandler;
use Admnio\Sunset\Repositories\SunsetWorkloadRepository;
use Psr\Log\LoggerInterface;

class SunsetServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__ . '/../config/sunset.php', 'sunset');

        $this->app->singleton(TransportRegistry::class, function ($app) {
            $registry = new TransportRegistry();

            $registry->register(new SqsTransport(
                container: $app,
                redis: $app->make(RedisFactory::class),
                packageConfig: $this->validatedPackageConfig($app['config']->get('sunset')),
                queuePrefix: $app['config']->get('queue.connections.sqs.prefix', ''),
                sqsClient: null,
                logger: $app->make(LoggerInterface::class),
            ));

            $registry->register(new \Admnio\Sunset\Transports\Redis\RedisTransport(
                redis: $app->make(RedisFactory::class),
                packageConfig: $this->validatedPackageConfig($app['config']->get('sunset')),
                logger: $app->make(LoggerInterface::class),
            ));

            $registry->register(new \Admnio\Sunset\Transports\Rabbit\RabbitTransport(
                container: $app,
                packageConfig: $this->validatedPackageConfig($app['config']->get('sunset')),
                logger: $app->make(LoggerInterface::class),
            ));

            return $registry;
        });

        $this->app->singleton(SqsConnector::class, function ($app) {
            return new SqsConnector($app->make(TransportRegistry::class));
        });

        $this->app->singleton(\Admnio\Sunset\Transports\Redis\RedisConnector::class, function ($app) {
            return new \Admnio\Sunset\Transports\Redis\RedisConnector(
                $app->make(TransportRegistry::class)
            );
        });

        $this->app->singleton(\Admnio\Sunset\Transports\Rabbit\RabbitConnector::class, function ($app) {
            return new \Admnio\Sunset\Transports\Rabbit\RabbitConnector(
                $app->make(TransportRegistry::class)
            );
        });

        // Register the ExtendedPayloadHandler binding unconditionally so listener
        // resolution works regardless of when extended_payload.enabled is set in
        // the config (which may happen in test environments after boot).
        //
        // The S3 client config is inlined here rather than sharing a helper with
        // SqsTransport::normalizeAwsConfig() — the duplication is at the
        // boundary (one-time service wiring), not in a hot path. If it grows
        // noisy a future cleanup can extract a small helper.
        $this->app->singleton(ExtendedPayloadHandler::class, function ($app) {
            $queueConfig = $app['config']->get('queue.connections.sqs', []);
            $sqsTransport = $app['config']->get('sunset.transports.sqs', []);

            $s3Config = [
                'region' => $queueConfig['region'] ?? 'us-east-1',
                'version' => 'latest',
            ];
            if (! empty($queueConfig['key']) && ! empty($queueConfig['secret'])) {
                $s3Config['credentials'] = [
                    'key' => $queueConfig['key'],
                    'secret' => $queueConfig['secret'],
                ];
                if (! empty($queueConfig['token'])) {
                    $s3Config['credentials']['token'] = $queueConfig['token'];
                }
            }
            if (! empty($queueConfig['endpoint'])) {
                $s3Config['endpoint'] = $queueConfig['endpoint'];
                $s3Config['use_path_style_endpoint'] = true;
            }

            return new ExtendedPayloadHandler(
                new \Aws\S3\S3Client($s3Config),
                $sqsTransport['extended_payload']['bucket'] ?? '',
                $sqsTransport['extended_payload']['prefix'] ?? ''
            );
        });

        $this->app->singleton(DelayedJobStore::class, function ($app) {
            return new DelayedJobStore(
                $app->make(RedisFactory::class),
                $app['config']->get('sunset.redis_connection')
            );
        });

        $this->app->singleton(DelayedJobReenqueuer::class, function ($app) {
            return new DelayedJobReenqueuer(
                store: $app->make(DelayedJobStore::class),
                queues: $app->make(QueueFactory::class),
                logger: $app->make(LoggerInterface::class),
                sweepIntervalSeconds: (int) $app['config']->get('sunset.transports.sqs.long_delay_sweep_interval', 60),
            );
        });

        $this->app->singleton(WorkloadRepository::class, $this->workloadRepositoryFactory());

        // Bind Sunset contracts to Redis implementations.
        $this->app->singleton(SunsetJobRepository::class, RedisJobRepository::class);
        $this->app->singleton(SunsetFailedJobRepository::class, RedisFailedJobRepository::class);
        $this->app->singleton(SunsetTagRepository::class, RedisTagRepository::class);
        $this->app->singleton(SunsetMetricsRepository::class, RedisMetricsRepository::class);

        // v0.5.0: Bind Sunset supervisor contracts to Redis implementations.
        $this->app->singleton(SunsetMasterSupervisorRepository::class, RedisMasterSupervisorRepository::class);
        $this->app->singleton(SunsetSupervisorRepository::class, RedisSupervisorRepository::class);
        $this->app->singleton(SunsetProcessRepository::class, RedisProcessRepository::class);
        $this->app->singleton(SunsetSupervisorCommandQueue::class, RedisSupervisorCommandQueue::class);

        // Bind Horizon contracts to our adapters (initial binding in register()).
        // These will be re-bound in boot() to win against Horizon's own register()
        // which runs after ours and overwrites these.
        $this->bindHorizonAdapters();

        // v0.5.0: Bind Horizon supervisor contracts to Sunset adapters (initial binding).
        $this->bindSupervisorAdapters();
    }

    public function boot(): void
    {
        if ($this->app->runningInConsole()) {
            $this->publishes([
                __DIR__ . '/../config/sunset.php' => config_path('sunset.php'),
            ], 'sunset-config');

            $this->commands([
                // v0.4.0:
                SunsetMigrateRedisKeysCommand::class,
                SweepDelayedCommand::class,
                SunsetMigrateHorizonKeysCommand::class,

                // v0.5.0 process tree:
                SunsetWorkCommand::class,
                SunsetSuperviseCommand::class,
                SunsetWorkerCommand::class,

                // v0.5.0 control:
                SunsetPauseCommand::class,
                SunsetContinueCommand::class,
                SunsetPauseSupervisorCommand::class,
                SunsetContinueSupervisorCommand::class,

                // v0.5.0 status:
                SunsetStatusCommand::class,
                SunsetSupervisorsCommand::class,
                SunsetSupervisorStatusCommand::class,

                // v0.5.0 terminate:
                SunsetTerminateCommand::class,

                // v0.5.0 maintenance:
                SunsetClearCommand::class,
                SunsetPurgeCommand::class,
                SunsetSnapshotCommand::class,
                SunsetForgetFailedCommand::class,

                // v0.5.0 operator:
                SunsetInstallCommand::class,
                SunsetPublishCommand::class,

                // v0.5.0 migration:
                SunsetMigrateHorizonConfigCommand::class,

                // v0.5.0 horizon stub (override):
                SunsetHorizonRemovedCommand::class,
            ]);
        }

        // NOTE: connector registration is deferred to a booted() callback below
        // so it runs AFTER every other service provider's boot(). Vendor
        // packages (e.g. vladimir-yuldashev/laravel-queue-rabbitmq) register
        // their own connectors under names like 'rabbitmq' inside their own
        // boot(). Real-world Laravel auto-discovery sorts package providers
        // alphabetically by composer name, so `admnio/sunset` boots before
        // `vladimir-yuldashev/laravel-queue-rabbitmq`. Registering directly
        // inside boot() would let the vendor's later boot() overwrite our
        // binding, defeating the whole subclass. booted() runs after all
        // boot()s, so our registrations win unconditionally.

        // Clean up S3 spillover objects when a job completes successfully on the
        // sqs connection. Listener short-circuits internally when the body is
        // not an S3 pointer, so registering it unconditionally is safe and
        // simplifies the test path (config can be set after register()).
        if ($this->app['config']->get('sunset.transports.sqs.extended_payload.enabled', false)) {
            $this->app['events']->listen(
                \Illuminate\Queue\Events\JobProcessed::class,
                CleanupExtendedPayload::class
            );
        }

        // Re-bind WorkloadRepository in boot() to override Horizon's own binding,
        // since Horizon's ServiceProvider registers after ours (alphabetical order).
        $this->app->singleton(WorkloadRepository::class, $this->workloadRepositoryFactory());

        // Re-bind Horizon adapters in boot() to win against Horizon's own register(),
        // which runs after ours and would otherwise overwrite our bindings.
        $this->bindHorizonAdapters();

        // v0.5.0: Re-bind Horizon supervisor adapters in boot() for the same reason.
        $this->bindSupervisorAdapters();

        // Register Sunset event → listener map.
        $events = $this->app['events'];

        $sunsetListenerMap = [
            JobQueueing::class  => [StorePendingJob::class, MonitorTag::class],
            JobQueued::class    => [StoreJob::class],
            JobReserved::class  => [MarkJobAsReserved::class],
            JobReleased::class  => [MarkJobAsReleased::class],
            JobCompleted::class => [MarkJobAsComplete::class],
            SunsetJobFailed::class => [MarkJobAsFailed::class],
        ];

        foreach ($sunsetListenerMap as $event => $listeners) {
            foreach ($listeners as $listener) {
                $events->listen($event, $listener);
            }
        }

        // Register translators on Laravel's stock queue events.
        $events->listen(\Illuminate\Queue\Events\JobProcessed::class, TranslateJobProcessed::class);
        $events->listen(\Illuminate\Queue\Events\JobFailed::class, TranslateJobFailed::class);

        $this->app->booted(function () use ($events, $sunsetListenerMap) {
            $schedule = $this->app->make(Schedule::class);

            $schedule->command('sunset:sweep-delayed')
                ->everyMinute()
                ->withoutOverlapping()
                ->name('sunset-sweep-delayed');

            // Schedule metrics snapshot every 5 minutes via the dedicated command.
            $schedule->command('sunset:snapshot')
                ->everyFiveMinutes()
                ->name('sunset-snapshot')
                ->withoutOverlapping();

            // Forget any Horizon listeners that may have registered on Sunset
            // events, then re-register our own listeners.
            foreach ($sunsetListenerMap as $event => $listeners) {
                $events->forget($event);
                foreach ($listeners as $listener) {
                    $events->listen($event, $listener);
                }
            }

            // Register transport connectors in booted() so any vendor provider
            // that also registers a connector under the same name (e.g.
            // `vladimir-yuldashev/laravel-queue-rabbitmq`) runs FIRST in its
            // boot(); our addConnector() then overwrites theirs and wins. See
            // SunsetServiceProviderRabbitConnectorRaceTest for the regression.
            $manager = $this->app->make('queue');
            if ($manager instanceof QueueManager) {
                $manager->addConnector('sqs', fn () => $this->app->make(SqsConnector::class));
                $manager->addConnector('redis', fn () => $this->app->make(
                    \Admnio\Sunset\Transports\Redis\RedisConnector::class
                ));
                $manager->addConnector('rabbitmq', fn () => $this->app->make(
                    \Admnio\Sunset\Transports\Rabbit\RabbitConnector::class
                ));
            }
        });

        // v0.5.0: Override Horizon's `horizon` artisan command with our stub.
        // We use booted() so this runs after HorizonServiceProvider::boot() has
        // registered Horizon's own `horizon` command; our add() call overwrites it.
        $this->app->booted(function () {
            if (! $this->app->runningInConsole()) {
                return;
            }
            \Illuminate\Console\Application::starting(function ($artisan) {
                $artisan->add(
                    $this->app->make(\Admnio\Sunset\Console\SunsetHorizonRemovedCommand::class)
                );
            });
        });
    }

    private function bindSupervisorAdapters(): void
    {
        $this->app->singleton(
            \Laravel\Horizon\Contracts\MasterSupervisorRepository::class,
            fn ($app) => new HorizonMasterSupervisorRepositoryAdapter(
                $app->make(SunsetMasterSupervisorRepository::class)
            )
        );

        $this->app->singleton(
            \Laravel\Horizon\Contracts\SupervisorRepository::class,
            fn ($app) => new HorizonSupervisorRepositoryAdapter(
                $app->make(SunsetSupervisorRepository::class)
            )
        );

        $this->app->singleton(
            \Laravel\Horizon\Contracts\ProcessRepository::class,
            fn ($app) => new HorizonProcessRepositoryAdapter(
                $app->make(SunsetProcessRepository::class)
            )
        );

        $this->app->singleton(
            \Laravel\Horizon\Contracts\HorizonCommandQueue::class,
            fn ($app) => new HorizonSupervisorCommandQueueAdapter(
                $app->make(SunsetSupervisorCommandQueue::class)
            )
        );
    }

    private function bindHorizonAdapters(): void
    {
        $this->app->singleton(
            \Laravel\Horizon\Contracts\JobRepository::class,
            fn ($app) => new HorizonJobRepositoryAdapter(
                $app->make(SunsetJobRepository::class),
                $app->make(SunsetFailedJobRepository::class),
            )
        );

        $this->app->singleton(
            \Laravel\Horizon\Contracts\TagRepository::class,
            fn ($app) => new HorizonTagRepositoryAdapter(
                $app->make(SunsetTagRepository::class)
            )
        );

        $this->app->singleton(
            MetricsRepository::class,
            fn ($app) => new HorizonMetricsRepositoryAdapter(
                $app->make(SunsetMetricsRepository::class),
            )
        );
    }

    private function workloadRepositoryFactory(): \Closure
    {
        return function ($app) {
            return new SunsetWorkloadRepository(
                transports: $app->make(TransportRegistry::class),
                metrics: $app->make(MetricsRepository::class),
                supervisors: $app->make(SupervisorRepository::class),
                cache: $app->make(Cache::class),
                queues: $this->resolveQueueList($app),
                cacheTtlSeconds: (int) $app['config']->get('sunset.workload_cache_ttl', 5),
            );
        };
    }

    private function validatedPackageConfig(array $config): array
    {
        $redisConn = $config['redis_connection'] ?? null;
        if (! $redisConn || ! $this->app['config']->get("database.redis.{$redisConn}")) {
            throw new InvalidConfigurationException(
                "Sunset: redis_connection '{$redisConn}' is not defined in database.redis."
            );
        }

        $sqsTransportConfig = $config['transports']['sqs'] ?? [];
        if (($sqsTransportConfig['extended_payload']['enabled'] ?? false)
            && empty($sqsTransportConfig['extended_payload']['bucket'])) {
            throw new InvalidConfigurationException(
                'Sunset: transports.sqs.extended_payload.enabled is true but no bucket is configured.'
            );
        }

        return $config;
    }

    private function resolveQueueList($app): array
    {
        $env = $app->environment();
        $supervisors = $app['config']->get("horizon.environments.{$env}", []);
        $queues = [];
        foreach ($supervisors as $supervisor) {
            foreach ((array) ($supervisor['queue'] ?? []) as $q) {
                $queues[] = $q;
            }
        }
        return array_values(array_unique($queues)) ?: [$app['config']->get('queue.connections.sqs.queue', 'default')];
    }
}
