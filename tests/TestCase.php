<?php

namespace Admnio\Sunset\Tests;

use Admnio\Sunset\SunsetServiceProvider;
use Orchestra\Testbench\TestCase as Orchestra;

abstract class TestCase extends Orchestra
{
    protected function getPackageProviders($app): array
    {
        return [
            \Laravel\Horizon\HorizonServiceProvider::class,
            SunsetServiceProvider::class,
        ];
    }

    protected function defineEnvironment($app): void
    {
        // Illuminate\Queue\Worker requires a `callable $isDownForMaintenance`
        // constructor parameter that the container cannot auto-resolve. Bind it
        // explicitly so that any test bootstrapping Artisan commands that extend
        // Laravel's built-in WorkCommand (e.g. SunsetWorkerCommand) can resolve.
        $app->singleton(\Illuminate\Queue\Worker::class, function ($app) {
            return new \Illuminate\Queue\Worker(
                $app->make(\Illuminate\Contracts\Queue\Factory::class),
                $app->make(\Illuminate\Contracts\Events\Dispatcher::class),
                $app->make(\Illuminate\Contracts\Debug\ExceptionHandler::class),
                fn () => $app->isDownForMaintenance(),
            );
        });

        $app['config']->set('queue.default', 'sqs');
        $app['config']->set('queue.connections.sqs', [
            'driver' => 'sqs',
            'key' => 'test',
            'secret' => 'test',
            'prefix' => 'http://localhost:4566/000000000000',
            'queue' => 'default',
            'suffix' => '',
            'region' => 'us-east-1',
        ]);
        $app['config']->set('queue.connections.redis', [
            'driver' => 'redis',
            'connection' => 'default',
            'queue' => 'default',
            'retry_after' => 60,
            'block_for' => null,
        ]);
        $app['config']->set('queue.connections.rabbitmq', [
            'driver' => 'rabbitmq',
            'queue' => 'default',
            'connection' => env('RABBITMQ_QUEUE_CONNECTION', 'default'),
            'hosts' => [[
                'host' => env('RABBITMQ_HOST', '127.0.0.1'),
                'port' => (int) env('RABBITMQ_PORT', 5672),
                'user' => env('RABBITMQ_USER', 'guest'),
                'password' => env('RABBITMQ_PASSWORD', 'guest'),
                'vhost' => env('RABBITMQ_VHOST', '/'),
            ]],
            'options' => [
                'queue' => [
                    'exchange' => env('RABBITMQ_EXCHANGE', 'amq.direct'),
                    'exchange_type' => 'direct',
                ],
            ],
        ]);
        $app['config']->set('database.redis.default', [
            'host' => env('REDIS_HOST', '127.0.0.1'),
            'port' => env('REDIS_PORT', 6379),
            'database' => 1,
        ]);
        $app['config']->set('sunset.redis_connection', 'default');
    }
}
