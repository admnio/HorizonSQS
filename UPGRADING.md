# Upgrading from `masonworkforce/horizon-sqs` v0.1.x to `admnio/sunset` v0.2.0

The package was renamed and restructured. Runtime behavior is identical — same dashboard, same SQS support, same Redis sidecar. This is a mechanical migration: composer swap, config rename, optional artisan command for Redis keys.

**Before you start:** stop your Horizon workers (`php artisan horizon:terminate` or systemd stop). This prevents in-flight long-delay jobs from being written to the old Redis key during the migration window.

## 1. Composer swap

```bash
composer remove masonworkforce/horizon-sqs
composer require admnio/sunset:^0.2
php artisan vendor:publish --tag=sunset-config
```

## 2. Config rename + reshape

Remove the old config file and inspect the new one (published in the step above):

```bash
rm config/horizon-sqs.php
```

The config structure changed. Top-level SQS-specific keys moved under `transports.sqs.*`:

**Before (`config/horizon-sqs.php`)**:
```php
return [
    'redis_connection' => env('HORIZON_SQS_REDIS', 'default'),
    'workload_cache_ttl' => 5,
    'sqs_max_delay' => 900,
    'long_delay_sweep_interval' => 60,
    'visibility_heartbeat' => false,
    'fifo' => [
        'message_group_id' => 'queue-name',
        'content_based_dedup' => true,
    ],
    'extended_payload' => [
        'enabled' => false,
        'bucket' => env('HORIZON_SQS_S3_BUCKET'),
        'prefix' => 'horizon-sqs-payloads/',
        'lifecycle_days' => 7,
    ],
];
```

**After (`config/sunset.php`)**:
```php
return [
    'redis_connection' => env('SUNSET_REDIS', 'default'),
    'workload_cache_ttl' => 5,
    'transports' => [
        'sqs' => [
            'sqs_max_delay' => 900,
            'long_delay_sweep_interval' => 60,
            'visibility_heartbeat' => false,
            'fifo' => [
                'message_group_id' => 'queue-name',
                'content_based_dedup' => true,
            ],
            'extended_payload' => [
                'enabled' => false,
                'bucket' => env('SUNSET_S3_BUCKET'),
                'prefix' => 'sunset-payloads/',
                'lifecycle_days' => 7,
            ],
        ],
    ],
];
```

Re-apply any local edits to the published file.

## 3. Env vars

Rename in your `.env`:

| Before | After |
|---|---|
| `HORIZON_SQS_REDIS` | `SUNSET_REDIS` |
| `HORIZON_SQS_S3_BUCKET` | `SUNSET_S3_BUCKET` |

## 4. PHP code references in your app

Most apps only touch config; these table entries apply only if you imported package classes directly.

| Before (v0.1.x) | After (v0.2.0) |
|---|---|
| `use MasonWorkforce\HorizonSqs\…` | `use Admnio\Sunset\…` |
| `MasonWorkforce\HorizonSqs\HorizonSqsServiceProvider` | `Admnio\Sunset\SunsetServiceProvider` |
| `MasonWorkforce\HorizonSqs\Exceptions\…` | `Admnio\Sunset\Exceptions\…` |
| `MasonWorkforce\HorizonSqs\HorizonSqsQueue` (custom subclass) | `Admnio\Sunset\Transports\Sqs\SqsQueue` |
| `MasonWorkforce\HorizonSqs\HorizonSqsConnector` (direct instantiation) | `Admnio\Sunset\Transports\Sqs\SqsConnector` (constructor signature changed — see note below) |
| `php artisan horizon-sqs:sweep-delayed` | `php artisan sunset:sweep-delayed` |

**Note on `SqsConnector`:** Its constructor signature was simplified in v0.2.0. It now takes only a `TransportRegistry`. In practice this affects no one — Laravel's `QueueManager` is the only caller and it resolves the connector through the container.

## 5. Data carryover

