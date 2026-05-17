<?php

namespace MasonWorkforce\HorizonSqs\Tests\Integration;

use Aws\S3\S3Client;
use Illuminate\Support\Facades\Queue;
use MasonWorkforce\HorizonSqs\Queue\HorizonSqsConnector;
use MasonWorkforce\HorizonSqs\Queue\Payload\PayloadEnricher;

class ExtendedPayloadTest extends IntegrationTestCase
{
    private string $bucket = 'horizon-sqs-test';

    protected function defineEnvironment($app): void
    {
        parent::defineEnvironment($app);
        // The HorizonSqsConnector reads the package config at register() time and
        // stores it in a private property. We must set extended_payload BEFORE
        // the connector singleton is built, so set it in defineEnvironment().
        $app['config']->set('horizon-sqs.extended_payload.enabled', true);
        $app['config']->set('horizon-sqs.extended_payload.bucket', $this->bucket);
        $app['config']->set('horizon-sqs.extended_payload.prefix', 'horizon-sqs-payloads/');
    }

    protected function setUp(): void
    {
        parent::setUp();
        $this->ensureLocalStackAvailable();
        $this->deleteAllQueues();
        $url = $this->createQueue('default');
        config(['queue.connections.sqs.prefix' => str_replace('/default', '', $url)]);

        $this->s3 = new S3Client([
            'region' => 'us-east-1',
            'version' => 'latest',
            'endpoint' => env('LOCALSTACK_ENDPOINT', 'http://localhost:4566'),
            'use_path_style_endpoint' => true,
            'credentials' => ['key' => 'test', 'secret' => 'test'],
        ]);
        try {
            $this->s3->createBucket(['Bucket' => $this->bucket]);
        } catch (\Throwable) {
            // already exists
        }

    }

    public function test_roundtrip_large_payload(): void
    {
        $big = str_repeat('x', 300_000);
        $payload = (new PayloadEnricher())->enrich(['custom' => $big], 'default');
        $json = json_encode($payload);

        Queue::connection('sqs')->pushRaw($json, 'default');

        $job = Queue::connection('sqs')->pop('default');
        $this->assertNotNull($job);
        $decoded = json_decode($job->getRawBody(), true);
        $this->assertSame($big, $decoded['custom']);
    }
}
