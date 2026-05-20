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

    /**
     * Skip the current test cleanly when RabbitMQ isn't reachable. Opens and
     * immediately closes an AMQP connection against the docker-compose service
     * defined in docker-compose.yml. Used by Rabbit integration tests to keep
     * the suite green on developer machines that haven't booted the container.
     */
    protected function ensureRabbitMQAvailable(): void
    {
        try {
            $connection = new \PhpAmqpLib\Connection\AMQPStreamConnection(
                env('RABBITMQ_HOST', '127.0.0.1'),
                (int) env('RABBITMQ_PORT', 5672),
                env('RABBITMQ_USER', 'guest'),
                env('RABBITMQ_PASSWORD', 'guest'),
                env('RABBITMQ_VHOST', '/'),
            );
            $connection->close();
        } catch (\Throwable $e) {
            $this->markTestSkipped(
                'RabbitMQ not reachable at 127.0.0.1:5672 — run docker compose up -d rabbitmq'
            );
        }
    }
}
