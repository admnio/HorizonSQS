<?php

namespace Admnio\Sunset\Tests\Integration;

use Aws\S3\Exception\S3Exception;
use Aws\S3\S3Client;
use Illuminate\Queue\Events\JobProcessed;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Queue;
use Admnio\Sunset\Queue\HorizonSqsConnector;

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

    public function test_job_processed_cleans_up_s3_pointer(): void
    {
        $big = str_repeat('y', 300_000);
        $payload = [
            'uuid' => 'cleanup-uuid',
            'id' => 'cleanup-id',
            'displayName' => 'CleanupJob',
            'job' => 'Illuminate\\Queue\\CallQueuedHandler@call',
            'data' => ['commandName' => 'CleanupJob', 'command' => ''],
            'custom' => $big,
        ];
        Queue::connection('sqs')->pushRaw(json_encode($payload), 'default');

        $job = Queue::connection('sqs')->pop('default');
        $this->assertNotNull($job);

        $sqsBody = json_decode(
            $this->sqs->receiveMessage(['QueueUrl' => $this->getQueueUrl()])->get('Messages')[0]['Body'] ?? '{}',
            true,
        );
        // Receive only used as a side-channel; nothing to assert here.

        // The pop()'d body should be the expanded JSON; locate the s3 pointer key
        // from the listener path by inspecting bucket objects.
        $objectsBefore = $this->listBucketKeys();
        $this->assertNotEmpty(
            $objectsBefore,
            'Expected at least one S3 pointer object after pushing a large payload.',
        );

        // Simulate Horizon worker firing JobProcessed after successful handle().
        event(new JobProcessed('sqs', $job));

        $objectsAfter = $this->listBucketKeys();
        $this->assertCount(
            count($objectsBefore) - 1,
            $objectsAfter,
            'Expected JobProcessed listener to delete the S3 pointer object.',
        );
    }

    private function getQueueUrl(): string
    {
        return rtrim(config('queue.connections.sqs.prefix'), '/') . '/default';
    }

    private function listBucketKeys(): array
    {
        try {
            $res = $this->s3->listObjectsV2(['Bucket' => $this->bucket]);
            return collect($res->get('Contents') ?? [])->pluck('Key')->all();
        } catch (S3Exception) {
            return [];
        }
    }

    public function test_roundtrip_large_payload(): void
    {
        $big = str_repeat('x', 300_000);
        // Provide a Horizon-shaped payload so JobPayload::prepare and the
        // downstream StoreJob listener have the fields they expect.
        $payload = [
            'uuid' => 'test-uuid-' . bin2hex(random_bytes(4)),
            'id' => 'test-id-' . bin2hex(random_bytes(4)),
            'displayName' => 'TestLargePayloadJob',
            'job' => 'Illuminate\\Queue\\CallQueuedHandler@call',
            'data' => ['commandName' => 'TestLargePayloadJob', 'command' => ''],
            'custom' => $big,
        ];
        $json = json_encode($payload);

        Queue::connection('sqs')->pushRaw($json, 'default');

        $job = Queue::connection('sqs')->pop('default');
        $this->assertNotNull($job);
        $decoded = json_decode($job->getRawBody(), true);
        $this->assertSame($big, $decoded['custom']);
    }
}
