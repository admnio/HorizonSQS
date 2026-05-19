<?php

namespace Admnio\Sunset\Tests\Unit;

use Admnio\Sunset\JobPayload;
use Admnio\Sunset\Tests\TestCase;

class JobPayloadTest extends TestCase
{
    public function test_decodes_payload_and_exposes_id(): void
    {
        $json = json_encode(['uuid' => 'abc-123', 'displayName' => 'TestJob', 'data' => []]);
        $payload = new JobPayload($json);

        $this->assertSame('abc-123', $payload->id());
        $this->assertSame('TestJob', $payload->decoded['displayName']);
        $this->assertSame($json, $payload->value);
    }

    public function test_id_falls_back_to_id_key_when_uuid_missing(): void
    {
        $json = json_encode(['id' => 'legacy-id', 'displayName' => 'TestJob', 'data' => []]);
        $this->assertSame('legacy-id', (new JobPayload($json))->id());
    }

    public function test_tags_returns_decoded_tags_or_empty_array(): void
    {
        $with = new JobPayload(json_encode(['uuid' => 'a', 'tags' => ['t1', 't2']]));
        $without = new JobPayload(json_encode(['uuid' => 'b']));

        $this->assertSame(['t1', 't2'], $with->tags());
        $this->assertSame([], $without->tags());
    }

    public function test_prepare_stamps_type_tags_silenced_and_pushed_at(): void
    {
        $payload = new JobPayload(json_encode(['uuid' => 'abc', 'data' => []]));
        $prepared = $payload->prepare(null);

        $this->assertSame('job', $prepared->decoded['type']);
        $this->assertSame([], $prepared->decoded['tags']);
        $this->assertFalse($prepared->decoded['silenced']);
        $this->assertArrayHasKey('pushedAt', $prepared->decoded);
        $this->assertIsString($prepared->decoded['pushedAt']);
    }

    public function test_set_rewrites_value_json_in_sync_with_decoded(): void
    {
        $payload = new JobPayload(json_encode(['uuid' => 'a']));
        $payload->set(['custom' => 'value']);

        $this->assertSame('value', $payload->decoded['custom']);
        $this->assertSame('value', json_decode($payload->value, true)['custom']);
    }

    public function test_is_retry_and_retry_of(): void
    {
        $not = new JobPayload(json_encode(['uuid' => 'a']));
        $retry = new JobPayload(json_encode(['uuid' => 'b', 'retry_of' => 'a']));

        $this->assertFalse($not->isRetry());
        $this->assertNull($not->retryOf());
        $this->assertTrue($retry->isRetry());
        $this->assertSame('a', $retry->retryOf());
    }

    public function test_array_access_reads_and_writes_decoded(): void
    {
        $payload = new JobPayload(json_encode(['uuid' => 'a', 'data' => ['foo' => 'bar']]));

        $this->assertTrue(isset($payload['data']));
        $this->assertSame(['foo' => 'bar'], $payload['data']);

        $payload['extra'] = 'baz';
        $this->assertSame('baz', $payload->decoded['extra']);
        $this->assertSame('baz', json_decode($payload->value, true)['extra']);

        unset($payload['extra']);
        $this->assertArrayNotHasKey('extra', $payload->decoded);
        $this->assertArrayNotHasKey('extra', json_decode($payload->value, true));
    }
}
