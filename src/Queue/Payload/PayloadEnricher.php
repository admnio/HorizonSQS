<?php

namespace MasonWorkforce\HorizonSqs\Queue\Payload;

use Ramsey\Uuid\Uuid;

class PayloadEnricher
{
    public function enrich(array $payload, string $queue): array
    {
        $payload['id'] = $payload['id'] ?? Uuid::uuid4()->toString();
        $payload['pushedAt'] = $payload['pushedAt'] ?? microtime(true);
        $payload['_horizon_nonce'] = $payload['_horizon_nonce'] ?? bin2hex(random_bytes(8));

        $existing = $payload['tags'] ?? [];
        $payload['tags'] = array_values(array_unique($existing));

        return $payload;
    }
}