- **SQS queues** are unchanged — same URLs, in-flight jobs continue to be processed.
- **S3 extended payload prefix** default changed (`horizon-sqs-payloads/` → `sunset-payloads/`). Existing buckets keep their objects under the old prefix. Either:
  - Keep your `config('sunset.transports.sqs.extended_payload.prefix')` set to `'horizon-sqs-payloads/'` indefinitely, or
  - Do a one-time S3 copy from the old prefix to the new, then update config.
- **Redis sidecar keys** for long-delayed jobs changed (`horizon-sqs:delayed` → `sunset:delayed`). Run the migration command after deploying:

  ```bash
  php artisan sunset:migrate-redis-keys
  ```

  This uses Redis `RENAME` with safety checks: if `sunset:delayed` is already populated, it refuses to overwrite and prints the member counts of both keys for manual inspection. Idempotent — safe to run more than once.

  The other ephemeral cache key (`horizon-sqs:workload`) is a 5-second cache; no migration needed.

## 6. Scheduler entries

If you copied the package's auto-scheduled sweep into your own `app/Console/Kernel.php`, change:

```php
$schedule->command('horizon-sqs:sweep-delayed')->everyMinute();
```

to:

```php
$schedule->command('sunset:sweep-delayed')->everyMinute();
```

If you let the package auto-register the schedule (default), nothing to do.

## 7. Horizon config

`config/horizon.php` supervisor blocks with `'connection' => 'sqs'` are unchanged. Sunset still extends Laravel's `sqs` queue driver.

## 8. Restart workers

After config is in place and the Redis migration has run:

```bash
php artisan horizon
```

(Or restart your supervisor / systemd unit.)

## Rollback

If something breaks, you can roll back by reinstalling v0.1.x:

```bash
composer remove admnio/sunset
composer require masonworkforce/horizon-sqs:^0.1
```

The Redis key migration is one-way (the `RENAME` moved the key). To roll back data, restore the old key from a Redis backup, or manually copy with `redis-cli COPY sunset:delayed horizon-sqs:delayed REPLACE`.

## Issues

File issues against the `admnio/sunset` repository.

---

# Upgrading from `admnio/sunset` v0.2.0 to v0.3.0

v0.3.0 adds the Redis transport. SQS users have nothing to do. Redis users get Sunset's enrichment automatically — restart workers after the upgrade.

## 1. Composer

```bash
composer update admnio/sunset
```

## 2. Config

Republish the config to pick up the new `transports.redis` block (or manually add it):

```bash
php artisan vendor:publish --tag=sunset-config --force
```

If you don't want to overwrite your existing `config/sunset.php`, manually append a `redis` entry to your `transports` array:

```php
'transports' => [
    'sqs' => [ /* unchanged */ ],
    'redis' => [
        'workload_connection' => env('SUNSET_REDIS_WORKLOAD_CONN', 'default'),
    ],
],
```

## 3. Queue config

No changes to `config/queue.php`. Existing `'driver' => 'redis'` blocks Just Work — Sunset's connector overrides Laravel's stock Redis driver via load order.

## 4. Horizon config

No changes to `config/horizon.php`.

## 5. Restart workers

```bash
php artisan horizon:terminate
```

Then restart your worker supervisor / systemd unit. New worker processes will go through Sunset's `Admnio\Sunset\Transports\Redis\RedisQueue` and dispatch Horizon events from there.

## 6. Verify

After workers come back up, dispatch a job to a Redis queue and confirm it shows up in Horizon's dashboard at `/horizon/jobs/pending`. The Recent Jobs and Tags pages should populate too.

## What changed under the hood

- New `Admnio\Sunset\Transports\Redis\` namespace with `RedisConnector`, `RedisQueue`, and `RedisTransport`.
- `Queue::extend('redis', ...)` overrides Laravel's stock `RedisConnector` (and Horizon's too — Sunset's `boot()` runs after Horizon's).
- The Redis transport delegates all connection management (cluster, sentinel, predis-vs-phpredis) to Laravel's `Illuminate\Contracts\Redis\Factory` — your `config/database.php` controls those.

## Rollback

If something breaks, pin the previous minor:
```bash
composer require admnio/sunset:0.2.*
```

No data migration to undo — Sunset's RedisQueue uses Laravel's same Redis schema.

---

# Upgrading from `admnio/sunset` v0.3.0 to v0.4.0

v0.4.0 moves the queueing-and-recording subsystem out of `laravel/horizon` and into the `Admnio\Sunset` namespace. The Redis keyspace renames from `horizon:*` to `sunset:*`. Horizon's dashboard keeps working via adapter classes — no changes needed in your routes/views.

## 1. Composer

```bash
composer update admnio/sunset
```

## 2. Republish the config

```bash
php artisan vendor:publish --tag=sunset-config --force
```

Or manually add the new keys to `config/sunset.php`:

```php
'key_prefix' => env('SUNSET_KEY_PREFIX', 'sunset'),

