<?php

namespace Admnio\Sunset\Tests\Integration\Dashboard;

use Admnio\Sunset\Facades\Sunset;
use Admnio\Sunset\Manager;
use Admnio\Sunset\Tests\Integration\IntegrationTestCase;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * Regression guard for the same-route polling contract.
 *
 * The dashboard SPA fetches the same URL with ?refresh=1 every poll tick
 * and binds the returned JSON shape to the same Vue component that the
 * initial Inertia render uses. If a controller change ever returns a
 * different prop set under one path vs the other, the polling client
 * silently breaks — values stop updating or undefined-key errors appear.
 *
 * This test fetches each dashboard page via both paths and asserts the
 * top-level prop KEYS are identical.
 */
class PollingShapeContractTest extends IntegrationTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Manager::flushAuth();
        Sunset::auth(fn () => true);
    }

    #[DataProvider('pages')]
    public function test_inertia_and_refresh_return_same_prop_keys(string $path): void
    {
        // Inertia path: send the X-Inertia header so the framework returns the JSON envelope
        // instead of a full HTML page.
        $inertia = $this->withHeaders(['X-Inertia' => 'true'])->getJson($path);
        $inertia->assertStatus(200);

        $inertiaProps = $inertia->json('props');
        $this->assertIsArray($inertiaProps, "Inertia render for {$path} must return a props array");

        // Polling path: ?refresh=1 returns the bare JSON.
        $refresh = $this->getJson($path . '?refresh=1');
        $refresh->assertStatus(200);

        $refreshProps = $refresh->json('props');
        $this->assertIsArray($refreshProps, "?refresh=1 for {$path} must return a props array");

        // Filter out shared props that intentionally only appear on initial Inertia render.
        // `sunset` is injected by SetSunsetInertiaRoot via Inertia::share() — the polling
        // client already received it on first mount, so re-sending it every tick would be
        // wasteful. This filter encodes that known-acceptable divergence.
        $sharedPropKeys = ['sunset'];

        $inertiaKeys = array_values(array_diff(array_keys($inertiaProps), $sharedPropKeys));
        $refreshKeys = array_values(array_diff(array_keys($refreshProps), $sharedPropKeys));

        sort($inertiaKeys);
        sort($refreshKeys);

        $this->assertSame(
            $inertiaKeys,
            $refreshKeys,
            "Top-level prop keys diverge between Inertia render and ?refresh=1 polling for {$path}.\n" .
            "Inertia: " . json_encode($inertiaKeys) . "\n" .
            "Refresh: " . json_encode($refreshKeys)
        );
    }

    public static function pages(): array
    {
        return [
            ['/sunset'],
            ['/sunset/workload'],
            ['/sunset/jobs/recent'],
            ['/sunset/jobs/failed'],
            ['/sunset/jobs/pending'],
            ['/sunset/jobs/completed'],
            ['/sunset/metrics'],
            ['/sunset/monitoring'],
            ['/sunset/rate-limits'],
            ['/sunset/supervisors'],
            ['/sunset/batches'],
            ['/sunset/health'],
            ['/sunset/activity'],
        ];
    }
}
