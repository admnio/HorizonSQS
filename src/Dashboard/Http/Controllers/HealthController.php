<?php

namespace Admnio\Sunset\Dashboard\Http\Controllers;

use Admnio\Sunset\RateLimiting\LimitRegistry;
use Illuminate\Contracts\Redis\Factory as RedisFactory;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Inertia\Response as InertiaResponse;
use Throwable;

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
            'versions'    => $this->versions(),
            'transports'  => $this->probeTransports(),
            'redis'       => $this->probeRedis(),
            'rate_limits' => $this->rateLimitsSummary(),
            'schedule'    => $this->scheduledCommands(),
        ]);
    }

    private function versions(): array
    {
        return [
            'php'     => PHP_VERSION,
            'laravel' => app()->version(),
            'sunset'  => $this->sunsetVersion(),
        ];
    }

    private function sunsetVersion(): string
    {
        // src/Dashboard/Http/Controllers/HealthController.php -> repo root composer.json
        // is 4 directory levels up (Controllers -> Http -> Dashboard -> src -> root).
        $composerJson = __DIR__ . '/../../../../composer.json';
        if (! file_exists($composerJson)) {
            return 'unknown';
        }
        $data = json_decode((string) file_get_contents($composerJson), true);
        if (! is_array($data)) {
            return 'unknown';
        }

        return (string) ($data['version'] ?? 'dev');
    }

    private function probeTransports(): array
    {
        $out = [];
        foreach (['sqs', 'redis', 'rabbitmq'] as $name) {
            $cfg = config("queue.connections.{$name}");
            $configured = is_array($cfg) && $cfg !== [];
            $row = [
                'name'       => $name,
                'configured' => $configured,
                'reachable'  => null,
                'error'      => null,
                'driver'     => is_array($cfg) ? ($cfg['driver'] ?? null) : null,
                'latency_ms' => null,
            ];
            if ($configured) {
                $result = $this->pingTransport($name);
                $row['reachable']  = $result['ok'];
                $row['error']      = $result['error'];
                $row['latency_ms'] = $result['latency_ms'];
            }
            $out[] = $row;
        }

        return $out;
    }

    private function pingTransport(string $name): array
    {
        $start = microtime(true);
        try {
            switch ($name) {
                case 'redis':
                    $conn = config('queue.connections.redis.connection', 'default');
                    app('redis')->connection($conn)->ping();
                    break;
                case 'sqs':
                    $cfg = (array) config('queue.connections.sqs');
                    $clientCfg = [
                        'region'   => $cfg['region'] ?? 'us-east-1',
                        'version'  => 'latest',
                        'http'     => ['timeout' => 1, 'connect_timeout' => 1],
                    ];
                    if (! empty($cfg['endpoint'])) {
                        $clientCfg['endpoint'] = $cfg['endpoint'];
                    }
                    if (! empty($cfg['key']) && ! empty($cfg['secret'])) {
                        $clientCfg['credentials'] = [
                            'key'    => $cfg['key'],
                            'secret' => $cfg['secret'],
                        ];
                    }
                    $client = new \Aws\Sqs\SqsClient($clientCfg);
                    $client->listQueues(['MaxResults' => 1]);
                    break;
                case 'rabbitmq':
                    $cfg = config('queue.connections.rabbitmq.hosts.0');
                    if (! is_array($cfg) || empty($cfg['host'])) {
                        return ['ok' => false, 'error' => 'No hosts configured', 'latency_ms' => 0];
                    }
                    $sock = @fsockopen(
                        (string) $cfg['host'],
                        (int) ($cfg['port'] ?? 5672),
                        $errno,
                        $errstr,
                        1.0
                    );
                    if (! $sock) {
                        return [
                            'ok' => false,
                            'error' => $errstr !== '' ? $errstr : 'connection refused',
                            'latency_ms' => (int) round((microtime(true) - $start) * 1000),
                        ];
                    }
                    fclose($sock);
                    break;
            }

            return [
                'ok' => true,
                'error' => null,
                'latency_ms' => (int) round((microtime(true) - $start) * 1000),
            ];
        } catch (Throwable $e) {
            return [
                'ok' => false,
                'error' => substr($e->getMessage(), 0, 200),
                'latency_ms' => (int) round((microtime(true) - $start) * 1000),
            ];
        }
    }

    private function probeRedis(): array
    {
        $conn = (string) config('sunset.redis_connection', 'default');
        try {
            $factory = app(RedisFactory::class);
            $redis = $factory->connection($conn);
            $start = microtime(true);
            $redis->ping();
            $latency = (int) round((microtime(true) - $start) * 1000);

            $prefix = (string) config('database.redis.options.prefix', '');

            return [
                'connection' => $conn,
                'reachable'  => true,
                'latency_ms' => $latency,
                'prefix'     => $prefix,
                'error'      => null,
            ];
        } catch (Throwable $e) {
            return [
                'connection' => $conn,
                'reachable'  => false,
                'latency_ms' => 0,
                'prefix'     => '',
                'error'      => substr($e->getMessage(), 0, 200),
            ];
        }
    }

    private function rateLimitsSummary(): array
    {
        try {
            $registry = app(LimitRegistry::class);
            $limits = $registry->all();

            return [
                'count'      => count($limits),
                'has_limits' => count($limits) > 0,
            ];
        } catch (Throwable) {
            return ['count' => 0, 'has_limits' => false];
        }
    }

    private function scheduledCommands(): array
    {
        // Hardcoded list of Sunset scheduled commands. Reading from Laravel's
        // Schedule requires the scheduler to be booted, which isn't guaranteed
        // in test contexts. This list mirrors what the ServiceProvider wires up.
        return [
            [
                'command' => 'sunset:sweep-delayed',
                'cadence' => 'every minute',
                'purpose' => 'Re-enqueue jobs whose delay has expired',
            ],
            [
                'command' => 'sunset:sweep-rate-limit-slots',
                'cadence' => 'every minute',
                'purpose' => 'Reconcile orphaned concurrency slots',
            ],
            [
                'command' => 'sunset:snapshot',
                'cadence' => 'every minute',
                'purpose' => 'Per-supervisor workload snapshot',
            ],
        ];
    }
}
