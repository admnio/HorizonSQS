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
