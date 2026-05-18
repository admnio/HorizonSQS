<?php

namespace Admnio\Sunset\Tests\Unit\Queue;

use Admnio\Sunset\Queue\HorizonSqsConnector;
use Admnio\Sunset\Queue\HorizonSqsQueue;
use Admnio\Sunset\Tests\TestCase;

class HorizonSqsConnectorTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        $this->app->singleton(HorizonSqsConnector::class, function ($app) {
            return new HorizonSqsConnector(
                container: $app,
                redis: $app->make(\Illuminate\Contracts\Redis\Factory::class),
                packageConfig: config('horizon-sqs'),
            );
        });
    }

    public function test_connect_returns_horizon_sqs_queue(): void
    {
        $connector = $this->app->make(HorizonSqsConnector::class);

        $queue = $connector->connect([
            'key' => 'test',
            'secret' => 'test',
            'region' => 'us-east-1',
            'prefix' => 'http://localhost:4566/000000000000',
            'queue' => 'default',
            'suffix' => '',
        ]);

        $this->assertInstanceOf(HorizonSqsQueue::class, $queue);
    }
}
