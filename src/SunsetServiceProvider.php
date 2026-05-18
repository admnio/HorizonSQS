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
use Admnio\Sunset\Console\SunsetMigrateRedisKeysCommand;
use Admnio\Sunset\Console\SweepDelayedCommand;
use Admnio\Sunset\Exceptions\InvalidConfigurationException;
use Admnio\Sunset\Listeners\CleanupExtendedPayload;
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
                connectionName: 'sqs',
                sweepIntervalSeconds: (int) $app['config']->get('sunset.transports.sqs.long_delay_sweep_interval', 60),
            );
        });

        $this->app->singleton(WorkloadRepository::class, $this->workloadRepositoryFactory());
    }

    public function boot(): void
    {
        if ($this->app->runningInConsole()) {
            $this->publishes([
                __DIR__ . '/../config/sunset.php' => config_path('sunset.php'),
            ], 'sunset-config');

            $this->commands([
                SunsetMigrateRedisKeysCommand::class,
                SweepDelayedCommand::class,
            ]);
        }

        $manager = $this->app->make('queue');
        if ($manager instanceof QueueManager) {
            $manager->addConnector('sqs', fn () => $this->app->make(SqsConnector::class));
            $manager->addConnector('redis', fn () => $this->app->make(
                \Admnio\Sunset\Transports\Redis\RedisConnector::class
            ));
        }

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

        $this->app->booted(function () {
            $schedule = $this->app->make(Schedule::class);
            $schedule->command('sunset:sweep-delayed')
                ->everyMinute()
                ->withoutOverlapping()
                ->name('sunset-sweep-delayed');
        });
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
