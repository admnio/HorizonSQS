<?php

namespace Admnio\Sunset;

use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Contracts\Cache\Repository as Cache;
use Illuminate\Contracts\Queue\Factory as QueueFactory;
use Illuminate\Contracts\Redis\Factory as RedisFactory;
use Illuminate\Queue\QueueManager;
use Illuminate\Support\ServiceProvider;
use Admnio\Sunset\Console\SunsetMigrateHorizonKeysCommand;
use Admnio\Sunset\Console\SunsetMigrateRedisKeysCommand;
use Admnio\Sunset\Console\SunsetSweepRateLimitSlotsCommand;
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
use Admnio\Sunset\Contracts\WorkloadRepository as SunsetWorkloadRepositoryContract;
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
use Admnio\Sunset\Manager;
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

        // Defensive: sunset:worker / sunset:supervise extend Laravel's
        // WorkCommand which type-hints Illuminate\Queue\Worker. Laravel binds
        // 'queue.worker' (string key) but not the FQCN. In Testbench and
        // arbitrary CLI tooling (e.g. `vendor/bin/testbench list`) this leaves
        // Worker unresolvable because its constructor needs a callable
        // $isDownForMaintenance. Bind-if-unbound so consumer apps and tooling
        // both resolve Sunset's commands without further setup.
        $this->app->singletonIf(\Illuminate\Queue\Worker::class, function ($app) {
            return new \Illuminate\Queue\Worker(
                $app->make(\Illuminate\Contracts\Queue\Factory::class),
                $app->make(\Illuminate\Contracts\Events\Dispatcher::class),
                $app->make(\Illuminate\Contracts\Debug\ExceptionHandler::class),
                fn () => $app->isDownForMaintenance(),
            );
        });

        $this->app->singleton(Manager::class, fn ($app) => new Manager($app));

        // v0.7.0: Rate-limit infrastructure. LimitRegistry MUST be a singleton
        // — otherwise Manager::for/limit declarations made in service providers
        // are silently lost when the registry is re-resolved at pop time.
        $this->app->singleton(\Admnio\Sunset\RateLimiting\LimitRegistry::class, function ($app) {
            return new \Admnio\Sunset\RateLimiting\LimitRegistry(
                $app->make(\Psr\Log\LoggerInterface::class)
            );
        });

        $this->app->singleton(\Admnio\Sunset\Contracts\Limiter::class, function ($app) {
            return new \Admnio\Sunset\RateLimiting\RedisLimiter(
                $app->make(\Illuminate\Contracts\Redis\Factory::class),
                $app['config']->get('sunset.redis_connection'),
            );
        });

        // Alias the concrete RedisLimiter to the Limiter contract binding so
        // anything that type-hints the concrete class (notably the sweep
        // command) resolves to the same singleton. Without this the container
        // tries to auto-construct RedisLimiter and chokes on the unresolvable
        // string $connectionName constructor parameter.
        $this->app->singleton(
            \Admnio\Sunset\RateLimiting\RedisLimiter::class,
            fn ($app) => $app->make(\Admnio\Sunset\Contracts\Limiter::class)
        );

        $this->app->singleton(\Admnio\Sunset\RateLimiting\RateLimitGate::class, function ($app) {
            return new \Admnio\Sunset\RateLimiting\RateLimitGate(
                $app->make(\Admnio\Sunset\RateLimiting\LimitRegistry::class),
                $app->make(\Admnio\Sunset\Contracts\Limiter::class),
                $app->make(\Illuminate\Contracts\Redis\Factory::class),
                $app['config']->get('sunset.redis_connection'),
                (bool) $app['config']->get('sunset.rate_limits.fail_closed', false),
                $app->make(\Psr\Log\LoggerInterface::class),
            );
        });

        // v0.8.2: Read-only view over sunset:rl:rejects:* counters for the
        // dashboard. Bound as a singleton so resolution is cheap on each
        // poll cycle; the actual Redis work happens inside rejectsByLimit().
        $this->app->singleton(\Admnio\Sunset\RateLimiting\RateLimitStatsRepository::class, function ($app) {
            return new \Admnio\Sunset\RateLimiting\RateLimitStatsRepository(
                $app->make(\Illuminate\Contracts\Redis\Factory::class),
                $app['config']->get('sunset.redis_connection'),
            );
        });

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

        // v0.8.0: Bind Sunset WorkloadRepository contract to the native
        // implementation. (Previously bound to Horizon's WorkloadRepository
        // contract via an adapter; Horizon is gone in v0.8.0.)
        $this->app->singleton(SunsetWorkloadRepositoryContract::class, $this->workloadRepositoryFactory());
    }

    public function boot(): void
    {
        // v0.8.0: Load the dashboard routes. The route group itself applies
        // Authorize + Inertia middleware and prefixes with sunset.dashboard.path
        // (falling back to top-level 'path' for users mid-upgrade).
        $this->loadRoutesFrom(__DIR__ . '/../routes/sunset.php');

        // v0.8.0: Register the package views under the `sunset` namespace so
        // the Inertia root view (`sunset::sunset-app`) can be resolved by the
        // dashboard middleware without forcing consumers to publish them.
        $this->loadViewsFrom(__DIR__ . '/../resources/views', 'sunset');

        if ($this->app->runningInConsole()) {
            $this->publishes([
                __DIR__ . '/../config/sunset.php' => config_path('sunset.php'),
            ], 'sunset-config');

            // v0.8.0: Publish the pre-built dashboard bundle. The source files
            // only exist after `npm run build` runs — for distribution they are
            // committed into `public-dist/` so they ship in the Composer
            // package. If absent, Laravel's vendor:publish silently skips them.
            $this->publishes([
                __DIR__ . '/../public-dist/app.js'  => public_path('vendor/sunset/app.js'),
                __DIR__ . '/../public-dist/app.css' => public_path('vendor/sunset/app.css'),
            ], 'sunset-assets');

            // v0.8.0: Optional override of the Inertia root view. Consumers
            // shouldn't need this for normal use (the view is loaded under the
            // `sunset` namespace), but publishing lets them customize CSP
            // nonces, asset paths, or layout chrome.
            $this->publishes([
                __DIR__ . '/../resources/views/sunset-app.blade.php' =>
                    resource_path('views/vendor/sunset/sunset-app.blade.php'),
            ], 'sunset-views');

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

                // v0.7.0 rate-limit maintenance:
                SunsetSweepRateLimitSlotsCommand::class,
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

        // v0.7.0: Release rate-limit concurrency slots on terminal job events.
        $events->listen(
            \Illuminate\Queue\Events\JobProcessed::class,
            \Admnio\Sunset\RateLimiting\Listeners\ReleaseConcurrencySlots::class
        );
        $events->listen(
            \Illuminate\Queue\Events\JobFailed::class,
            \Admnio\Sunset\RateLimiting\Listeners\ReleaseConcurrencySlots::class
        );
        $events->listen(
            \Illuminate\Queue\Events\JobExceptionOccurred::class,
            \Admnio\Sunset\RateLimiting\Listeners\ReleaseConcurrencySlots::class
        );

        $this->app->booted(function () {
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

            // v0.7.0: Safety-net reconciliation of orphaned rate-limit
            // concurrency slots. The listener handles the hot path; this
            // sweep cleans up leaked slots from crashed workers.
            $schedule->command('sunset:sweep-rate-limit-slots')
                ->everyMinute()
                ->withoutOverlapping()
                ->name('sunset-sweep-rate-limit-slots');

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
    }

    private function workloadRepositoryFactory(): \Closure
    {
        return function ($app) {
            return new SunsetWorkloadRepository(
                transports: $app->make(TransportRegistry::class),
                metrics: $app->make(SunsetMetricsRepository::class),
                supervisors: $app->make(SunsetSupervisorRepository::class),
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
        $config = $app['config'];

        // Prefer sunset.environments. Fall back to horizon.environments for
        // backwards compatibility — remove the fallback in v0.9.0+.
        $supervisors = (array) ($config->get("sunset.environments.{$env}")
            ?: $config->get("horizon.environments.{$env}", []));

        $queues = [];
        foreach ($supervisors as $supervisor) {
            foreach ((array) ($supervisor['queue'] ?? []) as $q) {
                $queues[] = $q;
            }
        }
        return array_values(array_unique($queues)) ?: [$config->get('queue.connections.sqs.queue', 'default')];
    }
}
