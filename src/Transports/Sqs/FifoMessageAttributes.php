<?php

namespace Admnio\Sunset\Transports\Sqs;

use InvalidArgumentException;

/**
 * @internal This class is part of Sunset's internal implementation; signatures
 *           may change between minor releases of v1.x. Consumers should depend
 *           on the published Admnio\Sunset\Contracts\* interfaces instead.
 */
class FifoMessageAttributes
{
    public function __construct(private array $config)
    {
    }

    public function for(string $queue, string $payload, array $options): array
    {
        if (! str_ends_with($queue, '.fifo')) {
            return [];
        }

        $strategy = $this->config['message_group_id'] ?? 'queue-name';
        $decoded = json_decode($payload, true) ?: [];

        $groupId = match (true) {
            is_callable($strategy) => $strategy($decoded, $queue),
            $strategy === 'queue-name' => $queue,
            $strategy === 'job-class' => $decoded['data']['commandName'] ?? $queue,
            default => throw new InvalidArgumentException("Unknown FIFO group strategy: {$strategy}"),
        };

        $dedupId = $options['MessageDeduplicationId']
            ?? (($this->config['content_based_dedup'] ?? true) ? hash('sha256', $payload) : null);

        $result = ['MessageGroupId' => $groupId];
        if ($dedupId !== null) {
            $result['MessageDeduplicationId'] = $dedupId;
        }

        return $result;
    }
}
