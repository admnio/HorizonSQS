<?php

namespace Admnio\Sunset\Tests\Integration;

use Aws\S3\S3Client;
use Aws\Sqs\SqsClient;
use Admnio\Sunset\Tests\TestCase;

abstract class IntegrationTestCase extends TestCase
{
    protected SqsClient $sqs;
    protected ?S3Client $s3 = null;

    protected function setUp(): void
    {
        parent::setUp();

        $this->sqs = new SqsClient([
            'region' => 'us-east-1',
            'version' => 'latest',
            'endpoint' => env('LOCALSTACK_ENDPOINT', 'http://localhost:4566'),
            'credentials' => ['key' => 'test', 'secret' => 'test'],
        ]);
    }

    protected function defineEnvironment($app): void
    {
        parent::defineEnvironment($app);
        $app['config']->set('queue.connections.sqs.endpoint', env('LOCALSTACK_ENDPOINT', 'http://localhost:4566'));
    }

    protected function createQueue(string $name, bool $fifo = false): string
    {
        $attrs = $fifo ? ['FifoQueue' => 'true', 'ContentBasedDeduplication' => 'true'] : [];
        $response = $this->sqs->createQueue(['QueueName' => $name, 'Attributes' => $attrs]);
        return $response->get('QueueUrl');
    }

    protected function deleteAllQueues(): void
    {
        $response = $this->sqs->listQueues();
        foreach (($response->get('QueueUrls') ?? []) as $url) {
            $this->sqs->deleteQueue(['QueueUrl' => $url]);
        }
    }

    protected function ensureLocalStackAvailable(): void
    {
        try {
            $this->sqs->listQueues();
        } catch (\Throwable $e) {
            $this->markTestSkipped('LocalStack not available at ' . env('LOCALSTACK_ENDPOINT'));
        }
    }
}
