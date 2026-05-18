<?php

namespace Admnio\Sunset\Tests\Unit\Support;

use Admnio\Sunset\Support\FifoMessageAttributes;
use Admnio\Sunset\Tests\TestCase;

class FifoMessageAttributesTest extends TestCase
{
    public function test_returns_empty_for_non_fifo_queue(): void
    {
        $attrs = new FifoMessageAttributes(['message_group_id' => 'queue-name', 'content_based_dedup' => true]);

        $result = $attrs->for('default', '{"id":"abc"}', []);

        $this->assertSame([], $result);
    }

    public function test_uses_queue_name_strategy(): void
    {
        $attrs = new FifoMessageAttributes(['message_group_id' => 'queue-name', 'content_based_dedup' => true]);

        $result = $attrs->for('orders.fifo', '{"id":"abc"}', []);

        $this->assertSame('orders.fifo', $result['MessageGroupId']);
    }

    public function test_uses_job_class_strategy_from_payload(): void
    {
        $attrs = new FifoMessageAttributes(['message_group_id' => 'job-class', 'content_based_dedup' => true]);
        $payload = json_encode(['data' => ['commandName' => 'App\\Jobs\\SendEmail']]);

        $result = $attrs->for('orders.fifo', $payload, []);

        $this->assertSame('App\\Jobs\\SendEmail', $result['MessageGroupId']);
    }

    public function test_callable_strategy_invoked(): void
    {
        $attrs = new FifoMessageAttributes([
            'message_group_id' => fn (array $payload, string $queue) => 'custom-' . $queue,
            'content_based_dedup' => true,
        ]);

        $result = $attrs->for('orders.fifo', '{"id":"abc"}', []);

        $this->assertSame('custom-orders.fifo', $result['MessageGroupId']);
    }

    public function test_content_based_dedup_uses_payload_sha256(): void
    {
        $attrs = new FifoMessageAttributes(['message_group_id' => 'queue-name', 'content_based_dedup' => true]);
        $payload = '{"id":"abc"}';

        $result = $attrs->for('orders.fifo', $payload, []);

        $this->assertSame(hash('sha256', $payload), $result['MessageDeduplicationId']);
    }

    public function test_options_override_dedup_id(): void
    {
        $attrs = new FifoMessageAttributes(['message_group_id' => 'queue-name', 'content_based_dedup' => true]);

        $result = $attrs->for('orders.fifo', '{"id":"abc"}', ['MessageDeduplicationId' => 'explicit']);

        $this->assertSame('explicit', $result['MessageDeduplicationId']);
    }
}
