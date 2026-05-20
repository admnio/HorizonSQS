<?php

namespace Admnio\Sunset\Transports\Sqs\Payload;

use Aws\S3\Exception\S3Exception;
use Aws\S3\S3Client;
use Admnio\Sunset\Exceptions\ExtendedPayloadException;
use Ramsey\Uuid\Uuid;
use Throwable;

/**
 * @internal This class is part of Sunset's internal implementation; signatures
 *           may change between minor releases of v1.x. Consumers should depend
 *           on the published Admnio\Sunset\Contracts\* interfaces instead.
 */
class ExtendedPayloadHandler
{
    private const SIZE_THRESHOLD = 256 * 1024;

    public function __construct(
        private S3Client $s3,
        private string $bucket,
        private string $prefix
    ) {
    }

    public function maybeStore(string $payload): string
    {
        if (strlen($payload) <= self::SIZE_THRESHOLD) {
            return $payload;
        }

        $key = $this->prefix . Uuid::uuid4()->toString();

        try {
            $this->s3->putObject([
                'Bucket' => $this->bucket,
                'Key' => $key,
                'Body' => $payload,
            ]);
        } catch (Throwable $e) {
            throw new ExtendedPayloadException('Failed to store extended payload in S3: ' . $e->getMessage(), 0, $e);
        }

        return json_encode([
            's3PointerKey' => $key,
            'size' => strlen($payload),
        ]);
    }

    public function maybeFetch(string $body): string
    {
        $decoded = json_decode($body, true);
        if (! is_array($decoded) || ! isset($decoded['s3PointerKey'])) {
            return $body;
        }

        try {
            $result = $this->s3->getObject([
                'Bucket' => $this->bucket,
                'Key' => $decoded['s3PointerKey'],
            ]);
        } catch (Throwable $e) {
            throw new ExtendedPayloadException('Failed to fetch extended payload from S3: ' . $e->getMessage(), 0, $e);
        }

        return (string) $result['Body'];
    }

    public function deleteIfPointer(string $body): void
    {
        $decoded = json_decode($body, true);
        if (! is_array($decoded) || ! isset($decoded['s3PointerKey'])) {
            return;
        }

        try {
            $this->s3->deleteObject([
                'Bucket' => $this->bucket,
                'Key' => $decoded['s3PointerKey'],
            ]);
        } catch (Throwable) {
            // best-effort; orphan handled by S3 lifecycle rule
        }
    }
}
