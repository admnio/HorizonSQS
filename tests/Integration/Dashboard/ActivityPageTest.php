<?php

namespace Admnio\Sunset\Tests\Integration\Dashboard;

use Admnio\Sunset\Activity\ActivityEvent;
use Admnio\Sunset\Contracts\ActivityRepository;
use Admnio\Sunset\Facades\Sunset;
use Admnio\Sunset\Manager;
use Admnio\Sunset\Repositories\Redis\RedisActivityRepository;
use Admnio\Sunset\Tests\Integration\IntegrationTestCase;
use Illuminate\Contracts\Redis\Factory as RedisFactory;

/**
 * Covers the GET /sunset/activity (Inertia + ?refresh=1 JSON) and GET
 * /sunset/activity/page?before_id=X branches of the new ActivityController.
 *
 * The stream() branch lives in its own test (ActivityStreamTest) so the
 * StreamedResponse plumbing — clock, sleep, ob_flush — stays isolated from
 * the prop-shape assertions here.
 */
class ActivityPageTest extends IntegrationTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        // Permissive Sunset auth so the Authorize middleware doesn't 403 the
        // test (no localhost detection in the testbench HTTP harness).
        Manager::flushAuth();
        Sunset::auth(fn () => true);

        // Wipe the test Redis DB so a prior run's activity events don't bleed
        // into this run's assertions about ordering / counts.
        $this->app->make(RedisFactory::class)
            ->connection(config('sunset.redis_connection', 'default'))
            ->flushdb();

        config(['sunset.activity.enabled' => true]);
    }

    public function test_refresh_response_returns_recent_events_descending_with_enabled_and_page_url(): void
    {
        $this->seedEvents(5);

        $response = $this->getJson('/sunset/activity?refresh=1');
        $response->assertStatus(200);

        $props = $response->json('props');
        $this->assertIsArray($props);

        $this->assertArrayHasKey('events', $props);
        $this->assertArrayHasKey('enabled', $props);
        $this->assertArrayHasKey('page_url', $props);

        $this->assertTrue($props['enabled']);
        $this->assertStringContainsString('/sunset/activity/page', $props['page_url']);

        $events = $props['events'];
        $this->assertCount(5, $events);

        // Descending by id: ids 5, 4, 3, 2, 1.
        $ids = array_map(fn ($e) => $e['id'], $events);
        $this->assertSame([5, 4, 3, 2, 1], $ids);

        // Shape sanity-check on the first row.
        foreach (['id', 'type', 'occurred_at', 'payload'] as $key) {
            $this->assertArrayHasKey($key, $events[0]);
        }
    }

    public function test_initial_inertia_render_returns_same_prop_shape(): void
    {
        $this->seedEvents(3);

        $response = $this->withHeaders(['X-Inertia' => 'true'])->getJson('/sunset/activity');
        $response->assertStatus(200);

        $props = $response->json('props');
        $this->assertIsArray($props);
        $this->assertArrayHasKey('events', $props);
        $this->assertArrayHasKey('enabled', $props);
        $this->assertArrayHasKey('page_url', $props);

        $this->assertCount(3, $props['events']);
        $this->assertTrue($props['enabled']);
        $this->assertStringContainsString('/sunset/activity/page', $props['page_url']);
    }

    public function test_page_endpoint_returns_events_strictly_before_the_cursor_descending(): void
    {
        $this->seedEvents(5);

        $response = $this->getJson('/sunset/activity/page?before_id=3');
        $response->assertStatus(200);

        $events = $response->json('events');
        $this->assertIsArray($events);

        // before_id=3 → strict inequality → ids 2 and 1, descending.
        $ids = array_map(fn ($e) => $e['id'], $events);
        $this->assertSame([2, 1], $ids);
    }

    public function test_show_returns_empty_events_list_when_no_events_recorded(): void
    {
        $response = $this->getJson('/sunset/activity?refresh=1');
        $response->assertStatus(200);

        $props = $response->json('props');
        $this->assertSame([], $props['events']);
        $this->assertTrue($props['enabled']);
    }

    /**
     * Seed N events with distinct types so each one is a distinguishable row.
     * record() assigns the monotonic id, so the first seeded event ends up
     * with id=1 and so on through id=N.
     */
    private function seedEvents(int $count): void
    {
        /** @var RedisActivityRepository $repo */
        $repo = $this->app->make(RedisActivityRepository::class);
        $this->assertInstanceOf(RedisActivityRepository::class, $repo);
        $this->assertInstanceOf(ActivityRepository::class, $repo);

        // Same pool of distinct types so each seeded row has its own identity.
        $types = [
            'job_failed',
            'job_completed',
            'job_rate_limited',
            'worker_process_restarting',
            'master_supervisor_deployed',
        ];

        for ($i = 0; $i < $count; $i++) {
            $repo->record(new ActivityEvent(
                id: 0,
                type: $types[$i % count($types)],
                occurredAt: 1_700_000_000 + $i,
                payload: ['seq' => $i],
            ));
        }
    }
}
