<?php

namespace Admnio\Sunset\Dashboard\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Inertia\Response as InertiaResponse;

/**
 * @internal This class is part of Sunset's internal implementation; signatures
 *           may change between minor releases of v1.x. Consumers should depend
 *           on the published Admnio\Sunset\Contracts\* interfaces instead.
 */
final class HealthController extends Controller
{
    public function show(Request $request): InertiaResponse|JsonResponse
    {
        return $this->inertiaOrJson($request, 'Sunset/Health', [
            'config'     => $this->snapshotConfig(),
            'transports' => $this->probeTransports(),
            'version'    => '0.8.0',
        ]);
    }

    /**
     * Capture the sunset config block with secret-ish keys masked. Anything
     * the operator would set via env that contains a secret should be hidden;
     * we redact rather than omit so the page can show the key as "set".
     */
    private function snapshotConfig(): array
    {
        $sunset = (array) config('sunset', []);

        return $this->redactSecrets($sunset);
    }

    private function redactSecrets(array $value): array
    {
        $sensitivePattern = '/(secret|password|token|key|credential)/i';

        foreach ($value as $k => $v) {
            if (is_array($v)) {
                $value[$k] = $this->redactSecrets($v);
                continue;
            }
            if (is_string($k) && preg_match($sensitivePattern, $k) && $v !== null && $v !== '') {
                $value[$k] = '***';
            }
        }

        return $value;
    }

    private function probeTransports(): array
    {
        $out = [];
        foreach (['sqs', 'redis', 'rabbitmq'] as $name) {
            $cfg = config("queue.connections.{$name}");
            $out[$name] = [
                'configured' => is_array($cfg) && $cfg !== [],
                'driver'     => is_array($cfg) ? ($cfg['driver'] ?? null) : null,
            ];
        }

        return $out;
    }
}
