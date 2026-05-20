<?php

namespace Admnio\Sunset\Tests\Integration\Dashboard;

use Admnio\Sunset\Facades\Sunset;
use Admnio\Sunset\Manager;
use Admnio\Sunset\Tests\Integration\IntegrationTestCase;
use Illuminate\Contracts\Redis\Factory as RedisFactory;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * Smoke-test every dashboard GET route returns a 200 with the expected props
 * shape when polled via the same-route JSON fallback (?refresh=1). The
 * Authorize middleware is satisfied by registering a permissive gate in
 * setUp() so this exercise focuses on controller wiring, not auth.
 */
class PageRoundtripTest extends IntegrationTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        // Reset the static auth closure so a permissive gate is in force
        // for these tests regardless of what other suites registered.
        Manager::flushAuth();
        Sunset::auth(fn () => true);

        // Wipe sunset:* keys so prior runs don't pollute the page payloads
        // (e.g. leftover failed_jobs entries influencing the failed page).
        $conn = $this->app->make(RedisFactory::class)
            ->connection(config('sunset.redis_connection', 'default'));
        foreach ((array) $conn->keys('sunset:*') as $k) {
            $conn->del($k);
        }
    }

    #[DataProvider('pages')]
    public function test_page_returns_props_shape(string $path, array $expectedKeys): void
    {
        $response = $this->getJson($path . '?refresh=1');

        $response->assertStatus(200);

        $props = $response->json('props');
        $this->assertIsArray($props, "Expected props array from {$path}, got " . json_encode($response->json()));

        foreach ($expectedKeys as $key) {
            $this->assertArrayHasKey($key, $props, "Page {$path} should return a '{$key}' prop");
        }
    }

    public static function pages(): array
    {
        return [
            'overview'      => ['/sunset',                ['workload', 'supervisors', 'masters', 'recent']],
            'workload'      => ['/sunset/workload',       ['queues']],
            'recent'        => ['/sunset/jobs/recent',    ['jobs', 'total']],
            'failed'        => ['/sunset/jobs/failed',    ['failures', 'total', 'recent']],
            'pending'       => ['/sunset/jobs/pending',   ['jobs', 'total']],
            'completed'     => ['/sunset/jobs/completed', ['jobs', 'total']],
            'metrics'       => ['/sunset/metrics',        ['jobs', 'queues', 'snapshot_taken_at', 'wait_times']],
            'monitoring'    => ['/sunset/monitoring',     ['pinned', 'counts']],
            'rate-limits'   => ['/sunset/rate-limits',    ['limits', 'rejects']],
            'supervisors'   => ['/sunset/supervisors',    ['supervisors', 'masters']],
            'batches'       => ['/sunset/batches',        ['batches', 'configured']],
            'health'        => ['/sunset/health',         ['versions', 'transports', 'redis', 'rate_limits', 'schedule']],
        ];
    }

    public function test_unauthorized_request_is_denied(): void
    {
        Manager::flushAuth();
        Sunset::auth(fn () => false);

        $response = $this->getJson('/sunset?refresh=1');
        $response->assertStatus(403);
    }
}