'trim' => [
    'recent' => 60,
    'pending' => 60,
    'completed' => 60,
    'recent_failed' => 10080,
    'failed' => 10080,
    'monitored' => 10080,
],
```

## 3. Migrate existing Redis keys

Run the migration command. Always dry-run first on production data:

```bash
php artisan sunset:migrate-horizon-keys --dry-run
php artisan sunset:migrate-horizon-keys
```

The command is idempotent — running it twice is a no-op.

## 4. Restart workers

```bash
php artisan horizon:terminate
```

Then restart your worker supervisor. New worker processes will dispatch Sunset events and record to `sunset:*` keys.

## 5. Verify

After workers come back up:
1. Dispatch a test job (e.g., `App\Jobs\TestJob::dispatch()`).
2. Confirm it appears at `/horizon/jobs/pending` (the dashboard reads through our adapters).
3. Inspect Redis: `redis-cli KEYS 'sunset:*'` should show your fresh records.
4. `redis-cli KEYS 'horizon:*'` should return empty (or only pre-migration leftovers if some keys were skipped).

## What changed under the hood

- New `Admnio\Sunset\Events\Job{Queueing,Queued,Reserved,Released,Completed,Failed}` event classes. Transports dispatch ours; `Laravel\Horizon\Events\*` no longer imported anywhere in our code.
- New `Admnio\Sunset\Contracts\{JobRepository,FailedJobRepository,TagRepository,MetricsRepository}` interfaces with focused, well-named methods (Horizon's mega-`JobRepository` is split into Job + FailedJob in our world).
- New `Admnio\Sunset\Repositories\Redis\Redis*Repository` implementations writing to `sunset:*` keys.
- New `Admnio\Sunset\Adapters\Horizon\Horizon*RepositoryAdapter` classes implement Horizon's contracts and delegate to ours, so the existing dashboard keeps reading our data.
- New `Admnio\Sunset\Listeners\*` (9 classes) record the lifecycle. `Laravel\Horizon\Listeners\*` no longer appears in the runtime listener chain for any lifecycle event.
- New `Admnio\Sunset\JobPayload` mirrors Horizon's `JobPayload` surface; transports use it. The `Laravel\Horizon\JobPayload` type is now only referenced inside `src/Adapters/Horizon/` at the dashboard boundary.

## Rollback

If something goes wrong:

```bash
composer require admnio/sunset:0.3.*
php artisan sunset:migrate-horizon-keys --from=sunset --to=horizon
```

The migration is reversible — same algorithm, swapped prefixes. Note: the v0.3.0 code does NOT write to `sunset:*` keys, so any data dispatched against v0.4.0 between the swap and rollback would be lost from the dashboard's view. Roll back during a low-traffic window or drain queues first.

---

# Upgrading from `admnio/sunset` v0.4.0 to v0.5.0

v0.5.0 moves the supervisor + master + worker process tree out of `laravel/horizon` and into the `Admnio\Sunset` namespace. `php artisan horizon` is **removed** (replaced with a removal notice). Users must migrate to `sunset:work`.

## 1. Composer

```bash
composer update admnio/sunset
```

## 2. Migrate your config

Run the migration command to copy `config/horizon.php`'s `environments` block (and `memory_limit`, `fast_termination`, `wait` settings) into `config/sunset.php`:

```bash
php artisan sunset:migrate-horizon-config --dry-run
php artisan sunset:migrate-horizon-config
```

If `config/sunset.php` already has a `supervisors` block (unlikely on first upgrade), use `--force` to overwrite.

## 3. Update your supervisor process

Change your systemd unit, Docker entrypoint, supervisord config, or whatever runs your queue workers:

```diff
- ExecStart=/usr/bin/php /var/www/app/artisan horizon
+ ExecStart=/usr/bin/php /var/www/app/artisan sunset:work
```

## 4. Update deploy scripts

If your deploy script calls `php artisan horizon:terminate` for zero-downtime deploys, change to `sunset:terminate`:

```diff
- php artisan horizon:terminate
+ php artisan sunset:terminate
```

Similar for any other `horizon:*` commands you use in scripts.

## 5. Restart workers

After the new code is deployed:

```bash
php artisan sunset:terminate  # If you previously had `horizon` running, this won't work. Use `kill` instead.
# Or for first start:
systemctl restart your-sunset-worker  # Or whatever your supervisor is
```

## 6. Verify

```bash
php artisan sunset:status         # Expect exit code 0 if running
php artisan sunset:supervisors    # Expect to see your configured supervisors
```

The Horizon dashboard at `/horizon` continues to work — the "Pause" button now routes through Sunset's adapter to your `sunset:work` process.

## What changed under the hood

- 8 new supervisor classes under `Admnio\Sunset\Supervisor\*` ported from `Laravel\Horizon\*`: `MasterSupervisor`, `Supervisor`, `SupervisorProcess`, `SupervisorOptions`, `SupervisorFactory`, `ProvisioningPlan`, `ProcessPool`, `ListensForSignals`.
- 6 new `Admnio\Sunset\SupervisorCommands\*` (Pause, ContinueWorking, Restart, Terminate, Scale, Balance).
- 1 new `Admnio\Sunset\MasterSupervisorCommands\AddSupervisor`.
- 4 new `Admnio\Sunset\Contracts\*` (Master/Supervisor/Process repos + CommandQueue).
- 4 new `Admnio\Sunset\Repositories\Redis\*` implementations.
- 4 new `Admnio\Sunset\Adapters\Horizon\*` adapter classes so the dashboard's pause/restart buttons still work.
- 19 new `Admnio\Sunset\Console\Sunset*Command` artisan commands.
- `php artisan horizon` is replaced with `SunsetHorizonRemovedCommand` (prints removal notice and exits 1).

## Rollback

If something goes wrong, pin the previous minor:

```bash
composer require admnio/sunset:0.4.*
```

Then revert your systemd unit + deploy scripts to use `horizon` again.

---

## From v0.5.0 to v0.6.0

v0.6.0 adds **RabbitMQ as a third first-class transport** alongside SQS (v0.2.0) and Redis (v0.3.0). No breaking changes for existing SQS or Redis consumers — this is purely additive.

### What's new

- `Admnio\Sunset\Transports\Rabbit\{RabbitTransport, RabbitQueue, RabbitConnector}` — a new transport with the same lifecycle event firing as SQS and Redis.
- `config/sunset.php` gains a `transports.rabbitmq` block with `workload_connection` and an opt-in `dead_letter` sub-block. The DLX scaffold is wired but full nack-on-drop routing lands in v0.7.0.
- `Queue::connection('rabbitmq')->later(60, $job)` is supported. Delays route through Sunset's `DelayedJobStore` (same store SQS uses since v0.2.0).

### Internal change worth knowing

The `DelayedJobStore` Redis ZSET member format changed from `queue|nonce|payload` to `queue|connection|nonce|payload` so reaped jobs return to their source transport. Old format entries continue to parse correctly during the upgrade window (they default to `connection='sqs'`, which matches pre-v0.6.0 reality since only SQS used the store). No consumer migration required.

### Migration steps

1. **Update composer:**

   ```bash
   composer require admnio/sunset:^0.6
   ```

   This pulls in `vladimir-yuldashev/laravel-queue-rabbitmq` transitively.

2. **Add a `rabbitmq` connection to `config/queue.php`:**

   ```php
   'connections' => [
       // ... existing connections ...
       'rabbitmq' => [
           'driver' => 'rabbitmq',
           'queue' => 'default',
           'connection' => 'default',
           'hosts' => [[
               'host' => env('RABBITMQ_HOST', '127.0.0.1'),
               'port' => (int) env('RABBITMQ_PORT', 5672),
               'user' => env('RABBITMQ_USER', 'guest'),
               'password' => env('RABBITMQ_PASSWORD', 'guest'),
               'vhost' => env('RABBITMQ_VHOST', '/'),
           ]],
           'options' => [
               'queue' => [
                   'exchange' => env('RABBITMQ_EXCHANGE', ''),
                   'exchange_type' => 'direct',
               ],
           ],
       ],
   ],
   ```

   **Pick one of two exchange strategies:**
   - **Empty exchange (`''`)** — Laravel-style, no binding setup required. Recommended for simple deployments.
   - **Named exchange (`'amq.direct'` etc.)** — production-style, requires you to declare queue-to-exchange bindings out-of-band:
     ```bash
     rabbitmqadmin declare binding source=amq.direct destination=<queue-name> routing_key=<queue-name>
     ```

3. **Switch a job to RabbitMQ:**

   ```php
   Queue::connection('rabbitmq')->push(new GeocodeAddress($address));
   ```

   Or set `QUEUE_CONNECTION=rabbitmq` and dispatch normally.

4. **Confirm the dashboard sees it:** the Horizon (or Sunset, post-v1.0.0) workload page should list the rabbitmq queue with a real depth count.

### Nothing else changes

SQS and Redis transports, supervisor commands, dashboard adapters, lifecycle events — all unchanged. UPGRADING from v0.5.0 is the simple "add the new transport if you want it" path.

---

## From v0.6.0 to v0.7.0

v0.7.0 adds **queue rate limiting** — fluent throttle + concurrency limits declared per queue or per job class, with multiple over-limit strategies. No breaking changes; rate limiting is opt-in and adds zero overhead when not used.

### What's new

- New facade: `Admnio\Sunset\Facades\Sunset` with `for(string $queueName)` and `limit(string $jobClass)` returning a `LimitBuilder`.
- New value objects: `Admnio\Sunset\RateLimiting\{Limit, ThrottleSpec, ConcurrencySpec, Decision, LimitBuilder, LimitRegistry}`.
- New Redis-backed limiter: `Admnio\Sunset\Contracts\Limiter` (interface) → `Admnio\Sunset\RateLimiting\RedisLimiter`.
- New gate that runs inside each transport's `pop()`: `Admnio\Sunset\RateLimiting\RateLimitGate`.
- New listener: `Admnio\Sunset\RateLimiting\Listeners\ReleaseConcurrencySlots` (registered on `JobProcessed`/`JobFailed`/`JobExceptionOccurred`).
- New scheduled command: `sunset:sweep-rate-limit-slots` (every minute) reconciles orphaned concurrency slots from killed workers.
- New event: `Admnio\Sunset\Events\JobRateLimited` fires on every rejection.
- New exception: `Admnio\Sunset\Exceptions\RateLimitExceededException` (used by the drop-as-failure strategy).
- New config block: `sunset.rate_limits` with `count_releases_by_default`, `fail_closed`, `sweep_interval_seconds`.

### Migration steps

1. **Update composer:**

   ```bash
   composer require admnio/sunset:^0.7
   ```

2. **(Optional) Publish the updated config:**

   The new `config/sunset.php` includes a `rate_limits` block with sensible defaults. If you want to publish it:

   ```bash
   php artisan vendor:publish --tag=sunset-config --force
   ```

   Re-apply any local edits afterwards. If you skip this step, Sunset falls back to the defaults baked into the package.

3. **Declare rate limits in any service provider:**

   ```php
   use Admnio\Sunset\Facades\Sunset;

   public function boot(): void
   {
       Sunset::for('geocode')->throttle(perMinute: 10)->concurrency(3);
   }
   ```

   No limits = no behavior change. The gate short-circuits when the registry is empty.

4. **Confirm the sweep is scheduled.** The service provider auto-schedules `sunset:sweep-rate-limit-slots` every minute. If you run a custom scheduler binary or override Sunset's scheduler hooks, verify the command is invoked.

### Internal Redis keyspace additions

v0.7.0 adds the following key patterns:

- `sunset:rl:t:<limit-name>:<bucket-key>` — sliding-window throttle sorted set (entries: timestamped reservation IDs).
- `sunset:rl:c:<limit-name>:<bucket-key>` — concurrency semaphore set (members: slot IDs).
- `sunset:rl:slot:<slot-id>` — TTL'd slot key (paired with each concurrency-set member; the sweep command reconciles when these expire without the corresponding set member being removed).
- `sunset:rl:reservations:<job-id>` — JSON-encoded list of held reservations per popped job (used by the release listener).
- `sunset:rl:rejects:<connection>:<queue>:<limit-name>` — rejection counter for dashboard observability (TTL = throttle window).

None of these keys collide with existing Sunset keys. All have TTLs and self-clean.

### Nothing else changes

Existing SQS, Redis, and RabbitMQ transports, supervisor commands, dashboard adapters, lifecycle events — all unchanged. The `pop()` path adds one method call (gate → admit → `isEmpty()` short-circuit) when no limits are registered.

### Rollback

If v0.7.0 doesn't work for you, pin v0.6.x:

```bash
composer require admnio/sunset:^0.6
```

No data migration is required. The new Redis keys (`sunset:rl:*`) self-expire and are unreferenced by older Sunset code.

---

## From v0.7.0 to v0.8.0

v0.8.0 ships the **Sunset SPA dashboard** at `/sunset` AND **drops `laravel/horizon`** from `composer.json`. Single release.

### Breaking changes

- `composer require admnio/sunset` no longer transitively installs `laravel/horizon`. If you depended on Horizon being available because Sunset required it, install it explicitly:
  ```bash
  composer require laravel/horizon
  ```
- `Admnio\Sunset\Adapters\Horizon\*` adapter classes are deleted. Consumer code that directly referenced them must move to Sunset's native contracts under `Admnio\Sunset\Contracts\*`.
- `Admnio\Sunset\JobPayload` no longer extends `Laravel\Horizon\JobPayload`. Public surface preserved (`prepare`, `id`, `value`, `decoded`, `tags`).
- `Admnio\Sunset\Repositories\SunsetWorkloadRepository` implements `Admnio\Sunset\Contracts\WorkloadRepository` (new) instead of Horizon's contract.

### Migration steps

1. **Update composer:**
   ```bash
   composer require admnio/sunset:^0.8
   ```

2. **Optional — drop Horizon if Sunset was the only reason you had it:**
   ```bash
   composer remove laravel/horizon
   ```

3. **Publish the dashboard:**
   ```bash
   php artisan sunset:install
   ```
   This publishes the compiled JS/CSS bundle to `public/vendor/sunset/` and the dashboard config to `config/sunset.php`.

4. **Register an auth gate** in any service provider:
   ```php
   use Admnio\Sunset\Facades\Sunset;

   public function boot(): void
   {
       Sunset::auth(fn ($request) => $request->user()?->isAdmin());
   }
   ```
   Without a custom gate, the dashboard is accessible only from localhost outside `local` env.

5. **Visit `/sunset`** in the browser.

### Optional — keep Horizon's dashboard alongside Sunset's

Both can coexist. After step 1 above:

```bash
composer require laravel/horizon
```

Both `/horizon` and `/sunset` will be reachable. They register independent bindings — no conflict. If you run both supervisors against the same queue, they will compete for jobs (your responsibility).

### Releases of the package — building the bundle

Sunset ships a pre-built JS/CSS bundle in `public-dist/`. **Maintainers must run `npm run build` before tagging a release.** The build output is committed so consumers don't need Node.js.

If you forked Sunset and want to rebuild the dashboard:

```bash
npm install
npm run build
# Then commit public-dist/app.{js,css} before tagging.
```

### What's new at a glance

- New facade method: `Sunset::auth(\Closure)`
- New contract: `Admnio\Sunset\Contracts\WorkloadRepository`
- New middleware: `Admnio\Sunset\Dashboard\Http\Middleware\Authorize`
- New artisan commands: none (the existing `sunset:install` was extended to publish the bundle)
- New config: `sunset.dashboard.path`, `sunset.dashboard.poll_interval_seconds`
- New routes: 20 (12 GET pages + 8 POST actions) under `/sunset`

---

## From v0.9.x to v1.0.0

v1.0.0 is the first stable release. The big-feature work (rate limits, dashboard, supervisor, transports) all landed in 0.x; v1.0 commits to backwards-compatible public API for the lifetime of v1.x.

### What's stable now

See the "Public API" section in README.md for the full list. In short: facade methods, contracts, events, exceptions, value objects, artisan command names, dashboard routes + their props shapes, and the published config keys.

### What's still internal

Everything marked `@internal` in PHPDoc. Most concrete classes under `Admnio\Sunset\Repositories\*`, `Supervisor\*`, `Dashboard\Http\*`, the rate-limit gate internals, and transport queue/connector implementations. Consumers should not extend these — depend on the Contracts interfaces instead.

### Improvements landing in v1.0.0

- **A11y (WCAG 2.1 AA):** Skip-to-content link, ARIA landmarks, focus-visible rings, dialog roles on the command palette, contrast-corrected status pills for light theme.
- **Perf:** Cached prefix detection in the rate-limit stats repository (one reflection-and-method-existence call per request instead of per poll tick).
- **Health page:** Live transport reachability probes (Redis ping, SQS list-queues, RabbitMQ TCP), runtime version display (PHP/Laravel/Sunset), Redis key prefix, registered rate-limit count, scheduled-command list.
- **`@internal` markers** on ~100 internal classes so static analysis surfaces incorrect consumer usage.

### Migration steps

For most consumers, **no action is required**. v1.0.0 is purely additive over v0.9.x.

If you previously relied on a class marked `@internal` in v1.0.0:
1. Find the corresponding public contract under `Admnio\Sunset\Contracts\*`.
2. Switch your `use` statement and type hint to the contract.
3. If your use case isn't covered by a public contract, file an issue — we may surface it as public API in v1.1.

### Release stability commitment

We will not make breaking changes to the public API listed in README's "Public API" section without bumping to v2.0.0. Patches (v1.0.x) ship bug fixes only. Minors (v1.1, v1.2, …) ship additive features and may deprecate (but not remove) APIs.

---

# Upgrading from `admnio/sunset` v1.0.x to v1.1.0

**No action required.** v1.1.0 is purely additive over v1.0.x.

## What you get for free

The `/sunset/supervisors` page now renders per-worker RSS and CPU% columns plus a click-to-toggle inline sparkline. Workers start reporting telemetry automatically the next time you restart them (the listener is wired in the service provider, no opt-in needed).

## New config block

A new `telemetry` block is published in `config/sunset.php` if you re-publish the config file. If you don't re-publish, the defaults apply (telemetry enabled, 5-second interval, 60 sparkline points).

```php
'telemetry' => [
    'enabled' => env('SUNSET_TELEMETRY_ENABLED', true),
    'interval_seconds' => (int) env('SUNSET_TELEMETRY_INTERVAL', 5),
    'series_points' => (int) env('SUNSET_TELEMETRY_SERIES_POINTS', 60),
],
```

## When to disable

Each worker writes ~3 Redis keys per `interval_seconds` (default 5s). For a fleet of N workers that's ~`0.6 * N` writes/second. For most deployments this is negligible. If your Redis is already pressured, set `SUNSET_TELEMETRY_ENABLED=false` to short-circuit the listener entirely.

## Windows note

`getrusage()` returns zeros for ru_utime/ru_stime on Windows. The listener detects this and writes `cpu_pct: null` after the second consecutive zero reading. RSS works everywhere. The dashboard renders `—` for unavailable CPU%.

## New public API surface

These are stable for the lifetime of v1.x:

- `Admnio\Sunset\Contracts\WorkerMetricsRepository` — interface with `all()`, `find(int $pid)`, `series(int $pid, string $kind, int $maxPoints = 60)`.
- `Admnio\Sunset\Telemetry\WorkerMetricsSnapshot` — readonly value object.

The Supervisors dashboard route gains two new top-level props (`worker_metrics`, `worker_metric_series`); existing prop shapes are unchanged.

## Scheduled command

`sunset:sweep-worker-metrics` is auto-registered with the scheduler at `everyMinute()->withoutOverlapping()`. Make sure `php artisan schedule:run` is wired in your cron (it already needs to be for the existing `sunset:sweep-rate-limit-slots` and long-delay sweep).

---

# Upgrading from `admnio/sunset` v1.1.x to v1.2.0

**No action required.** v1.2.0 is purely additive over v1.1.x.

## What you get for free

A new `/sunset/activity` dashboard page lists the most recent job failures, rate-limit rejections, worker restarts, and supervisor deployments. The recorder begins capturing events the moment Sunset boots; no opt-in needed. The page polls every 3 seconds (same `dashboard.poll_interval_seconds` setting every other dashboard page uses).

## New config block

If you re-publish the config (`php artisan vendor:publish --tag=sunset-config --force`), you'll see the new `activity` block:

```php
'activity' => [
    'enabled' => env('SUNSET_ACTIVITY_ENABLED', true),
    'stream_buffer_size' => (int) env('SUNSET_ACTIVITY_BUFFER', 5000),
],
```

Defaults work for most deployments. If you don't re-publish, the defaults still apply (the SP merges them in).

## New public API surface

These are stable for v1.x:

- `Admnio\Sunset\Contracts\ActivityRepository` — read interface (`recent`, `since`, `before`).
- `Admnio\Sunset\Activity\ActivityEvent` — readonly value object (`id`, `type`, `occurred_at`, `payload`).
- `Admnio\Sunset\Events\ActivityRecorded` — fires after each event lands in the buffer. Subscribe to forward to Slack / audit log / Datadog:

  ```php
  Event::listen(\Admnio\Sunset\Events\ActivityRecorded::class, function ($e) {
      if ($e->event->type === 'job_failed') {
          // forward $e->event->payload to your external system
      }
  });
  ```

## Buffer size considerations

`stream_buffer_size` defaults to 5000 events. At a steady ~10 events/second this is ~8 minutes of replay history — enough for most operators arriving at the dashboard to see what just happened. Bump it up to 50000 if you want longer replay (~83 minutes); the cost is more Redis memory (~150 bytes/event × 50000 = ~7.5 MB).

---

# Upgrading from `admnio/sunset` v1.2.0 to v1.2.1

If you installed v1.2.0 between commits and used the SSE stream, this section applies. Otherwise — skip; v1.2.1 is a no-op upgrade.

## What changed

- The Server-Sent Events endpoint `GET /sunset/activity/stream` was removed.
- Three config keys were removed: `sunset.activity.max_connection_seconds`, `sunset.activity.heartbeat_interval_seconds`, `sunset.activity.poll_interval_seconds`. Their env-var hooks (`SUNSET_ACTIVITY_MAX_CONNECTION`, `SUNSET_ACTIVITY_HEARTBEAT`, `SUNSET_ACTIVITY_POLL`) are now ignored.
- The Activity dashboard page polls the same `?refresh=1` route every other dashboard page polls. Cadence is governed by `dashboard.poll_interval_seconds` (default 3 seconds).
- The `stream_url` Inertia prop is replaced by `page_url` (for the "Load older" pagination URL). If you wrote a custom Vue page on top of the route, drop the `stream_url`/`EventSource` wiring and use the standard `usePolling()` composable.
- Internal class `Admnio\Sunset\Activity\ActivityStreamer` was deleted. Internal — no consumer should have referenced it.

## Why

Streaming added a per-tab worker-slot cost under Octane that wasn't worth the freshness benefit for an observability page already needing a recorder + buffer for replay. Polling at 3s is fast enough for the use case, removes the worker starvation, and keeps the page consistent with the rest of the dashboard.

## Migration

For most consumers: **no action required**. Sunset auto-discovers the new code; the page renders normally on the next request.

If you set any of the removed env vars in your deployment config, you can remove them — they're ignored.
