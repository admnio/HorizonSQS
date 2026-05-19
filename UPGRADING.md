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
