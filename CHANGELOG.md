# Changelog

All notable changes to Sunset are documented here. Format follows [Keep a Changelog](https://keepachangelog.com/en/1.1.0/) and Sunset adheres to [Semantic Versioning](https://semver.org/).

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
