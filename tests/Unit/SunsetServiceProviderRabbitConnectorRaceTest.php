<?php

namespace Admnio\Sunset\Tests\Unit;

use Admnio\Sunset\SunsetServiceProvider;
use Admnio\Sunset\Tests\TestCase;
use Admnio\Sunset\Transports\Rabbit\RabbitQueue;
use VladimirYuldashev\LaravelQueueRabbitMQ\LaravelQueueRabbitMQServiceProvider;

/**
 * Regression test for the v0.6.0 connector binding race: vendor's
 * LaravelQueueRabbitMQServiceProvider also registers a 'rabbitmq' connector,
 * and alphabetical auto-discovery means vendor's boot() runs AFTER Sunset's.
 * Without a booted()-callback fix, vendor would overwrite Sunset's binding.
 *
 * This test loads BOTH providers (matching real-world auto-discovery) and
 * asserts that `Queue::connection('rabbitmq')` resolves to Sunset's subclass.
 */
class SunsetServiceProviderRabbitConnectorRaceTest extends TestCase
{
    protected function getPackageProviders($app): array
    {
        // Order mirrors real-world Laravel auto-discovery: package providers
        // load alphabetically by composer package name, so `admnio/sunset`
        // registers BEFORE `vladimir-yuldashev/laravel-queue-rabbitmq`.
        // That means Sunset's boot() runs first and the vendor's boot() runs
        // later — and would overwrite the 'rabbitmq' connector binding if
        // Sunset registers it directly inside boot() rather than via a
        // booted() callback.
        return [
            \Laravel\Horizon\HorizonServiceProvider::class,
            SunsetServiceProvider::class,
            LaravelQueueRabbitMQServiceProvider::class,
        ];
    }

    public function test_sunset_rabbit_connector_wins_over_vendor_after_auto_discovery_order(): void
    {
        // After all providers have booted, the rabbitmq connector that
        // QueueManager resolves must produce a Sunset RabbitQueue subclass,
        // not the vanilla vendor RabbitMQQueue.
        $queue = $this->app['queue']->connection('rabbitmq');

        $this->assertInstanceOf(
            RabbitQueue::class,
            $queue,
            'Expected Sunset RabbitQueue subclass; got ' . get_class($queue) .
            '. The connector binding race against the vendor provider has regressed.'
        );
    }
}
