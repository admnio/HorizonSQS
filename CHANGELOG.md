# Changelog

All notable changes to Sunset are documented here. Format follows [Keep a Changelog](https://keepachangelog.com/en/1.1.0/) and Sunset adheres to [Semantic Versioning](https://semver.org/).

## v1.2.1

Drop Server-Sent Events; the Activity page now polls the same way every other dashboard page does.

### Removed
- `Admnio\Sunset\Activity\ActivityStreamer` (internal) — no replacement; the recorder + sorted-set buffer cover what the streamer's read side did.
- `Admnio\Sunset\Dashboard\Http\Controllers\ActivityController::stream()` and the `GET /sunset/activity/stream` route.
- `useActivityStream` Vue composable.
- Config keys `sunset.activity.max_connection_seconds`, `sunset.activity.heartbeat_interval_seconds`, `sunset.activity.poll_interval_seconds` (plus their `SUNSET_ACTIVITY_*` env hooks). The dashboard now uses the existing `dashboard.poll_interval_seconds` (default 3s) for the Activity page like every other page.
- README's "Octane note (activity stream)" section — no longer applicable.

### Changed
- `ActivityController::show()` now emits `page_url` instead of `stream_url`. The Inertia props shape is otherwise unchanged.
- `Activity.vue` switched to the same `usePolling()` composable every other dashboard page uses. Removed Pause/Resume controls (polling has nothing to pause that closing the tab doesn't already cover) and the live/reconnecting status pill.

### Why
Streaming added an Octane worker-starvation tradeoff that wasn't worth the freshness benefit for an observability page that already needed a recorder + buffer for replay. Polling at 3s is fast enough for the use case, removes the per-tab worker slot cost, and keeps the page consistent with the rest of the dashboard. Public-API contracts (`ActivityRepository`, `ActivityEvent`, `ActivityRecorded`) are unchanged.

## v1.2.0

Realtime worker activity stream — a new `/sunset/activity` dashboard page powered by Server-Sent Events.

### Added
- `Admnio\Sunset\Contracts\ActivityRepository` (public contract) with `recent(limit)`, `since(after_id, limit)`, `before(before_id, limit)`. Default Redis implementation lives at `Admnio\Sunset\Repositories\Redis\RedisActivityRepository`.
- `Admnio\Sunset\Activity\ActivityEvent` (public readonly value object) — `id, type, occurred_at, payload`. `toArray()` / `fromArray()` / `toJson()` / `fromJson()` for serialization.
- `Admnio\Sunset\Events\ActivityRecorded` (public event) — fires after each event lands in the buffer. Consumers subscribe to forward activity to Slack / audit logs / Datadog.
- `ActivityRecorder` listener subscribes to 8 Sunset events: `JobFailed`, `JobCompleted`, `JobRateLimited`, `JobQueued`, `WorkerProcessRestarting`, `UnableToLaunchProcess`, `LongWaitDetected`, `MasterSupervisorDeployed`. Translates each into an `ActivityEvent`, writes to a capped Redis sorted set with INCR-assigned monotonic ids, fires `ActivityRecorded`.
- `Admnio\Sunset\Activity\ActivityStreamer` — pure cursor-poll SSE generator with `Last-Event-ID` resume, heartbeat (default 15s), and max-connection-seconds (default 60).
- `Admnio\Sunset\Dashboard\Http\Controllers\ActivityController` — `show()` Inertia render, `page()` paginated JSON (`?before_id=`), `stream()` SSE response with proxy-friendly headers (`Cache-Control: no-cache, no-transform, no-store`, `X-Accel-Buffering: no`, `Connection: close`).
- New `Activity.vue` dashboard page: live event log with category filter chips (`all` / `errors` (default) / `lifecycle` / `supervisor`), pause/resume with queue-while-paused count, click-to-expand JSON payload, "load older" pagination, 1000-event client ring buffer.
- Config block `sunset.activity.{enabled, stream_buffer_size, max_connection_seconds, heartbeat_interval_seconds, poll_interval_seconds}` with env-var hooks (`SUNSET_ACTIVITY_*`).
- LeftRail nav now shows "activity" between "home" and "workload" under the Overview group.

### Notes
- We chose a server-side cursor-poll model (default 5s) over Redis pub/sub. pub/sub semantics differ subtly between phpredis and predis around blocking reads + read timeouts + heartbeat timers, and the fiddliness wasn't worth it for v1.2.0 against three transport libraries. "Stream" here means "the server polls Redis every 5s and forwards new events as SSE frames," not "Redis pushes directly to the connection." Sub-second freshness becomes a v1.2.x optimization if anyone asks.
- Under Laravel Octane, each connected dashboard tab consumes a worker slot for up to `max_connection_seconds`. README documents two mitigations: route `/sunset/*` to a separate FPM tier, or disable streaming with `SUNSET_ACTIVITY_ENABLED=false`.

## v1.1.0

Worker telemetry — per-worker RSS and CPU sampled on the queue Looping event and surfaced on the Supervisors dashboard page.

### Added
- `Admnio\Sunset\Contracts\WorkerMetricsRepository` (public contract) and `Admnio\Sunset\Telemetry\WorkerMetricsSnapshot` (public value object) for reading worker metrics.
- `WorkerLoopListener` subscribes to `Illuminate\Queue\Events\Looping` and `JobProcessed`. Throttled sampling (default 5s) keeps Redis traffic bounded; `cpu_pct` uses `getrusage()` user+sys deltas over wall-clock deltas.
- `RedisWorkerMetricsRepository` stores per-PID snapshot hash (TTL 30s) plus capped sorted-set series for RSS and CPU (default 60 points = 5 minutes at 5s interval).
- `sunset:sweep-worker-metrics` artisan command, scheduled every minute, reconciles the `worker_metrics:pids` set against TTL-expired hashes and deletes orphan series.
- `/sunset/supervisors` dashboard page renders new "Workers" section with RSS / CPU% columns and a click-to-toggle RSS↔CPU inline sparkline per PID.
- Config block `sunset.telemetry.{enabled, interval_seconds, series_points}` with env-var hooks (`SUNSET_TELEMETRY_ENABLED`, `SUNSET_TELEMETRY_INTERVAL`, `SUNSET_TELEMETRY_SERIES_POINTS`).

### Notes
- CPU% reads as `null` on Windows where `getrusage()` returns zeros for user/sys time; RSS works everywhere.
- Listener swallows Redis-down exceptions silently (debug-logged) — telemetry is observability, not load-bearing.

## v1.0.2

Polish release. No public-API changes.

### Added
- `CONTRIBUTING.md` — quickstart, testsuites, bundle rebuild, conventions, PR workflow, public-API scope.
- `SECURITY.md` — vulnerability reporting to security@admn.io, supported-versions table, scope.
- README Octane note — `Sunset::auth()` static-callback semantics across Octane worker boots.

### Fixed
- `SunsetServiceProvider` binds `Illuminate\Queue\Worker::class` via `singletonIf` so `vendor/bin/testbench list` and consumer-side tooling resolve `SunsetWorkerCommand` (which extends Laravel's `WorkCommand`) without app-level wiring.
- `tests/TestCase` forces `cache.default=array`. Testbench's default `database` driver needs the `cache` migration; `SunsetWorkloadRepository`'s cache and Laravel's queue-restart signal both write through it.

## v1.0.1

Polish release. No public-API changes.

### Fixed
- Batches-page configuration banner and `Toast` component use theme-aware status colors so amber/red pills meet WCAG 2.1 AA contrast in both light and dark themes.

## v1.0.0

First stable release. Backwards-compatible public API commitment.

### Added
- A11y pass: WCAG 2.1 AA — skip link, ARIA landmarks, focus-visible rings, command palette dialog roles, contrast-corrected status pills.
- Health page reports transport reachability probes (Redis ping, SQS list-queues, RabbitMQ TCP), PHP/Laravel/Sunset versions, Redis prefix, registered rate-limit count, scheduled-command summary.
- `@internal` markers on ~100 internal classes; "Public API" section in README documenting the stable v1.x surface.

### Changed
- `RateLimitStatsRepository::detectPrefix()` and `SunsetSweepRateLimitSlotsCommand::detectPrefix()` cache per-instance.

## v0.9.1
- Wire `orchestra/testbench-dusk` for Dusk browser tests in CI.

## v0.9.0
- `RateLimitStatsRepository` uses Redis SCAN cursor iteration instead of KEYS.
- New batched `/sunset/metrics/series` endpoint (avoids N-parallel-fetches on the Metrics page).
- CI: RabbitMQ service added to integration job; new bundle verification job (`npm run build` must match committed `public-dist/`).
- `@dataProvider` doc-comments converted to `#[DataProvider]` attributes (cleared 2 PHPUnit deprecations).

## v0.8.2
- `RateLimitStatsRepository` exposes a public read API for `sunset:rl:rejects:*` counters; rate-limits dashboard page renders them.
- `/sunset/metrics/jobs/{name}` and `/sunset/metrics/queues/{name}` routes; Metrics page renders inline sparklines.
- `PollingShapeContractTest` locks the same-route polling contract (initial Inertia render must match `?refresh=1` JSON prop keys).
- 17 risky test markers cleaned up (`addToAssertionCount` reflects Mockery's already-enforced expectations).
- `RELEASING.md` maintainer checklist.

## v0.8.1
- `AutoScaler` uses native `Queue::size()` instead of Horizon's `readyNow()` macro (fixes a real production bug post-Horizon-removal).
- `SunsetServiceProvider::resolveQueueList()` reads `sunset.environments` first; falls back to `horizon.environments` for one release.
- `dashboard.poll_interval_seconds` config wired through Inertia shared props into the `usePolling()` composable; all 12 pages dropped their hardcoded `3000` argument.

## v0.8.0
- Sunset SPA dashboard at `/sunset` (Inertia + Vue 3 + Tailwind + Pinia).
- 12 pages: Overview, Workload, Recent/Failed/Pending/Completed jobs, Metrics, Monitoring, Rate Limits, Supervisors, Batches, Health.
- Master-detail layout for Failed Jobs (list + selected job's exception + retry/delete actions).
- Left-rail grouped navigation (Overview/Jobs/Ops); hamburger drawer on mobile; full responsive down to phone.
- Light + dark theming via `prefers-color-scheme`; user override toggle.
- Polling every 3s via `?refresh=1` on same routes.
- Command palette (⌘K) for fuzzy navigation.
- `Sunset::auth()` single gate (default: localhost-only outside `local` env).
- Destructive actions: retry/delete failed jobs; pause/resume supervisors; pin/unpin tags.
- `laravel/horizon` removed from `composer.json`. 7 `src/Adapters/Horizon/*` files deleted. 4 source files refactored to use native Sunset contracts. New `Admnio\Sunset\Contracts\WorkloadRepository`.

## v0.7.0
- Queue rate limiting. Fluent API: `Sunset::for($queueName)->throttle(...)->concurrency(...)` and `Sunset::limit($jobClass)->...`.
- Sliding-window throttle (Redis sorted sets via Lua).
- Semaphore concurrency (Redis sets with TTL'd slot keys via Lua).
- Three over-limit strategies: release-computed (default), release-fixed, drop. Drop has dropAsFailure toggle.
- Conditions via `->when()`; dynamic bucket keys via `->by()`.
- Composition: chained throttle+concurrency atomic in one limit.
- All three transports (SQS, Redis, RabbitMQ) hooked uniformly; zero overhead when no limits are registered.
- `JobRateLimited` event; `RateLimitExceededException`.
- `sunset:sweep-rate-limit-slots` scheduled command reconciles orphaned concurrency slots.

## v0.6.0
- RabbitMQ as a first-class transport. Mirrors v0.3.0's Redis pattern; reuses Sunset's `DelayedJobStore` for delays.
- DelayedJobStore tracks source connection (was hardcoded to 'sqs').
- Transport connectors registered in `booted()` so vendor providers can't overwrite Sunset's bindings.

## v0.5.0 and earlier

See git history.
