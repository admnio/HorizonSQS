<?php

namespace Admnio\Sunset;

use Aws\Sqs\SqsClient;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Contracts\Cache\Repository as Cache;
use Illuminate\Contracts\Queue\Factory as QueueFactory;
use Illuminate\Contracts\Redis\Factory as RedisFactory;
use Illuminate\Queue\QueueManager;
use Illuminate\Support\ServiceProvider;
use Laravel\Horizon\Contracts\MetricsRepository;
use Laravel\Horizon\Contracts\SupervisorRepository;
use Laravel\Horizon\Contracts\WorkloadRepository;
use Admnio\Sunset\Console\SweepDelayedCommand;
use Admnio\Sunset\Exceptions\InvalidConfigurationException;
use Admnio\Sunset\Listeners\CleanupExtendedPayload;
use Admnio\Sunset\Queue\Delay\DelayedJobReenqueuer;
use Admnio\Sunset\Queue\Delay\DelayedJobStore;
use Admnio\Sunset\Queue\HorizonSqsConnector;
use Admnio\Sunset\Queue\Payload\ExtendedPayloadHandler;
use Admnio\Sunset\Repositories\SqsWorkloadRepository;
use Psr\Log\LoggerInterface;

class SunsetServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__ . '/../config/horizon-sqs.php', 'horizon-sqs');

        $this->app->singleton(HorizonSqsConnector::class, function ($app) {
            return new HorizonSqsConnector(
                container: $app,
                redis: $app->make(RedisFactory::class),
                packageConfig: $this->validatedPackageConfig($app['config']->get('horizon-sqs')),
            );
        });

        // Register the ExtendedPayloadHandler binding unconditionally so listener
        // resolution works regardless of when extended_payload.enabled is set in
        // the config (which may happen in test environments after boot).
        $this->app->singleton(ExtendedPayloadHandler::class, function ($app) {
            $config = $app['config']->get('horizon-sqs');
            $queueConfig = $app['config']->get('queue.connections.sqs', []);
            $s3Config = $this->awsConfigFor($queueConfig);
            if (! empty($s3Config['endpoint'])) {
                $s3Config['use_path_style_endpoint'] = true;
            }
            return new ExtendedPayloadHandler(
                new \Aws\S3\S3Client($s3Config),
                $config['extended_payload']['bucket'] ?? '',
                $config['extended_payload']['prefix'] ?? ''
            );
        });

        $this->app->singleton(DelayedJobStore::class, function ($app) {
            return new DelayedJobStore(
                $app->make(RedisFactory::class),
                $app['config']->get('horizon-sqs.redis_connection')
            );
        });

        $this->app->singleton(DelayedJobReenqueuer::class, function ($app) {
            return new DelayedJobReenqueuer(
                store: $app->make(DelayedJobStore::class),
                queues: $app->make(QueueFactory::class),
                logger: $app->make(LoggerInterface::class),
                connectionName: 'sqs',
                sweepIntervalSeconds: (int) $app['config']->get('horizon-sqs.long_delay_sweep_interval', 60),
            );
        });

        $this->app->singleton(WorkloadRepository::class, function ($app) {
            $connection = $app['config']->get('queue.connections.sqs');
            $queues = $this->resolveQueueList($app);

            return new SqsWorkloadRepository(
                sqs: new SqsClient($this->awsConfigFor($connection)),
                metrics: $app->make(MetricsRepository::class),
                supervisors: $app->make(SupervisorRepository::class),
                cache: $app->make(Cache::class),
                logger: $app->make(LoggerInterface::class),
                queuePrefix: $connection['prefix'] ?? '',
                queues: $queues,
                cacheTtlSeconds: (int) $app['config']->get('horizon-sqs.workload_cache_ttl', 5),
            );
        });
    }

    public function boot(): void
    {
        if ($this->app->runningInConsole()) {
            $this->publishes([
                __DIR__ . '/../config/horizon-sqs.php' => config_path('horizon-sqs.php'),
            ], 'horizon-sqs-config');

            $this->commands([SweepDelayedCommand::class]);
        }

        $manager = $this->app->make('queue');
        if ($manager instanceof QueueManager) {
            $manager->addConnector('sqs', fn () => $this->app->make(HorizonSqsConnector::class));
        }

        // Clean up S3 spillover objects when a job completes successfully on the
        // sqs connection. Listener short-circuits internally when the body is
        // not an S3 pointer, so registering it unconditionally is safe and
        // simplifies the test path (config can be set after register()).
        if ($this->app['config']->get('horizon-sqs.extended_payload.enabled', false)) {
            $this->app['events']->listen(
                \Illuminate\Queue\Events\JobProcessed::class,
                CleanupExtendedPayload::class
            );
        }

        // Re-bind WorkloadRepository in boot() to override Horizon's own binding,
        // since Horizon's ServiceProvider registers after ours (alphabetical order).
        $this->app->singleton(WorkloadRepository::class, function ($app) {
            $connection = $app['config']->get('queue.connections.sqs');
            $queues = $this->resolveQueueList($app);

            return new SqsWorkloadRepository(
                sqs: new SqsClient($this->awsConfigFor($connection)),
                metrics: $app->make(MetricsRepository::class),
                supervisors: $app->make(SupervisorRepository::class),
                cache: $app->make(Cache::class),
                logger: $app->make(LoggerInterface::class),
                queuePrefix: $connection['prefix'] ?? '',
                queues: $queues,
                cacheTtlSeconds: (int) $app['config']->get('horizon-sqs.workload_cache_ttl', 5),
            );
        });

        $this->app->booted(function () {
            $schedule = $this->app->make(Schedule::class);
            $schedule->command('horizon-sqs:sweep-delayed')
                ->everyMinute()
                ->withoutOverlapping()
                ->name('horizon-sqs-sweep-delayed');
        });
    }

    private function validatedPackageConfig(array $config): array
    {
        $redisConn = $config['redis_connection'] ?? null;
        if (! $redisConn || ! $this->app['config']->get("database.redis.{$redisConn}")) {
            throw new InvalidConfigurationException(
                "horizon-sqs.redis_connection '{$redisConn}' is not defined in database.redis."
            );
        }

        if (($config['extended_payload']['enabled'] ?? false) && empty($config['extended_payload']['bucket'])) {
            throw new InvalidConfigurationException(
                'horizon-sqs.extended_payload.enabled is true but no bucket is configured.'
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

    private function awsConfigFor(array $config): array
    {
        $base = ['region' => $config['region'] ?? 'us-east-1', 'version' => 'latest'];
        if (! empty($config['key']) && ! empty($config['secret'])) {
            $base['credentials'] = [
                'key' => $config['key'],
                'secret' => $config['secret'],
            ];
            if (! empty($config['token'])) {
                $base['credentials']['token'] = $config['token'];
            }
        }
        if (! empty($config['endpoint'])) {
            $base['endpoint'] = $config['endpoint'];
        }
        return $base;
    }
}
