<?php

namespace MasonWorkforce\HorizonSqs;

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
use MasonWorkforce\HorizonSqs\Console\SweepDelayedCommand;
use MasonWorkforce\HorizonSqs\Exceptions\InvalidConfigurationException;
use MasonWorkforce\HorizonSqs\Queue\Delay\DelayedJobReenqueuer;
use MasonWorkforce\HorizonSqs\Queue\Delay\DelayedJobStore;
use MasonWorkforce\HorizonSqs\Queue\HorizonSqsConnector;
use MasonWorkforce\HorizonSqs\Queue\Payload\PayloadEnricher;
use MasonWorkforce\HorizonSqs\Repositories\SqsWorkloadRepository;
use Psr\Log\LoggerInterface;

class HorizonSqsServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__ . '/../config/horizon-sqs.php', 'horizon-sqs');

        $this->app->singleton(PayloadEnricher::class);

        $this->app->singleton(HorizonSqsConnector::class, function ($app) {
            return new HorizonSqsConnector(
                container: $app,
                enricher: $app->make(PayloadEnricher::class),
                redis: $app->make(RedisFactory::class),
                packageConfig: $this->validatedPackageConfig($app['config']->get('horizon-sqs')),
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
