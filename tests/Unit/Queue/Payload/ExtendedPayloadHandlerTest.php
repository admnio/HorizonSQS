<?php

namespace Admnio\Sunset\Tests\Unit\Queue\Payload;

use Aws\Result;
use Aws\S3\S3Client;
use Admnio\Sunset\Exceptions\ExtendedPayloadException;
use Admnio\Sunset\Queue\Payload\ExtendedPayloadHandler;
use Admnio\Sunset\Tests\TestCase;
use Mockery;

class ExtendedPayloadHandlerTest extends TestCase
{
    public function test_returns_payload_unchanged_when_under_threshold(): void
    {
        $s3 = Mockery::mock(S3Client::class);
        $handler = new ExtendedPayloadHandler($s3, 'my-bucket', 'horizon-sqs-payloads/');

        $payload = str_repeat('a', 200_000);

        $this->assertSame($payload, $handler->maybeStore($payload));
    }

    public function test_stores_payload_above_threshold_and_returns_pointer(): void
    {
        $s3 = Mockery::mock(S3Client::class);
        $s3->shouldReceive('putObject')->once()->andReturn(new Result());

        $handler = new ExtendedPayloadHandler($s3, 'my-bucket', 'horizon-sqs-payloads/');
        $payload = str_repeat('a', 300_000);

        $pointer = $handler->maybeStore($payload);

        $decoded = json_decode($pointer, true);
        $this->assertArrayHasKey('s3PointerKey', $decoded);
        $this->assertStringStartsWith('horizon-sqs-payloads/', $decoded['s3PointerKey']);
        $this->assertSame(300_000, $decoded['size']);
    }

    public function test_fetch_resolves_pointer_via_s3(): void
    {
        $s3 = Mockery::mock(S3Client::class);
        $s3->shouldReceive('getObject')
            ->once()
            ->with(Mockery::on(fn ($args) => $args['Key'] === 'horizon-sqs-payloads/abc'))
            ->andReturn(new Result(['Body' => 'real-payload']));

        $handler = new ExtendedPayloadHandler($s3, 'my-bucket', 'horizon-sqs-payloads/');
        $pointer = json_encode(['s3PointerKey' => 'horizon-sqs-payloads/abc', 'size' => 300_000]);

        $this->assertSame('real-payload', $handler->maybeFetch($pointer));
    }

    public function test_fetch_passes_through_non_pointer(): void
    {
        $s3 = Mockery::mock(S3Client::class);
        $handler = new ExtendedPayloadHandler($s3, 'my-bucket', 'horizon-sqs-payloads/');

        $this->assertSame('plain', $handler->maybeFetch('plain'));
        $this->assertSame('{"id":"abc"}', $handler->maybeFetch('{"id":"abc"}'));
    }

    public function test_store_failure_throws(): void
    {
        $s3 = Mockery::mock(S3Client::class);
        $s3->shouldReceive('putObject')->andThrow(new \Aws\S3\Exception\S3Exception('fail', Mockery::mock(\Aws\CommandInterface::class)));

        $handler = new ExtendedPayloadHandler($s3, 'my-bucket', 'horizon-sqs-payloads/');

        $this->expectException(ExtendedPayloadException::class);
        $handler->maybeStore(str_repeat('a', 300_000));
    }

    public function test_delete_pointer(): void
    {
        $s3 = Mockery::mock(S3Client::class);
        $s3->shouldReceive('deleteObject')
            ->once()
            ->with(Mockery::on(fn ($args) => $args['Key'] === 'horizon-sqs-payloads/abc'))
            ->andReturn(new Result());

        $handler = new ExtendedPayloadHandler($s3, 'my-bucket', 'horizon-sqs-payloads/');
        $pointer = json_encode(['s3PointerKey' => 'horizon-sqs-payloads/abc', 'size' => 300_000]);

        $handler->deleteIfPointer($pointer);
    }

    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }
}
