<?php

namespace MasonWorkforce\HorizonSqs\Tests\Unit\Queue\Payload;

use MasonWorkforce\HorizonSqs\Queue\Payload\PayloadEnricher;
use MasonWorkforce\HorizonSqs\Tests\TestCase;

class PayloadEnricherTest extends TestCase
{
    public function test_adds_id_pushedAt_tags_and_nonce(): void
    {
        $enricher = new PayloadEnricher();
        $payload = ['displayName' => 'App\\Jobs\\SendEmail', 'job' => 'Illuminate\\Queue\\CallQueuedHandler@call'];

        $result = $enricher->enrich($payload, 'default');

        $this->assertArrayHasKey('id', $result);
        $this->assertMatchesRegularExpression(
            '/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/',
            $result['id']
        );
        $this->assertArrayHasKey('pushedAt', $result);
        $this->assertIsFloat($result['pushedAt']);
        $this->assertArrayHasKey('tags', $result);
        $this->assertIsArray($result['tags']);
        $this->assertArrayHasKey('_horizon_nonce', $result);
        $this->assertSame(16, strlen($result['_horizon_nonce']));
    }

    public function test_preserves_existing_payload_keys(): void
    {
        $enricher = new PayloadEnricher();
        $payload = ['displayName' => 'Foo', 'data' => ['command' => 'serialized']];

        $result = $enricher->enrich($payload, 'default');

        $this->assertSame('Foo', $result['displayName']);
        $this->assertSame(['command' => 'serialized'], $result['data']);
    }

    public function test_does_not_overwrite_existing_id(): void
    {
        $enricher = new PayloadEnricher();
        $payload = ['id' => 'preset-id', 'displayName' => 'Foo'];

        $result = $enricher->enrich($payload, 'default');

        $this->assertSame('preset-id', $result['id']);
    }

    public function test_merges_existing_tags_uniquely(): void
    {
        $enricher = new PayloadEnricher();
        $payload = ['tags' => ['a', 'b']];

        $result = $enricher->enrich($payload, 'default');

        $this->assertContains('a', $result['tags']);
        $this->assertContains('b', $result['tags']);
        $this->assertSame(array_unique($result['tags']), $result['tags']);
    }
}
