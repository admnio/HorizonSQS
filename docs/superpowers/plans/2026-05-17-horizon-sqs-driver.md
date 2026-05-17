# Horizon SQS Driver Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Build `masonworkforce/horizon-sqs`, a Composer package that makes Laravel Horizon's dashboard fully functional when the queue transport is Amazon SQS, by adding a custom SQS connector and overriding one Horizon repository while reusing all others unchanged.

**Architecture:** Approach A from spec. Custom `HorizonSqsQueue` extends `Illuminate\Queue\SqsQueue` and enriches payloads with `id`, `pushedAt`, `tags`. Laravel's worker events drive Horizon's existing Redis-backed repositories for all stats. Only `WorkloadRepository` is overridden to query SQS `GetQueueAttributes` for pending counts. Long delays (>15min) buffer in Redis and are swept by a scheduled command. Optional S3 spill-over handles >256KB payloads. FIFO queues supported via configurable group/dedup strategies.

**Tech Stack:** PHP 8.2+, Laravel 10/11/12, Horizon 5.x, AWS SDK PHP (`aws/aws-sdk-php`), PHPUnit 10/11, Orchestra Testbench for package testing, LocalStack (Docker) for integration tests.

**Reference spec:** `docs/superpowers/specs/2026-05-17-horizon-sqs-driver-design.md`

**Working directory:** `C:\WebDev\Packages\HorizonSQS` (Windows; PowerShell or Bash via Git Bash).

---

## File Structure

**Source files (`src/`):**
- `HorizonSqsServiceProvider.php` — wiring (config merge, queue extend, binding override, scheduled command)
- `Queue/HorizonSqsConnector.php` — `ConnectorInterface` returning `HorizonSqsQueue`
- `Queue/HorizonSqsQueue.php` — extends `SqsQueue`, overrides `createPayload`, `pushRaw`, `pop`
- `Queue/Payload/PayloadEnricher.php` — pure transformer adding id/pushedAt/tags/nonce
- `Queue/Payload/ExtendedPayloadHandler.php` — S3 spill-over for >256KB payloads
- `Queue/Delay/DelayedJobReenqueuer.php` — sweep delayed jobs from Redis sorted set into SQS
- `Queue/Delay/DelayedJobStore.php` — thin wrapper over Redis sorted set (helper for testability)
- `Repositories/SqsWorkloadRepository.php` — implements `Horizon\Contracts\WorkloadRepository`
- `Console/SweepDelayedCommand.php` — `horizon-sqs:sweep-delayed` artisan command
- `Exceptions/HorizonSqsPushException.php`
- `Exceptions/ExtendedPayloadException.php`
- `Exceptions/InvalidConfigurationException.php`
- `Support/FifoMessageAttributes.php` — derives `MessageGroupId` + `MessageDeduplicationId`

**Config:**
- `config/horizon-sqs.php` — package config

**Tests (`tests/`):**
- `Unit/Queue/Payload/PayloadEnricherTest.php`
- `Unit/Queue/Payload/ExtendedPayloadHandlerTest.php`
- `Unit/Queue/HorizonSqsQueueTest.php`
- `Unit/Queue/Delay/DelayedJobReenqueuerTest.php`
- `Unit/Repositories/SqsWorkloadRepositoryTest.php`
- `Unit/Support/FifoMessageAttributesTest.php`
- `Integration/PushPopProcessTest.php` — LocalStack
- `Integration/FifoOrderingTest.php` — LocalStack
- `Integration/ExtendedPayloadTest.php` — LocalStack
- `Integration/LongDelaySweepTest.php` — LocalStack
- `Integration/DashboardJsonTest.php` — Horizon dashboard JSON shape
- `TestCase.php` — base PHPUnit test case extending Orchestra Testbench
- `Fixtures/` — fake job classes

**Infrastructure:**
- `composer.json`
- `phpunit.xml`
- `docker-compose.yml` — LocalStack + Redis for integration tests
- `.github/workflows/tests.yml`
- `README.md`
- `.gitignore`

---

## Task 0: Scaffolding

**Files:**
- Create: `composer.json`
- Create: `phpunit.xml`
- Create: `.gitignore`
- Create: `tests/TestCase.php`

- [ ] **Step 0.1: Write `composer.json`**

```json
{
  "name": "masonworkforce/horizon-sqs",
  "description": "Laravel Horizon support for Amazon SQS with full dashboard parity.",
  "type": "library",
  "license": "MIT",
  "require": {
    "php": "^8.2",
    "aws/aws-sdk-php": "^3.300",
    "illuminate/queue": "^10.0 || ^11.0 || ^12.0",
    "illuminate/redis": "^10.0 || ^11.0 || ^12.0",
    "illuminate/support": "^10.0 || ^11.0 || ^12.0",
    "laravel/horizon": "^5.20",
    "ramsey/uuid": "^4.7"
  },
  "require-dev": {
    "orchestra/testbench": "^8.0 || ^9.0 || ^10.0",
    "phpunit/phpunit": "^10.5 || ^11.0",
    "mockery/mockery": "^1.6"
  },
  "autoload": {
    "psr-4": {
      "MasonWorkforce\\HorizonSqs\\": "src/"
    }
  },
  "autoload-dev": {
    "psr-4": {
      "MasonWorkforce\\HorizonSqs\\Tests\\": "tests/"
    }
  },
  "extra": {
    "laravel": {
      "providers": [
        "MasonWorkforce\\HorizonSqs\\HorizonSqsServiceProvider"
      ]
    }
  },
  "minimum-stability": "stable",
  "prefer-stable": true
}
```

- [ ] **Step 0.2: Write `phpunit.xml`**

```xml
<?xml version="1.0" encoding="UTF-8"?>
<phpunit xmlns:xsi="http://www.w3.org/2001/XMLSchema-instance"
         xsi:noNamespaceSchemaLocation="vendor/phpunit/phpunit/phpunit.xsd"
         bootstrap="vendor/autoload.php"
         colors="true"
         cacheDirectory=".phpunit.cache">
  <testsuites>
    <testsuite name="Unit">
      <directory>tests/Unit</directory>
    </testsuite>
    <testsuite name="Integration">
      <directory>tests/Integration</directory>
    </testsuite>
  </testsuites>
  <php>
    <env name="APP_ENV" value="testing"/>
    <env name="QUEUE_CONNECTION" value="sqs"/>
    <env name="AWS_ACCESS_KEY_ID" value="test"/>
    <env name="AWS_SECRET_ACCESS_KEY" value="test"/>
    <env name="AWS_DEFAULT_REGION" value="us-east-1"/>
    <env name="LOCALSTACK_ENDPOINT" value="http://localhost:4566"/>
    <env name="REDIS_HOST" value="127.0.0.1"/>
    <env name="REDIS_PORT" value="6379"/>
  </php>
</phpunit>
```

- [ ] **Step 0.3: Write `.gitignore`**

```
/vendor/
composer.lock
.phpunit.cache/
.phpunit.result.cache
/.idea/
/.vscode/
/build/
.DS_Store
```

- [ ] **Step 0.4: Write `tests/TestCase.php`**

```php
<?php

namespace MasonWorkforce\HorizonSqs\Tests;

use MasonWorkforce\HorizonSqs\HorizonSqsServiceProvider;
use Orchestra\Testbench\TestCase as Orchestra;

abstract class TestCase extends Orchestra
{
    protected function getPackageProviders($app): array
    {
        return [
            \Laravel\Horizon\HorizonServiceProvider::class,
            HorizonSqsServiceProvider::class,
        ];
    }

    protected function defineEnvironment($app): void
    {
        $app['config']->set('queue.default', 'sqs');
        $app['config']->set('queue.connections.sqs', [
            'driver' => 'sqs',
            'key' => 'test',
            'secret' => 'test',
            'prefix' => 'http://localhost:4566/000000000000',
            'queue' => 'default',
            'suffix' => '',
            'region' => 'us-east-1',
        ]);
        $app['config']->set('database.redis.default', [
            'host' => env('REDIS_HOST', '127.0.0.1'),
            'port' => env('REDIS_PORT', 6379),
            'database' => 1,
        ]);
        $app['config']->set('horizon-sqs.redis_connection', 'default');
    }
}
```

- [ ] **Step 0.5: Install dependencies**

Run: `composer install`
Expected: `vendor/` directory populated, no errors.

- [ ] **Step 0.6: Commit**

```bash
git add composer.json phpunit.xml .gitignore tests/TestCase.php
git commit -m "chore: scaffold composer package and test harness"
```

---

## Task 1: Config file

**Files:**
- Create: `config/horizon-sqs.php`

- [ ] **Step 1.1: Write the config file**

```php
<?php

return [
    /*
    | Redis connection (from config/database.php) used by Horizon's
    | repositories as the stats sidecar. Should match Horizon's own redis.
    */
    'redis_connection' => env('HORIZON_SQS_REDIS', 'default'),

    /*
    | Workload cache TTL in seconds for GetQueueAttributes results.
    | Prevents SQS API thrashing under dashboard polling load.
    */
    'workload_cache_ttl' => 5,

    /*
    | SQS native maximum delay for sendMessage in seconds.
    */
    'sqs_max_delay' => 900,

    /*
    | How often the delayed-job sweeper runs (seconds).
    */
    'long_delay_sweep_interval' => 60,

    /*
    | Reserved for v0.2. Flag accepted by config but not yet wired.
    | When implemented, an in-worker heartbeat will extend SQS visibility
    | while a job runs. Requires the pcntl extension on Linux.
    */
    'visibility_heartbeat' => false,

    'fifo' => [
        // 'queue-name' | 'job-class' | callable(array $payload, string $queue): string
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

- [ ] **Step 1.2: Commit**

```bash
git add config/horizon-sqs.php
git commit -m "feat: add package config file"
```

---

## Task 2: ServiceProvider skeleton

**Files:**
- Create: `src/HorizonSqsServiceProvider.php`
- Test: `tests/Unit/HorizonSqsServiceProviderTest.php`

- [ ] **Step 2.1: Write the failing test**

```php
<?php

namespace MasonWorkforce\HorizonSqs\Tests\Unit;

use MasonWorkforce\HorizonSqs\Tests\TestCase;

class HorizonSqsServiceProviderTest extends TestCase
{
    public function test_publishes_config(): void
    {
        $this->assertSame('default', config('horizon-sqs.redis_connection'));
        $this->assertSame(5, config('horizon-sqs.workload_cache_ttl'));
    }
}
```

- [ ] **Step 2.2: Run test to verify it fails**

Run: `vendor/bin/phpunit --filter HorizonSqsServiceProviderTest`
Expected: FAIL — `HorizonSqsServiceProvider` class not found.

- [ ] **Step 2.3: Write minimal provider**

```php
<?php

namespace MasonWorkforce\HorizonSqs;

use Illuminate\Support\ServiceProvider;

class HorizonSqsServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__ . '/../config/horizon-sqs.php', 'horizon-sqs');
    }

    public function boot(): void
    {
        if ($this->app->runningInConsole()) {
            $this->publishes([
                __DIR__ . '/../config/horizon-sqs.php' => config_path('horizon-sqs.php'),
            ], 'horizon-sqs-config');
        }
    }
}
```

- [ ] **Step 2.4: Run test to verify it passes**

Run: `vendor/bin/phpunit --filter HorizonSqsServiceProviderTest`
Expected: PASS.

- [ ] **Step 2.5: Commit**

```bash
git add src/HorizonSqsServiceProvider.php tests/Unit/HorizonSqsServiceProviderTest.php
git commit -m "feat: scaffold service provider with config merge"
```

---

## Task 3: PayloadEnricher

**Files:**
- Create: `src/Queue/Payload/PayloadEnricher.php`
- Test: `tests/Unit/Queue/Payload/PayloadEnricherTest.php`

- [ ] **Step 3.1: Write the failing test**

```php
<?php

namespace MasonWorkforce\HorizonSqs\Tests\Unit\Queue\Payload;

use MasonWorkforce\HorizonSqs\Queue\Payload\PayloadEnricher;
use MasonWorkforce\HorizonSqs\Tests\TestCase;

class PayloadEnricherTest extends TestCase
{
    public function test_adds_id_pushedAt_tags_and_nonce(): void
    {
        $enricher = new PayloadEnricher();
        $payload = ['displayName' => 'App\\Jobs\\SendEmail', 'job' => 'Illuminate\\Queue\\CallQueuedHandler@call'];

        $result = $enricher->enrich($payload, 'default');

        $this->assertArrayHasKey('id', $result);
        $this->assertMatchesRegularExpression(
            '/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/',
            $result['id']
        );
        $this->assertArrayHasKey('pushedAt', $result);
        $this->assertIsFloat($result['pushedAt']);
        $this->assertArrayHasKey('tags', $result);
        $this->assertIsArray($result['tags']);
        $this->assertArrayHasKey('_horizon_nonce', $result);
        $this->assertSame(16, strlen($result['_horizon_nonce']));
    }

    public function test_preserves_existing_payload_keys(): void
    {
        $enricher = new PayloadEnricher();
        $payload = ['displayName' => 'Foo', 'data' => ['command' => 'serialized']];

        $result = $enricher->enrich($payload, 'default');

        $this->assertSame('Foo', $result['displayName']);
        $this->assertSame(['command' => 'serialized'], $result['data']);
    }

    public function test_does_not_overwrite_existing_id(): void
    {
        $enricher = new PayloadEnricher();
        $payload = ['id' => 'preset-id', 'displayName' => 'Foo'];

        $result = $enricher->enrich($payload, 'default');

        $this->assertSame('preset-id', $result['id']);
    }

    public function test_merges_existing_tags_uniquely(): void
    {
        $enricher = new PayloadEnricher();
        $payload = ['tags' => ['a', 'b']];

        $result = $enricher->enrich($payload, 'default');

        $this->assertContains('a', $result['tags']);
        $this->assertContains('b', $result['tags']);
        $this->assertSame(array_unique($result['tags']), $result['tags']);
    }
}
```

- [ ] **Step 3.2: Run test to verify it fails**

Run: `vendor/bin/phpunit --filter PayloadEnricherTest`
Expected: FAIL — class not found.

- [ ] **Step 3.3: Implement PayloadEnricher**

```php
<?php

namespace MasonWorkforce\HorizonSqs\Queue\Payload;

use Ramsey\Uuid\Uuid;

class PayloadEnricher
{
    public function enrich(array $payload, string $queue): array
    {
        $payload['id'] = $payload['id'] ?? Uuid::uuid4()->toString();
        $payload['pushedAt'] = $payload['pushedAt'] ?? microtime(true);
        $payload['_horizon_nonce'] = $payload['_horizon_nonce'] ?? bin2hex(random_bytes(8));

        $existing = $payload['tags'] ?? [];
        $payload['tags'] = array_values(array_unique($existing));

        return $payload;
    }
}
```

- [ ] **Step 3.4: Run test to verify it passes**

Run: `vendor/bin/phpunit --filter PayloadEnricherTest`
Expected: PASS (4 tests).

- [ ] **Step 3.5: Commit**

```bash
git add src/Queue/Payload/PayloadEnricher.php tests/Unit/Queue/Payload/PayloadEnricherTest.php
git commit -m "feat: add payload enricher with id, pushedAt, tags, nonce"
```

---

## Task 4: FifoMessageAttributes helper

**Files:**
- Create: `src/Support/FifoMessageAttributes.php`
- Test: `tests/Unit/Support/FifoMessageAttributesTest.php`

- [ ] **Step 4.1: Write the failing test**

```php
<?php

namespace MasonWorkforce\HorizonSqs\Tests\Unit\Support;

use MasonWorkforce\HorizonSqs\Support\FifoMessageAttributes;
use MasonWorkforce\HorizonSqs\Tests\TestCase;

class FifoMessageAttributesTest extends TestCase
{
    public function test_returns_empty_for_non_fifo_queue(): void
    {
        $attrs = new FifoMessageAttributes(['message_group_id' => 'queue-name', 'content_based_dedup' => true]);

        $result = $attrs->for('default', '{"id":"abc"}', []);

        $this->assertSame([], $result);
    }

    public function test_uses_queue_name_strategy(): void
    {
        $attrs = new FifoMessageAttributes(['message_group_id' => 'queue-name', 'content_based_dedup' => true]);

        $result = $attrs->for('orders.fifo', '{"id":"abc"}', []);

        $this->assertSame('orders.fifo', $result['MessageGroupId']);
    }

    public function test_uses_job_class_strategy_from_payload(): void
    {
        $attrs = new FifoMessageAttributes(['message_group_id' => 'job-class', 'content_based_dedup' => true]);
        $payload = json_encode(['data' => ['commandName' => 'App\\Jobs\\SendEmail']]);

        $result = $attrs->for('orders.fifo', $payload, []);

        $this->assertSame('App\\Jobs\\SendEmail', $result['MessageGroupId']);
    }

    public function test_callable_strategy_invoked(): void
    {
        $attrs = new FifoMessageAttributes([
            'message_group_id' => fn (array $payload, string $queue) => 'custom-' . $queue,
            'content_based_dedup' => true,
        ]);

        $result = $attrs->for('orders.fifo', '{"id":"abc"}', []);

        $this->assertSame('custom-orders.fifo', $result['MessageGroupId']);
    }

    public function test_content_based_dedup_uses_payload_sha256(): void
    {
        $attrs = new FifoMessageAttributes(['message_group_id' => 'queue-name', 'content_based_dedup' => true]);
        $payload = '{"id":"abc"}';

        $result = $attrs->for('orders.fifo', $payload, []);

        $this->assertSame(hash('sha256', $payload), $result['MessageDeduplicationId']);
    }

    public function test_options_override_dedup_id(): void
    {
        $attrs = new FifoMessageAttributes(['message_group_id' => 'queue-name', 'content_based_dedup' => true]);

        $result = $attrs->for('orders.fifo', '{"id":"abc"}', ['MessageDeduplicationId' => 'explicit']);

        $this->assertSame('explicit', $result['MessageDeduplicationId']);
    }
}
```

- [ ] **Step 4.2: Run test to verify it fails**

Run: `vendor/bin/phpunit --filter FifoMessageAttributesTest`
Expected: FAIL — class not found.

- [ ] **Step 4.3: Implement FifoMessageAttributes**

```php
<?php

namespace MasonWorkforce\HorizonSqs\Support;

use InvalidArgumentException;

class FifoMessageAttributes
{
    public function __construct(private array $config)
    {
    }

    public function for(string $queue, string $payload, array $options): array
    {
        if (! str_ends_with($queue, '.fifo')) {
            return [];
        }

        $strategy = $this->config['message_group_id'] ?? 'queue-name';
        $decoded = json_decode($payload, true) ?: [];

        $groupId = match (true) {
            is_callable($strategy) => $strategy($decoded, $queue),
            $strategy === 'queue-name' => $queue,
            $strategy === 'job-class' => $decoded['data']['commandName'] ?? $queue,
            default => throw new InvalidArgumentException("Unknown FIFO group strategy: {$strategy}"),
        };

        $dedupId = $options['MessageDeduplicationId']
            ?? (($this->config['content_based_dedup'] ?? true) ? hash('sha256', $payload) : null);

        $result = ['MessageGroupId' => $groupId];
        if ($dedupId !== null) {
            $result['MessageDeduplicationId'] = $dedupId;
        }

        return $result;
    }
}
```

- [ ] **Step 4.4: Run test to verify it passes**

Run: `vendor/bin/phpunit --filter FifoMessageAttributesTest`
Expected: PASS (6 tests).

- [ ] **Step 4.5: Commit**

```bash
git add src/Support/FifoMessageAttributes.php tests/Unit/Support/FifoMessageAttributesTest.php
git commit -m "feat: add FIFO message group and dedup attribute resolver"
```

---

## Task 5: Custom exceptions

**Files:**
- Create: `src/Exceptions/HorizonSqsPushException.php`
- Create: `src/Exceptions/ExtendedPayloadException.php`
- Create: `src/Exceptions/InvalidConfigurationException.php`

- [ ] **Step 5.1: Write the exceptions**

`src/Exceptions/HorizonSqsPushException.php`:
```php
<?php

namespace MasonWorkforce\HorizonSqs\Exceptions;

use RuntimeException;

class HorizonSqsPushException extends RuntimeException
{
}
```

`src/Exceptions/ExtendedPayloadException.php`:
```php
<?php

namespace MasonWorkforce\HorizonSqs\Exceptions;

use RuntimeException;

class ExtendedPayloadException extends RuntimeException
{
}
```

`src/Exceptions/InvalidConfigurationException.php`:
```php
<?php

namespace MasonWorkforce\HorizonSqs\Exceptions;

use RuntimeException;

class InvalidConfigurationException extends RuntimeException
{
}
```

- [ ] **Step 5.2: Commit**

```bash
git add src/Exceptions/
git commit -m "feat: add custom exception types"
```

---

## Task 6: ExtendedPayloadHandler

**Files:**
- Create: `src/Queue/Payload/ExtendedPayloadHandler.php`
- Test: `tests/Unit/Queue/Payload/ExtendedPayloadHandlerTest.php`

- [ ] **Step 6.1: Write the failing test**

```php
<?php

namespace MasonWorkforce\HorizonSqs\Tests\Unit\Queue\Payload;

use Aws\Result;
use Aws\S3\S3Client;
use MasonWorkforce\HorizonSqs\Exceptions\ExtendedPayloadException;
use MasonWorkforce\HorizonSqs\Queue\Payload\ExtendedPayloadHandler;
use MasonWorkforce\HorizonSqs\Tests\TestCase;
use Mockery;

class ExtendedPayloadHandlerTest extends TestCase
{
    public function test_returns_payload_unchanged_when_under_threshold(): void
    {
        $s3 = Mockery::mock(S3Client::class);
        $handler = new ExtendedPayloadHandler($s3, 'my-bucket', 'horizon-sqs-payloads/');

        $payload = str_repeat('a', 200_000);

        $this->assertSame($payload, $handler->maybeStore($payload));
    }

    public function test_stores_payload_above_threshold_and_returns_pointer(): void
    {
        $s3 = Mockery::mock(S3Client::class);
        $s3->shouldReceive('putObject')->once()->andReturn(new Result());

        $handler = new ExtendedPayloadHandler($s3, 'my-bucket', 'horizon-sqs-payloads/');
        $payload = str_repeat('a', 300_000);

        $pointer = $handler->maybeStore($payload);

        $decoded = json_decode($pointer, true);
        $this->assertArrayHasKey('s3PointerKey', $decoded);
        $this->assertStringStartsWith('horizon-sqs-payloads/', $decoded['s3PointerKey']);
        $this->assertSame(300_000, $decoded['size']);
    }

    public function test_fetch_resolves_pointer_via_s3(): void
    {
        $s3 = Mockery::mock(S3Client::class);
        $s3->shouldReceive('getObject')
            ->once()
            ->with(Mockery::on(fn ($args) => $args['Key'] === 'horizon-sqs-payloads/abc'))
            ->andReturn(new Result(['Body' => 'real-payload']));

        $handler = new ExtendedPayloadHandler($s3, 'my-bucket', 'horizon-sqs-payloads/');
        $pointer = json_encode(['s3PointerKey' => 'horizon-sqs-payloads/abc', 'size' => 300_000]);

        $this->assertSame('real-payload', $handler->maybeFetch($pointer));
    }

    public function test_fetch_passes_through_non_pointer(): void
    {
        $s3 = Mockery::mock(S3Client::class);
        $handler = new ExtendedPayloadHandler($s3, 'my-bucket', 'horizon-sqs-payloads/');

        $this->assertSame('plain', $handler->maybeFetch('plain'));
        $this->assertSame('{"id":"abc"}', $handler->maybeFetch('{"id":"abc"}'));
    }

    public function test_store_failure_throws(): void
    {
        $s3 = Mockery::mock(S3Client::class);
        $s3->shouldReceive('putObject')->andThrow(new \Aws\S3\Exception\S3Exception('fail', Mockery::mock(\Aws\CommandInterface::class)));

        $handler = new ExtendedPayloadHandler($s3, 'my-bucket', 'horizon-sqs-payloads/');

        $this->expectException(ExtendedPayloadException::class);
        $handler->maybeStore(str_repeat('a', 300_000));
    }

    public function test_delete_pointer(): void
    {
        $s3 = Mockery::mock(S3Client::class);
        $s3->shouldReceive('deleteObject')
            ->once()
            ->with(Mockery::on(fn ($args) => $args['Key'] === 'horizon-sqs-payloads/abc'))
            ->andReturn(new Result());

        $handler = new ExtendedPayloadHandler($s3, 'my-bucket', 'horizon-sqs-payloads/');
        $pointer = json_encode(['s3PointerKey' => 'horizon-sqs-payloads/abc', 'size' => 300_000]);

        $handler->deleteIfPointer($pointer);
    }

    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }
}
```

- [ ] **Step 6.2: Run test to verify it fails**

Run: `vendor/bin/phpunit --filter ExtendedPayloadHandlerTest`
Expected: FAIL — class not found.

- [ ] **Step 6.3: Implement ExtendedPayloadHandler**

```php
<?php

namespace MasonWorkforce\HorizonSqs\Queue\Payload;

use Aws\S3\Exception\S3Exception;
use Aws\S3\S3Client;
use MasonWorkforce\HorizonSqs\Exceptions\ExtendedPayloadException;
use Ramsey\Uuid\Uuid;
use Throwable;

class ExtendedPayloadHandler
{
    private const SIZE_THRESHOLD = 256 * 1024;

    public function __construct(
        private S3Client $s3,
        private string $bucket,
        private string $prefix
    ) {
    }

    public function maybeStore(string $payload): string
    {
        if (strlen($payload) <= self::SIZE_THRESHOLD) {
            return $payload;
        }

        $key = $this->prefix . Uuid::uuid4()->toString();

        try {
            $this->s3->putObject([
                'Bucket' => $this->bucket,
                'Key' => $key,
                'Body' => $payload,
            ]);
        } catch (Throwable $e) {
            throw new ExtendedPayloadException('Failed to store extended payload in S3: ' . $e->getMessage(), 0, $e);
        }

        return json_encode([
            's3PointerKey' => $key,
            'size' => strlen($payload),
        ]);
    }

    public function maybeFetch(string $body): string
    {
        $decoded = json_decode($body, true);
        if (! is_array($decoded) || ! isset($decoded['s3PointerKey'])) {
            return $body;
        }

        try {
            $result = $this->s3->getObject([
                'Bucket' => $this->bucket,
                'Key' => $decoded['s3PointerKey'],
            ]);
        } catch (Throwable $e) {
            throw new ExtendedPayloadException('Failed to fetch extended payload from S3: ' . $e->getMessage(), 0, $e);
        }

        return (string) $result['Body'];
    }

    public function deleteIfPointer(string $body): void
    {
        $decoded = json_decode($body, true);
        if (! is_array($decoded) || ! isset($decoded['s3PointerKey'])) {
            return;
        }

        try {
            $this->s3->deleteObject([
                'Bucket' => $this->bucket,
                'Key' => $decoded['s3PointerKey'],
            ]);
        } catch (Throwable) {
            // best-effort; orphan handled by S3 lifecycle rule
        }
    }
}
```

- [ ] **Step 6.4: Run test to verify it passes**

Run: `vendor/bin/phpunit --filter ExtendedPayloadHandlerTest`
Expected: PASS (6 tests).

- [ ] **Step 6.5: Commit**

```bash
git add src/Queue/Payload/ExtendedPayloadHandler.php tests/Unit/Queue/Payload/ExtendedPayloadHandlerTest.php
git commit -m "feat: add S3 extended payload handler for >256KB messages"
```

---

## Task 7: DelayedJobStore

**Files:**
- Create: `src/Queue/Delay/DelayedJobStore.php`
- Test: `tests/Unit/Queue/Delay/DelayedJobStoreTest.php`

- [ ] **Step 7.1: Write the failing test**

```php
<?php

namespace MasonWorkforce\HorizonSqs\Tests\Unit\Queue\Delay;

use Illuminate\Contracts\Redis\Factory as RedisFactory;
use Illuminate\Redis\Connections\Connection as RedisConnection;
use MasonWorkforce\HorizonSqs\Queue\Delay\DelayedJobStore;
use MasonWorkforce\HorizonSqs\Tests\TestCase;
use Mockery;

class DelayedJobStoreTest extends TestCase
{
    public function test_buffer_adds_to_sorted_set(): void
    {
        $conn = Mockery::mock(RedisConnection::class);
        $conn->shouldReceive('zadd')
            ->once()
            ->with('horizon-sqs:delayed', 1_700_000_000, Mockery::pattern('/^orders\\|.*\\{"id":"abc"\\}$/'))
            ->andReturn(1);

        $factory = Mockery::mock(RedisFactory::class);
        $factory->shouldReceive('connection')->with('default')->andReturn($conn);

        $store = new DelayedJobStore($factory, 'default');
        $store->buffer('orders', '{"id":"abc"}', 1_700_000_000);
    }

    public function test_due_returns_entries_below_threshold(): void
    {
        $conn = Mockery::mock(RedisConnection::class);
        $conn->shouldReceive('zrangebyscore')
            ->once()
            ->with('horizon-sqs:delayed', '-inf', 1_700_000_060, ['withscores' => true])
            ->andReturn([
                'orders|nonce1|{"id":"a"}' => 1_700_000_010,
                'orders|nonce2|{"id":"b"}' => 1_700_000_050,
            ]);

        $factory = Mockery::mock(RedisFactory::class);
        $factory->shouldReceive('connection')->with('default')->andReturn($conn);

        $store = new DelayedJobStore($factory, 'default');
        $entries = $store->due(1_700_000_060);

        $this->assertCount(2, $entries);
        $this->assertSame('orders', $entries[0]['queue']);
        $this->assertSame('{"id":"a"}', $entries[0]['payload']);
        $this->assertSame(1_700_000_010.0, $entries[0]['eta']);
    }

    public function test_remove_zrems_by_member(): void
    {
        $conn = Mockery::mock(RedisConnection::class);
        $conn->shouldReceive('zrem')
            ->once()
            ->with('horizon-sqs:delayed', 'orders|nonce|{"id":"a"}')
            ->andReturn(1);

        $factory = Mockery::mock(RedisFactory::class);
        $factory->shouldReceive('connection')->with('default')->andReturn($conn);

        $store = new DelayedJobStore($factory, 'default');
        $store->remove('orders|nonce|{"id":"a"}');
    }

    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }
}
```

- [ ] **Step 7.2: Run test to verify it fails**

Run: `vendor/bin/phpunit --filter DelayedJobStoreTest`
Expected: FAIL — class not found.

- [ ] **Step 7.3: Implement DelayedJobStore**

```php
<?php

namespace MasonWorkforce\HorizonSqs\Queue\Delay;

use Illuminate\Contracts\Redis\Factory as RedisFactory;

class DelayedJobStore
{
    private const KEY = 'horizon-sqs:delayed';

    public function __construct(
        private RedisFactory $redis,
        private string $connectionName
    ) {
    }

    public function buffer(string $queue, string $payload, float $eta): void
    {
        $member = $queue . '|' . bin2hex(random_bytes(6)) . '|' . $payload;
        $this->connection()->zadd(self::KEY, (int) $eta, $member);
    }

    public function due(int $now): array
    {
        $raw = $this->connection()->zrangebyscore(self::KEY, '-inf', $now, ['withscores' => true]);

        $entries = [];
        foreach ($raw as $member => $score) {
            [$queue, , $payload] = explode('|', $member, 3);
            $entries[] = [
                'member' => $member,
                'queue' => $queue,
                'payload' => $payload,
                'eta' => (float) $score,
            ];
        }
        return $entries;
    }

    public function remove(string $member): void
    {
        $this->connection()->zrem(self::KEY, $member);
    }

    private function connection()
    {
        return $this->redis->connection($this->connectionName);
    }
}
```

- [ ] **Step 7.4: Run test to verify it passes**

Run: `vendor/bin/phpunit --filter DelayedJobStoreTest`
Expected: PASS (3 tests).

- [ ] **Step 7.5: Commit**

```bash
git add src/Queue/Delay/DelayedJobStore.php tests/Unit/Queue/Delay/DelayedJobStoreTest.php
git commit -m "feat: add DelayedJobStore wrapping Redis sorted set"
```

---

## Task 8: HorizonSqsQueue — createPayload

**Files:**
- Create: `src/Queue/HorizonSqsQueue.php`
- Test: `tests/Unit/Queue/HorizonSqsQueueTest.php`

- [ ] **Step 8.1: Write the failing test (createPayload)**

```php
<?php

namespace MasonWorkforce\HorizonSqs\Tests\Unit\Queue;

use Aws\Sqs\SqsClient;
use MasonWorkforce\HorizonSqs\Queue\Delay\DelayedJobStore;
use MasonWorkforce\HorizonSqs\Queue\HorizonSqsQueue;
use MasonWorkforce\HorizonSqs\Queue\Payload\ExtendedPayloadHandler;
use MasonWorkforce\HorizonSqs\Queue\Payload\PayloadEnricher;
use MasonWorkforce\HorizonSqs\Support\FifoMessageAttributes;
use MasonWorkforce\HorizonSqs\Tests\TestCase;
use Mockery;

class HorizonSqsQueueTest extends TestCase
{
    public function test_create_payload_adds_horizon_fields(): void
    {
        $queue = $this->makeQueue();

        $json = $queue->createPayload('Illuminate\\Queue\\CallQueuedHandler@call', 'default', new \stdClass());
        $decoded = json_decode($json, true);

        $this->assertArrayHasKey('id', $decoded);
        $this->assertArrayHasKey('pushedAt', $decoded);
        $this->assertArrayHasKey('tags', $decoded);
        $this->assertArrayHasKey('_horizon_nonce', $decoded);
    }

    private function makeQueue(): HorizonSqsQueue
    {
        return new HorizonSqsQueue(
            sqs: Mockery::mock(SqsClient::class),
            default: 'default',
            prefix: 'http://localhost:4566/000000000000',
            suffix: '',
            enricher: new PayloadEnricher(),
            fifoAttributes: new FifoMessageAttributes(['message_group_id' => 'queue-name', 'content_based_dedup' => true]),
            extendedPayload: null,
            delayedStore: Mockery::mock(DelayedJobStore::class),
            maxNativeDelay: 900,
            longPollSeconds: 20,
        );
    }

    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }
}
```

- [ ] **Step 8.2: Run test to verify it fails**

Run: `vendor/bin/phpunit --filter HorizonSqsQueueTest`
Expected: FAIL — class not found.

- [ ] **Step 8.3: Implement HorizonSqsQueue (skeleton + createPayload)**

```php
<?php

namespace MasonWorkforce\HorizonSqs\Queue;

use Aws\Sqs\SqsClient;
use Illuminate\Queue\SqsQueue;
use MasonWorkforce\HorizonSqs\Queue\Delay\DelayedJobStore;
use MasonWorkforce\HorizonSqs\Queue\Payload\ExtendedPayloadHandler;
use MasonWorkforce\HorizonSqs\Queue\Payload\PayloadEnricher;
use MasonWorkforce\HorizonSqs\Support\FifoMessageAttributes;

class HorizonSqsQueue extends SqsQueue
{
    public function __construct(
        SqsClient $sqs,
        string $default,
        string $prefix,
        string $suffix,
        private PayloadEnricher $enricher,
        private FifoMessageAttributes $fifoAttributes,
        private ?ExtendedPayloadHandler $extendedPayload,
        private DelayedJobStore $delayedStore,
        private int $maxNativeDelay = 900,
        private int $longPollSeconds = 20,
    ) {
        parent::__construct($sqs, $default, $prefix, $suffix);
    }

    protected function createPayloadArray($job, $queue, $data = '')
    {
        $payload = parent::createPayloadArray($job, $queue, $data);
        return $this->enricher->enrich($payload, $queue);
    }
}
```

- [ ] **Step 8.4: Run test to verify it passes**

Run: `vendor/bin/phpunit --filter HorizonSqsQueueTest`
Expected: PASS (1 test).

- [ ] **Step 8.5: Commit**

```bash
git add src/Queue/HorizonSqsQueue.php tests/Unit/Queue/HorizonSqsQueueTest.php
git commit -m "feat: HorizonSqsQueue with payload enrichment"
```

---

## Task 9: HorizonSqsQueue — pushRaw (FIFO + extended payload + delay routing)

**Files:**
- Modify: `src/Queue/HorizonSqsQueue.php`
- Modify: `tests/Unit/Queue/HorizonSqsQueueTest.php`

- [ ] **Step 9.1: Add failing tests for pushRaw branching**

Append to `tests/Unit/Queue/HorizonSqsQueueTest.php` inside the class:

```php
    public function test_push_raw_sends_to_sqs_for_short_delay(): void
    {
        $sqs = Mockery::mock(SqsClient::class);
        $sqs->shouldReceive('sendMessage')
            ->once()
            ->with(Mockery::on(function ($args) {
                return $args['QueueUrl'] === 'http://localhost:4566/000000000000/default'
                    && $args['MessageBody'] === '{"id":"abc"}'
                    && ! isset($args['DelaySeconds']);
            }))
            ->andReturn(new \Aws\Result(['MessageId' => 'mid-1']));

        $queue = $this->makeQueueWithSqs($sqs);

        $result = $queue->pushRaw('{"id":"abc"}', 'default');

        $this->assertSame('mid-1', $result);
    }

    public function test_push_raw_buffers_long_delay_in_redis(): void
    {
        $sqs = Mockery::mock(SqsClient::class);
        $sqs->shouldNotReceive('sendMessage');

        $store = Mockery::mock(DelayedJobStore::class);
        $store->shouldReceive('buffer')
            ->once()
            ->with(
                'default',
                Mockery::on(fn ($p) => is_string($p) && isset(json_decode($p, true)['id'])),
                Mockery::on(fn ($eta) => $eta > microtime(true) + 3500)
            );

        $queue = new HorizonSqsQueue(
            sqs: $sqs,
            default: 'default',
            prefix: 'http://localhost:4566/000000000000',
            suffix: '',
            enricher: new PayloadEnricher(),
            fifoAttributes: new FifoMessageAttributes(['message_group_id' => 'queue-name', 'content_based_dedup' => true]),
            extendedPayload: null,
            delayedStore: $store,
            maxNativeDelay: 900,
            longPollSeconds: 20,
        );

        $queue->later(3600, 'App\\Jobs\\Noop', '', 'default');
    }

    public function test_push_raw_includes_fifo_attributes_for_fifo_queue(): void
    {
        $sqs = Mockery::mock(SqsClient::class);
        $sqs->shouldReceive('sendMessage')
            ->once()
            ->with(Mockery::on(function ($args) {
                return $args['QueueUrl'] === 'http://localhost:4566/000000000000/orders.fifo'
                    && $args['MessageGroupId'] === 'orders.fifo'
                    && isset($args['MessageDeduplicationId']);
            }))
            ->andReturn(new \Aws\Result(['MessageId' => 'mid-2']));

        $queue = $this->makeQueueWithSqs($sqs);

        $queue->pushRaw('{"id":"abc"}', 'orders.fifo');
    }

    public function test_push_raw_spills_large_payload_to_s3(): void
    {
        $sqs = Mockery::mock(SqsClient::class);
        $sqs->shouldReceive('sendMessage')
            ->once()
            ->with(Mockery::on(function ($args) {
                $body = json_decode($args['MessageBody'], true);
                return isset($body['s3PointerKey']);
            }))
            ->andReturn(new \Aws\Result(['MessageId' => 'mid-3']));

        $extended = Mockery::mock(ExtendedPayloadHandler::class);
        $extended->shouldReceive('maybeStore')
            ->once()
            ->andReturn('{"s3PointerKey":"horizon-sqs-payloads/abc","size":300000}');

        $queue = new HorizonSqsQueue(
            sqs: $sqs,
            default: 'default',
            prefix: 'http://localhost:4566/000000000000',
            suffix: '',
            enricher: new PayloadEnricher(),
            fifoAttributes: new FifoMessageAttributes(['message_group_id' => 'queue-name', 'content_based_dedup' => true]),
            extendedPayload: $extended,
            delayedStore: Mockery::mock(DelayedJobStore::class),
            maxNativeDelay: 900,
            longPollSeconds: 20,
        );

        $queue->pushRaw(str_repeat('a', 300_000), 'default');
    }

    private function makeQueueWithSqs(SqsClient $sqs): HorizonSqsQueue
    {
        return new HorizonSqsQueue(
            sqs: $sqs,
            default: 'default',
            prefix: 'http://localhost:4566/000000000000',
            suffix: '',
            enricher: new PayloadEnricher(),
            fifoAttributes: new FifoMessageAttributes(['message_group_id' => 'queue-name', 'content_based_dedup' => true]),
            extendedPayload: null,
            delayedStore: Mockery::mock(DelayedJobStore::class),
            maxNativeDelay: 900,
            longPollSeconds: 20,
        );
    }
```

- [ ] **Step 9.2: Run tests to verify they fail**

Run: `vendor/bin/phpunit --filter HorizonSqsQueueTest`
Expected: FAIL (4 new tests).

- [ ] **Step 9.3: Implement pushRaw and later overrides**

Add to `src/Queue/HorizonSqsQueue.php`:

```php
    public function pushRaw($payload, $queue = null, array $options = [])
    {
        $resolvedQueue = $this->getQueue($queue);

        if ($this->extendedPayload) {
            $payload = $this->extendedPayload->maybeStore($payload);
        }

        $args = [
            'QueueUrl' => $this->prefix . '/' . $resolvedQueue,
            'MessageBody' => $payload,
        ];

        if (isset($options['delay']) && $options['delay'] > 0) {
            $args['DelaySeconds'] = (int) $options['delay'];
        }

        $args = array_merge($args, $this->fifoAttributes->for($resolvedQueue, $payload, $options));

        $response = $this->sqs->sendMessage($args);
        return $response->get('MessageId');
    }

    public function later($delay, $job, $data = '', $queue = null)
    {
        $payload = $this->createPayload($job, $this->getQueue($queue), $data);
        $delaySeconds = $this->secondsUntil($delay);

        if ($delaySeconds > $this->maxNativeDelay) {
            $this->delayedStore->buffer(
                $this->getQueue($queue),
                $payload,
                microtime(true) + $delaySeconds
            );
            $decoded = json_decode($payload, true);
            return $decoded['id'] ?? null;
        }

        return $this->pushRaw($payload, $queue, ['delay' => $delaySeconds]);
    }
```

Note: `getQueue` is inherited from `SqsQueue` and returns the queue name without the URL prefix.

- [ ] **Step 9.4: Run tests to verify they pass**

Run: `vendor/bin/phpunit --filter HorizonSqsQueueTest`
Expected: PASS (5 tests total).

- [ ] **Step 9.5: Commit**

```bash
git add src/Queue/HorizonSqsQueue.php tests/Unit/Queue/HorizonSqsQueueTest.php
git commit -m "feat: pushRaw with FIFO, extended payload, and long-delay buffering"
```

---

## Task 10: HorizonSqsQueue — pop with extended payload fetch

**Files:**
- Modify: `src/Queue/HorizonSqsQueue.php`
- Modify: `tests/Unit/Queue/HorizonSqsQueueTest.php`

- [ ] **Step 10.1: Add failing test for pop unwrapping extended payload**

Append to test class:

```php
    public function test_pop_unwraps_extended_payload(): void
    {
        $sqs = Mockery::mock(SqsClient::class);
        $sqs->shouldReceive('receiveMessage')
            ->once()
            ->andReturn(new \Aws\Result([
                'Messages' => [[
                    'MessageId' => 'mid-1',
                    'ReceiptHandle' => 'rh-1',
                    'Body' => '{"s3PointerKey":"horizon-sqs-payloads/abc","size":300000}',
                    'Attributes' => ['ApproximateReceiveCount' => 1],
                ]],
            ]));

        $extended = Mockery::mock(ExtendedPayloadHandler::class);
        $extended->shouldReceive('maybeFetch')
            ->once()
            ->andReturn('{"id":"abc","tags":[]}');

        $queue = new HorizonSqsQueue(
            sqs: $sqs,
            default: 'default',
            prefix: 'http://localhost:4566/000000000000',
            suffix: '',
            enricher: new PayloadEnricher(),
            fifoAttributes: new FifoMessageAttributes(['message_group_id' => 'queue-name', 'content_based_dedup' => true]),
            extendedPayload: $extended,
            delayedStore: Mockery::mock(DelayedJobStore::class),
            maxNativeDelay: 900,
            longPollSeconds: 20,
        );
        $queue->setContainer($this->app);

        $job = $queue->pop('default');

        $this->assertNotNull($job);
        $this->assertSame('{"id":"abc","tags":[]}', $job->getRawBody());
    }
```

- [ ] **Step 10.2: Run test to verify it fails**

Run: `vendor/bin/phpunit --filter test_pop_unwraps_extended_payload`
Expected: FAIL.

- [ ] **Step 10.3: Implement pop override**

Add to `src/Queue/HorizonSqsQueue.php`:

```php
    public function pop($queue = null)
    {
        $response = $this->sqs->receiveMessage([
            'QueueUrl' => $queueUrl = $this->prefix . '/' . $this->getQueue($queue),
            'AttributeNames' => ['ApproximateReceiveCount'],
            'WaitTimeSeconds' => $this->longPollSeconds,
        ]);

        if (! is_array($response['Messages']) || count($response['Messages']) === 0) {
            return null;
        }

        $message = $response['Messages'][0];

        if ($this->extendedPayload) {
            $message['Body'] = $this->extendedPayload->maybeFetch($message['Body']);
        }

        return new \Illuminate\Queue\Jobs\SqsJob(
            $this->container,
            $this->sqs,
            $message,
            $this->connectionName,
            $queueUrl
        );
    }
```

- [ ] **Step 10.4: Run tests**

Run: `vendor/bin/phpunit --filter HorizonSqsQueueTest`
Expected: PASS (6 tests).

- [ ] **Step 10.5: Commit**

```bash
git add src/Queue/HorizonSqsQueue.php tests/Unit/Queue/HorizonSqsQueueTest.php
git commit -m "feat: pop with extended payload fetch"
```

---

## Task 11: HorizonSqsConnector

**Files:**
- Create: `src/Queue/HorizonSqsConnector.php`
- Test: `tests/Unit/Queue/HorizonSqsConnectorTest.php`

- [ ] **Step 11.1: Write the failing test**

```php
<?php

namespace MasonWorkforce\HorizonSqs\Tests\Unit\Queue;

use MasonWorkforce\HorizonSqs\Queue\HorizonSqsConnector;
use MasonWorkforce\HorizonSqs\Queue\HorizonSqsQueue;
use MasonWorkforce\HorizonSqs\Tests\TestCase;

class HorizonSqsConnectorTest extends TestCase
{
    public function test_connect_returns_horizon_sqs_queue(): void
    {
        $connector = $this->app->make(HorizonSqsConnector::class);

        $queue = $connector->connect([
            'key' => 'test',
            'secret' => 'test',
            'region' => 'us-east-1',
            'prefix' => 'http://localhost:4566/000000000000',
            'queue' => 'default',
            'suffix' => '',
        ]);

        $this->assertInstanceOf(HorizonSqsQueue::class, $queue);
    }
}
```

- [ ] **Step 11.2: Run test to verify it fails**

Run: `vendor/bin/phpunit --filter HorizonSqsConnectorTest`
Expected: FAIL — class not found.

- [ ] **Step 11.3: Implement HorizonSqsConnector**

```php
<?php

namespace MasonWorkforce\HorizonSqs\Queue;

use Aws\S3\S3Client;
use Aws\Sqs\SqsClient;
use Illuminate\Contracts\Container\Container;
use Illuminate\Contracts\Redis\Factory as RedisFactory;
use Illuminate\Queue\Connectors\ConnectorInterface;
use MasonWorkforce\HorizonSqs\Queue\Delay\DelayedJobStore;
use MasonWorkforce\HorizonSqs\Queue\Payload\ExtendedPayloadHandler;
use MasonWorkforce\HorizonSqs\Queue\Payload\PayloadEnricher;
use MasonWorkforce\HorizonSqs\Support\FifoMessageAttributes;

class HorizonSqsConnector implements ConnectorInterface
{
    public function __construct(
        private Container $container,
        private PayloadEnricher $enricher,
        private RedisFactory $redis,
        private array $packageConfig,
    ) {
    }

    public function connect(array $config)
    {
        $sqs = new SqsClient($this->normalizeSqsConfig($config));

        $extended = null;
        if ($this->packageConfig['extended_payload']['enabled'] ?? false) {
            $s3 = new S3Client($this->normalizeSqsConfig($config));
            $extended = new ExtendedPayloadHandler(
                $s3,
                $this->packageConfig['extended_payload']['bucket'],
                $this->packageConfig['extended_payload']['prefix']
            );
        }

        return new HorizonSqsQueue(
            sqs: $sqs,
            default: $config['queue'],
            prefix: $config['prefix'] ?? '',
            suffix: $config['suffix'] ?? '',
            enricher: $this->enricher,
            fifoAttributes: new FifoMessageAttributes($this->packageConfig['fifo']),
            extendedPayload: $extended,
            delayedStore: new DelayedJobStore($this->redis, $this->packageConfig['redis_connection']),
            maxNativeDelay: (int) ($this->packageConfig['sqs_max_delay'] ?? 900),
            longPollSeconds: 20,
        );
    }

    private function normalizeSqsConfig(array $config): array
    {
        $base = [
            'region' => $config['region'] ?? 'us-east-1',
            'version' => 'latest',
        ];

        if (! empty($config['key']) && ! empty($config['secret'])) {
            $base['credentials'] = [
                'key' => $config['key'],
                'secret' => $config['secret'],
                'token' => $config['token'] ?? null,
            ];
        }

        if (! empty($config['endpoint'])) {
            $base['endpoint'] = $config['endpoint'];
        }

        return $base;
    }
}
```

- [ ] **Step 11.4: Run test to verify it passes**

Run: `vendor/bin/phpunit --filter HorizonSqsConnectorTest`
Expected: PASS.

- [ ] **Step 11.5: Commit**

```bash
git add src/Queue/HorizonSqsConnector.php tests/Unit/Queue/HorizonSqsConnectorTest.php
git commit -m "feat: HorizonSqsConnector wires queue with deps"
```

---

## Task 12: SqsWorkloadRepository

**Files:**
- Create: `src/Repositories/SqsWorkloadRepository.php`
- Test: `tests/Unit/Repositories/SqsWorkloadRepositoryTest.php`

- [ ] **Step 12.1: Write the failing test**

```php
<?php

namespace MasonWorkforce\HorizonSqs\Tests\Unit\Repositories;

use Aws\Result;
use Aws\Sqs\SqsClient;
use Illuminate\Contracts\Cache\Repository as Cache;
use Laravel\Horizon\Contracts\MetricsRepository;
use Laravel\Horizon\Contracts\ProcessRepository;
use MasonWorkforce\HorizonSqs\Repositories\SqsWorkloadRepository;
use MasonWorkforce\HorizonSqs\Tests\TestCase;
use Mockery;

class SqsWorkloadRepositoryTest extends TestCase
{
    public function test_returns_queues_with_length_and_wait(): void
    {
        $sqs = Mockery::mock(SqsClient::class);
        $sqs->shouldReceive('getQueueAttributes')
            ->andReturnUsing(function ($args) {
                $queue = basename($args['QueueUrl']);
                return new Result([
                    'Attributes' => [
                        'ApproximateNumberOfMessages' => $queue === 'orders' ? '40' : '10',
                        'ApproximateNumberOfMessagesNotVisible' => '0',
                    ],
                ]);
            });

        $metrics = Mockery::mock(MetricsRepository::class);
        $metrics->shouldReceive('runtimeForQueue')->with('orders')->andReturn(2.0);
        $metrics->shouldReceive('runtimeForQueue')->with('default')->andReturn(1.0);

        $processes = Mockery::mock(ProcessRepository::class);
        $processes->shouldReceive('processesPerQueue')->andReturn(['orders' => 4, 'default' => 2]);

        $cache = Mockery::mock(Cache::class);
        $cache->shouldReceive('remember')->andReturnUsing(fn ($key, $ttl, $cb) => $cb());

        $repo = new SqsWorkloadRepository(
            $sqs,
            $metrics,
            $processes,
            $cache,
            'http://localhost:4566/000000000000',
            ['orders', 'default'],
            5
        );

        $workload = $repo->get();

        $byName = collect($workload)->keyBy('name')->all();

        $this->assertSame(40, $byName['orders']['length']);
        $this->assertSame(20, $byName['orders']['wait']); // 40 * 2.0 / 4 = 20
        $this->assertSame(10, $byName['default']['length']);
        $this->assertSame(5, $byName['default']['wait']);  // 10 * 1.0 / 2 = 5
    }

    public function test_handles_zero_processes(): void
    {
        $sqs = Mockery::mock(SqsClient::class);
        $sqs->shouldReceive('getQueueAttributes')->andReturn(new Result([
            'Attributes' => ['ApproximateNumberOfMessages' => '5', 'ApproximateNumberOfMessagesNotVisible' => '0'],
        ]));

        $metrics = Mockery::mock(MetricsRepository::class);
        $metrics->shouldReceive('runtimeForQueue')->andReturn(1.0);

        $processes = Mockery::mock(ProcessRepository::class);
        $processes->shouldReceive('processesPerQueue')->andReturn(['default' => 0]);

        $cache = Mockery::mock(Cache::class);
        $cache->shouldReceive('remember')->andReturnUsing(fn ($key, $ttl, $cb) => $cb());

        $repo = new SqsWorkloadRepository($sqs, $metrics, $processes, $cache, 'http://localhost:4566/000000000000', ['default'], 5);

        $workload = $repo->get();

        $this->assertSame(5, $workload[0]['wait']); // divides by max(1, processes)
    }

    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }
}
```

- [ ] **Step 12.2: Run test to verify it fails**

Run: `vendor/bin/phpunit --filter SqsWorkloadRepositoryTest`
Expected: FAIL — class not found.

- [ ] **Step 12.3: Implement SqsWorkloadRepository**

```php
<?php

namespace MasonWorkforce\HorizonSqs\Repositories;

use Aws\Sqs\SqsClient;
use Illuminate\Contracts\Cache\Repository as Cache;
use Laravel\Horizon\Contracts\MetricsRepository;
use Laravel\Horizon\Contracts\ProcessRepository;
use Laravel\Horizon\Contracts\WorkloadRepository;

class SqsWorkloadRepository implements WorkloadRepository
{
    public function __construct(
        private SqsClient $sqs,
        private MetricsRepository $metrics,
        private ProcessRepository $processes,
        private Cache $cache,
        private string $queuePrefix,
        private array $queues,
        private int $cacheTtlSeconds,
    ) {
    }

    public function get(): array
    {
        return $this->cache->remember(
            'horizon-sqs:workload',
            $this->cacheTtlSeconds,
            fn () => $this->fetch()
        );
    }

    private function fetch(): array
    {
        $perQueueProcesses = $this->processes->processesPerQueue();

        $promises = [];
        foreach ($this->queues as $queue) {
            $promises[$queue] = $this->sqs->getQueueAttributesAsync([
                'QueueUrl' => $this->queuePrefix . '/' . $queue,
                'AttributeNames' => ['ApproximateNumberOfMessages', 'ApproximateNumberOfMessagesNotVisible'],
            ]);
        }

        $workload = [];
        foreach ($promises as $queue => $promise) {
            $result = $promise->wait();
            $attrs = $result['Attributes'] ?? [];
            $length = (int) ($attrs['ApproximateNumberOfMessages'] ?? 0);
            $runtime = (float) $this->metrics->runtimeForQueue($queue);
            $procs = max(1, (int) ($perQueueProcesses[$queue] ?? 0));

            $workload[] = [
                'name' => $queue,
                'length' => $length,
                'wait' => (int) round($length * $runtime / $procs),
                'processes' => $procs,
                'split' => null,
            ];
        }

        return $workload;
    }
}
```

- [ ] **Step 12.4: Run test to verify it passes**

Run: `vendor/bin/phpunit --filter SqsWorkloadRepositoryTest`
Expected: PASS (2 tests).

- [ ] **Step 12.5: Commit**

```bash
git add src/Repositories/SqsWorkloadRepository.php tests/Unit/Repositories/SqsWorkloadRepositoryTest.php
git commit -m "feat: SqsWorkloadRepository for Horizon dashboard workload page"
```

---

## Task 13: DelayedJobReenqueuer

**Files:**
- Create: `src/Queue/Delay/DelayedJobReenqueuer.php`
- Test: `tests/Unit/Queue/Delay/DelayedJobReenqueuerTest.php`

- [ ] **Step 13.1: Write the failing test**

```php
<?php

namespace MasonWorkforce\HorizonSqs\Tests\Unit\Queue\Delay;

use Illuminate\Contracts\Queue\Factory as QueueFactory;
use Illuminate\Queue\Queue;
use MasonWorkforce\HorizonSqs\Queue\Delay\DelayedJobReenqueuer;
use MasonWorkforce\HorizonSqs\Queue\Delay\DelayedJobStore;
use MasonWorkforce\HorizonSqs\Tests\TestCase;
use Mockery;

class DelayedJobReenqueuerTest extends TestCase
{
    public function test_sweeps_due_jobs_to_sqs(): void
    {
        $now = 1_700_000_100;

        $store = Mockery::mock(DelayedJobStore::class);
        $store->shouldReceive('due')->with($now + 60)->andReturn([
            ['member' => 'orders|n1|{"id":"a"}', 'queue' => 'orders', 'payload' => '{"id":"a"}', 'eta' => $now + 10.0],
            ['member' => 'default|n2|{"id":"b"}', 'queue' => 'default', 'payload' => '{"id":"b"}', 'eta' => $now + 50.0],
        ]);
        $store->shouldReceive('remove')->with('orders|n1|{"id":"a"}')->once();
        $store->shouldReceive('remove')->with('default|n2|{"id":"b"}')->once();

        $sqsQueue = Mockery::mock(Queue::class);
        $sqsQueue->shouldReceive('pushRaw')
            ->with('{"id":"a"}', 'orders', Mockery::on(fn ($opts) => $opts['delay'] === 10))
            ->once();
        $sqsQueue->shouldReceive('pushRaw')
            ->with('{"id":"b"}', 'default', Mockery::on(fn ($opts) => $opts['delay'] === 50))
            ->once();

        $queues = Mockery::mock(QueueFactory::class);
        $queues->shouldReceive('connection')->with('sqs')->andReturn($sqsQueue);

        $reenqueuer = new DelayedJobReenqueuer($store, $queues, 'sqs', 60);
        $reenqueuer->sweep($now);
    }

    public function test_partial_failure_leaves_entry(): void
    {
        $now = 1_700_000_100;

        $store = Mockery::mock(DelayedJobStore::class);
        $store->shouldReceive('due')->andReturn([
            ['member' => 'orders|n1|{"id":"a"}', 'queue' => 'orders', 'payload' => '{"id":"a"}', 'eta' => $now + 10.0],
            ['member' => 'orders|n2|{"id":"b"}', 'queue' => 'orders', 'payload' => '{"id":"b"}', 'eta' => $now + 20.0],
        ]);
        $store->shouldReceive('remove')->with('orders|n1|{"id":"a"}')->once();
        $store->shouldNotReceive('remove')->with('orders|n2|{"id":"b"}');

        $sqsQueue = Mockery::mock(Queue::class);
        $sqsQueue->shouldReceive('pushRaw')->with('{"id":"a"}', 'orders', Mockery::any())->once();
        $sqsQueue->shouldReceive('pushRaw')->with('{"id":"b"}', 'orders', Mockery::any())
            ->andThrow(new \RuntimeException('sqs failed'));

        $queues = Mockery::mock(QueueFactory::class);
        $queues->shouldReceive('connection')->andReturn($sqsQueue);

        $reenqueuer = new DelayedJobReenqueuer($store, $queues, 'sqs', 60);
        $reenqueuer->sweep($now);
    }

    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }
}
```

- [ ] **Step 13.2: Run test to verify it fails**

Run: `vendor/bin/phpunit --filter DelayedJobReenqueuerTest`
Expected: FAIL — class not found.

- [ ] **Step 13.3: Implement DelayedJobReenqueuer**

```php
<?php

namespace MasonWorkforce\HorizonSqs\Queue\Delay;

use Illuminate\Contracts\Queue\Factory as QueueFactory;
use Throwable;

class DelayedJobReenqueuer
{
    public function __construct(
        private DelayedJobStore $store,
        private QueueFactory $queues,
        private string $connectionName,
        private int $sweepIntervalSeconds,
    ) {
    }

    public function sweep(?int $now = null): void
    {
        $now = $now ?? time();
        $entries = $this->store->due($now + $this->sweepIntervalSeconds);

        $queue = $this->queues->connection($this->connectionName);

        foreach ($entries as $entry) {
            try {
                $delay = max(0, (int) round($entry['eta'] - $now));
                $queue->pushRaw($entry['payload'], $entry['queue'], ['delay' => $delay]);
                $this->store->remove($entry['member']);
            } catch (Throwable) {
                // leave in set for next sweep
            }
        }
    }
}
```

- [ ] **Step 13.4: Run test to verify it passes**

Run: `vendor/bin/phpunit --filter DelayedJobReenqueuerTest`
Expected: PASS (2 tests).

- [ ] **Step 13.5: Commit**

```bash
git add src/Queue/Delay/DelayedJobReenqueuer.php tests/Unit/Queue/Delay/DelayedJobReenqueuerTest.php
git commit -m "feat: DelayedJobReenqueuer with partial-failure resilience"
```

---

## Task 14: SweepDelayedCommand

**Files:**
- Create: `src/Console/SweepDelayedCommand.php`
- Test: `tests/Unit/Console/SweepDelayedCommandTest.php`

- [ ] **Step 14.1: Write the failing test**

```php
<?php

namespace MasonWorkforce\HorizonSqs\Tests\Unit\Console;

use MasonWorkforce\HorizonSqs\Queue\Delay\DelayedJobReenqueuer;
use MasonWorkforce\HorizonSqs\Tests\TestCase;
use Mockery;

class SweepDelayedCommandTest extends TestCase
{
    public function test_invokes_reenqueuer(): void
    {
        $reenqueuer = Mockery::mock(DelayedJobReenqueuer::class);
        $reenqueuer->shouldReceive('sweep')->once();
        $this->app->instance(DelayedJobReenqueuer::class, $reenqueuer);

        $this->artisan('horizon-sqs:sweep-delayed')->assertSuccessful();
    }

    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }
}
```

- [ ] **Step 14.2: Run test to verify it fails**

Run: `vendor/bin/phpunit --filter SweepDelayedCommandTest`
Expected: FAIL — command not registered.

- [ ] **Step 14.3: Implement SweepDelayedCommand**

```php
<?php

namespace MasonWorkforce\HorizonSqs\Console;

use Illuminate\Console\Command;
use MasonWorkforce\HorizonSqs\Queue\Delay\DelayedJobReenqueuer;

class SweepDelayedCommand extends Command
{
    protected $signature = 'horizon-sqs:sweep-delayed';

    protected $description = 'Push long-delayed jobs whose ETA falls within the next sweep interval back to SQS.';

    public function handle(DelayedJobReenqueuer $reenqueuer): int
    {
        $reenqueuer->sweep();
        return self::SUCCESS;
    }
}
```

(Command registration in ServiceProvider in Task 15.)

- [ ] **Step 14.4: Commit (test still failing — wired in next task)**

```bash
git add src/Console/SweepDelayedCommand.php tests/Unit/Console/SweepDelayedCommandTest.php
git commit -m "feat: SweepDelayedCommand artisan command"
```

---

## Task 15: ServiceProvider wiring — bindings, Queue::extend, command registration, schedule

**Files:**
- Modify: `src/HorizonSqsServiceProvider.php`
- Test: `tests/Unit/HorizonSqsServiceProviderTest.php`

- [ ] **Step 15.1: Add failing tests for wiring**

Append to `tests/Unit/HorizonSqsServiceProviderTest.php`:

```php
    public function test_workload_repository_binding_swapped(): void
    {
        $resolved = $this->app->make(\Laravel\Horizon\Contracts\WorkloadRepository::class);

        $this->assertInstanceOf(
            \MasonWorkforce\HorizonSqs\Repositories\SqsWorkloadRepository::class,
            $resolved
        );
    }

    public function test_sqs_driver_resolves_to_horizon_sqs_queue(): void
    {
        $queue = $this->app['queue']->connection('sqs');

        $this->assertInstanceOf(
            \MasonWorkforce\HorizonSqs\Queue\HorizonSqsQueue::class,
            $queue
        );
    }

    public function test_artisan_command_registered(): void
    {
        $commands = array_keys($this->app->make(\Illuminate\Contracts\Console\Kernel::class)->all());
        $this->assertContains('horizon-sqs:sweep-delayed', $commands);
    }

    public function test_validates_redis_connection_config(): void
    {
        config(['horizon-sqs.redis_connection' => 'nonexistent-connection']);
        config(['database.redis.nonexistent-connection' => null]);

        $this->expectException(\MasonWorkforce\HorizonSqs\Exceptions\InvalidConfigurationException::class);
        $this->app->make(\MasonWorkforce\HorizonSqs\Queue\HorizonSqsConnector::class)
            ->connect(config('queue.connections.sqs'));
    }
```

- [ ] **Step 15.2: Run tests to verify they fail**

Run: `vendor/bin/phpunit --filter HorizonSqsServiceProviderTest`
Expected: 4 new tests fail.

- [ ] **Step 15.3: Rewrite the ServiceProvider with full wiring**

Replace `src/HorizonSqsServiceProvider.php`:

```php
<?php

namespace MasonWorkforce\HorizonSqs;

use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Contracts\Cache\Repository as Cache;
use Illuminate\Contracts\Queue\Factory as QueueFactory;
use Illuminate\Contracts\Redis\Factory as RedisFactory;
use Illuminate\Queue\QueueManager;
use Illuminate\Support\ServiceProvider;
use Laravel\Horizon\Contracts\MetricsRepository;
use Laravel\Horizon\Contracts\ProcessRepository;
use Laravel\Horizon\Contracts\WorkloadRepository;
use MasonWorkforce\HorizonSqs\Console\SweepDelayedCommand;
use MasonWorkforce\HorizonSqs\Exceptions\InvalidConfigurationException;
use MasonWorkforce\HorizonSqs\Queue\Delay\DelayedJobReenqueuer;
use MasonWorkforce\HorizonSqs\Queue\Delay\DelayedJobStore;
use MasonWorkforce\HorizonSqs\Queue\HorizonSqsConnector;
use MasonWorkforce\HorizonSqs\Queue\Payload\PayloadEnricher;
use MasonWorkforce\HorizonSqs\Repositories\SqsWorkloadRepository;

class HorizonSqsServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__ . '/../config/horizon-sqs.php', 'horizon-sqs');

        $this->app->singleton(PayloadEnricher::class);

        $this->app->singleton(HorizonSqsConnector::class, function ($app) {
            return new HorizonSqsConnector(
                container: $app,
                enricher: $app->make(PayloadEnricher::class),
                redis: $app->make(RedisFactory::class),
                packageConfig: $this->validatedPackageConfig($app['config']->get('horizon-sqs')),
            );
        });

        $this->app->singleton(DelayedJobStore::class, function ($app) {
            return new DelayedJobStore(
                $app->make(RedisFactory::class),
                $app['config']->get('horizon-sqs.redis_connection')
            );
        });

        $this->app->singleton(DelayedJobReenqueuer::class, function ($app) {
            return new DelayedJobReenqueuer(
                store: $app->make(DelayedJobStore::class),
                queues: $app->make(QueueFactory::class),
                connectionName: 'sqs',
                sweepIntervalSeconds: (int) $app['config']->get('horizon-sqs.long_delay_sweep_interval', 60),
            );
        });

        $this->app->singleton(WorkloadRepository::class, function ($app) {
            $connection = $app['config']->get('queue.connections.sqs');
            $queues = $this->resolveQueueList($app);

            return new SqsWorkloadRepository(
                sqs: new \Aws\Sqs\SqsClient($this->awsConfigFor($connection)),
                metrics: $app->make(MetricsRepository::class),
                processes: $app->make(ProcessRepository::class),
                cache: $app->make(Cache::class),
                queuePrefix: $connection['prefix'] ?? '',
                queues: $queues,
                cacheTtlSeconds: (int) $app['config']->get('horizon-sqs.workload_cache_ttl', 5),
            );
        });
    }

    public function boot(): void
    {
        if ($this->app->runningInConsole()) {
            $this->publishes([
                __DIR__ . '/../config/horizon-sqs.php' => config_path('horizon-sqs.php'),
            ], 'horizon-sqs-config');

            $this->commands([SweepDelayedCommand::class]);
        }

        $manager = $this->app->make('queue');
        if ($manager instanceof QueueManager) {
            $manager->addConnector('sqs', fn () => $this->app->make(HorizonSqsConnector::class));
        }

        $this->app->booted(function () {
            $schedule = $this->app->make(Schedule::class);
            $schedule->command('horizon-sqs:sweep-delayed')
                ->everyMinute()
                ->withoutOverlapping()
                ->name('horizon-sqs-sweep-delayed');
        });
    }

    private function validatedPackageConfig(array $config): array
    {
        $redisConn = $config['redis_connection'] ?? null;
        if (! $redisConn || ! $this->app['config']->get("database.redis.{$redisConn}")) {
            throw new InvalidConfigurationException(
                "horizon-sqs.redis_connection '{$redisConn}' is not defined in database.redis."
            );
        }

        if (($config['extended_payload']['enabled'] ?? false) && empty($config['extended_payload']['bucket'])) {
            throw new InvalidConfigurationException(
                'horizon-sqs.extended_payload.enabled is true but no bucket is configured.'
            );
        }

        return $config;
    }

    private function resolveQueueList($app): array
    {
        $env = $app->environment();
        $supervisors = $app['config']->get("horizon.environments.{$env}", []);
        $queues = [];
        foreach ($supervisors as $supervisor) {
            foreach ((array) ($supervisor['queue'] ?? []) as $q) {
                $queues[] = $q;
            }
        }
        return array_values(array_unique($queues)) ?: [$app['config']->get('queue.connections.sqs.queue', 'default')];
    }

    private function awsConfigFor(array $config): array
    {
        $base = ['region' => $config['region'] ?? 'us-east-1', 'version' => 'latest'];
        if (! empty($config['key']) && ! empty($config['secret'])) {
            $base['credentials'] = [
                'key' => $config['key'],
                'secret' => $config['secret'],
                'token' => $config['token'] ?? null,
            ];
        }
        if (! empty($config['endpoint'])) {
            $base['endpoint'] = $config['endpoint'];
        }
        return $base;
    }
}
```

- [ ] **Step 15.4: Run all unit tests**

Run: `vendor/bin/phpunit --testsuite Unit`
Expected: all tests pass.

- [ ] **Step 15.5: Commit**

```bash
git add src/HorizonSqsServiceProvider.php tests/Unit/HorizonSqsServiceProviderTest.php
git commit -m "feat: wire ServiceProvider with bindings, Queue::extend, command, schedule"
```

---

## Task 16: Integration test harness — LocalStack

**Files:**
- Create: `docker-compose.yml`
- Create: `tests/Integration/IntegrationTestCase.php`

- [ ] **Step 16.1: Write `docker-compose.yml`**

```yaml
services:
  localstack:
    image: localstack/localstack:3
    ports:
      - "4566:4566"
    environment:
      - SERVICES=sqs,s3
      - DEBUG=0
      - PERSISTENCE=0
  redis:
    image: redis:7-alpine
    ports:
      - "6379:6379"
```

- [ ] **Step 16.2: Write `tests/Integration/IntegrationTestCase.php`**

```php
<?php

namespace MasonWorkforce\HorizonSqs\Tests\Integration;

use Aws\S3\S3Client;
use Aws\Sqs\SqsClient;
use MasonWorkforce\HorizonSqs\Tests\TestCase;

abstract class IntegrationTestCase extends TestCase
{
    protected SqsClient $sqs;
    protected ?S3Client $s3 = null;

    protected function setUp(): void
    {
        parent::setUp();

        $this->sqs = new SqsClient([
            'region' => 'us-east-1',
            'version' => 'latest',
            'endpoint' => env('LOCALSTACK_ENDPOINT', 'http://localhost:4566'),
            'credentials' => ['key' => 'test', 'secret' => 'test'],
        ]);
    }

    protected function defineEnvironment($app): void
    {
        parent::defineEnvironment($app);
        $app['config']->set('queue.connections.sqs.endpoint', env('LOCALSTACK_ENDPOINT', 'http://localhost:4566'));
    }

    protected function createQueue(string $name, bool $fifo = false): string
    {
        $attrs = $fifo ? ['FifoQueue' => 'true', 'ContentBasedDeduplication' => 'true'] : [];
        $response = $this->sqs->createQueue(['QueueName' => $name, 'Attributes' => $attrs]);
        return $response->get('QueueUrl');
    }

    protected function deleteAllQueues(): void
    {
        $response = $this->sqs->listQueues();
        foreach (($response->get('QueueUrls') ?? []) as $url) {
            $this->sqs->deleteQueue(['QueueUrl' => $url]);
        }
    }

    protected function ensureLocalStackAvailable(): void
    {
        try {
            $this->sqs->listQueues();
        } catch (\Throwable $e) {
            $this->markTestSkipped('LocalStack not available at ' . env('LOCALSTACK_ENDPOINT'));
        }
    }
}
```

- [ ] **Step 16.3: Start LocalStack and Redis**

Run: `docker compose up -d`
Expected: `localstack` and `redis` containers running. Verify with `docker compose ps`.

- [ ] **Step 16.4: Commit**

```bash
git add docker-compose.yml tests/Integration/IntegrationTestCase.php
git commit -m "test: integration test harness with LocalStack + Redis"
```

---

## Task 17: Integration — push→pop→process roundtrip

**Files:**
- Create: `tests/Integration/PushPopProcessTest.php`
- Create: `tests/Fixtures/Jobs/RecordingJob.php`

- [ ] **Step 17.1: Write the failing test**

`tests/Fixtures/Jobs/RecordingJob.php`:
```php
<?php

namespace MasonWorkforce\HorizonSqs\Tests\Fixtures\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

class RecordingJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public function __construct(public string $marker)
    {
    }

    public function handle(): void
    {
        file_put_contents(sys_get_temp_dir() . '/horizon-sqs-marker', $this->marker);
    }
}
```

`tests/Integration/PushPopProcessTest.php`:
```php
<?php

namespace MasonWorkforce\HorizonSqs\Tests\Integration;

use Illuminate\Support\Facades\Queue;
use MasonWorkforce\HorizonSqs\Tests\Fixtures\Jobs\RecordingJob;

class PushPopProcessTest extends IntegrationTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->ensureLocalStackAvailable();
        $this->deleteAllQueues();
        $url = $this->createQueue('default');
        config(['queue.connections.sqs.prefix' => str_replace('/default', '', $url)]);
        @unlink(sys_get_temp_dir() . '/horizon-sqs-marker');
    }

    public function test_roundtrip_processes_job(): void
    {
        Queue::push(new RecordingJob('hello'));

        $job = Queue::connection('sqs')->pop('default');
        $this->assertNotNull($job);
        $job->fire();

        $this->assertSame('hello', file_get_contents(sys_get_temp_dir() . '/horizon-sqs-marker'));
    }

    public function test_pushed_payload_contains_horizon_fields(): void
    {
        Queue::push(new RecordingJob('hi'));

        $job = Queue::connection('sqs')->pop('default');
        $decoded = json_decode($job->getRawBody(), true);

        $this->assertArrayHasKey('id', $decoded);
        $this->assertArrayHasKey('pushedAt', $decoded);
        $this->assertArrayHasKey('tags', $decoded);
    }
}
```

- [ ] **Step 17.2: Run test**

Run: `vendor/bin/phpunit --filter PushPopProcessTest`
Expected: PASS (2 tests). If LocalStack isn't running, tests skip.

- [ ] **Step 17.3: Commit**

```bash
git add tests/Integration/PushPopProcessTest.php tests/Fixtures/Jobs/RecordingJob.php
git commit -m "test: integration roundtrip via LocalStack"
```

---

## Task 18: Integration — FIFO ordering

**Files:**
- Create: `tests/Integration/FifoOrderingTest.php`

- [ ] **Step 18.1: Write the test**

```php
<?php

namespace MasonWorkforce\HorizonSqs\Tests\Integration;

use Illuminate\Support\Facades\Queue;

class FifoOrderingTest extends IntegrationTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->ensureLocalStackAvailable();
        $this->deleteAllQueues();
        $url = $this->createQueue('orders.fifo', fifo: true);
        config(['queue.connections.sqs.prefix' => str_replace('/orders.fifo', '', $url)]);
    }

    public function test_fifo_preserves_order_within_group(): void
    {
        $sqs = Queue::connection('sqs');

        $sqs->pushRaw('{"id":"1","seq":1,"_horizon_nonce":"a1"}', 'orders.fifo');
        $sqs->pushRaw('{"id":"2","seq":2,"_horizon_nonce":"a2"}', 'orders.fifo');
        $sqs->pushRaw('{"id":"3","seq":3,"_horizon_nonce":"a3"}', 'orders.fifo');

        $received = [];
        for ($i = 0; $i < 3; $i++) {
            $job = $sqs->pop('orders.fifo');
            $this->assertNotNull($job, "expected job #{$i}");
            $body = json_decode($job->getRawBody(), true);
            $received[] = $body['seq'];
        }

        $this->assertSame([1, 2, 3], $received);
    }
}
```

- [ ] **Step 18.2: Run test**

Run: `vendor/bin/phpunit --filter FifoOrderingTest`
Expected: PASS.

- [ ] **Step 18.3: Commit**

```bash
git add tests/Integration/FifoOrderingTest.php
git commit -m "test: FIFO ordering integration test"
```

---

## Task 19: Integration — extended payload

**Files:**
- Create: `tests/Integration/ExtendedPayloadTest.php`

- [ ] **Step 19.1: Write the test**

```php
<?php

namespace MasonWorkforce\HorizonSqs\Tests\Integration;

use Aws\S3\S3Client;
use Illuminate\Support\Facades\Queue;
use MasonWorkforce\HorizonSqs\Queue\Payload\PayloadEnricher;

class ExtendedPayloadTest extends IntegrationTestCase
{
    private string $bucket = 'horizon-sqs-test';

    protected function setUp(): void
    {
        parent::setUp();
        $this->ensureLocalStackAvailable();
        $this->deleteAllQueues();
        $url = $this->createQueue('default');
        config([
            'queue.connections.sqs.prefix' => str_replace('/default', '', $url),
            'horizon-sqs.extended_payload.enabled' => true,
            'horizon-sqs.extended_payload.bucket' => $this->bucket,
        ]);

        $this->s3 = new S3Client([
            'region' => 'us-east-1',
            'version' => 'latest',
            'endpoint' => env('LOCALSTACK_ENDPOINT', 'http://localhost:4566'),
            'use_path_style_endpoint' => true,
            'credentials' => ['key' => 'test', 'secret' => 'test'],
        ]);
        try {
            $this->s3->createBucket(['Bucket' => $this->bucket]);
        } catch (\Throwable) {
            // already exists
        }
    }

    public function test_roundtrip_large_payload(): void
    {
        $big = str_repeat('x', 300_000);
        $payload = (new PayloadEnricher())->enrich(['custom' => $big], 'default');
        $json = json_encode($payload);

        Queue::connection('sqs')->pushRaw($json, 'default');

        $job = Queue::connection('sqs')->pop('default');
        $this->assertNotNull($job);
        $decoded = json_decode($job->getRawBody(), true);
        $this->assertSame($big, $decoded['custom']);
    }
}
```

- [ ] **Step 19.2: Run test**

Run: `vendor/bin/phpunit --filter ExtendedPayloadTest`
Expected: PASS.

- [ ] **Step 19.3: Commit**

```bash
git add tests/Integration/ExtendedPayloadTest.php
git commit -m "test: extended payload S3 roundtrip integration"
```

---

## Task 20: Integration — long-delay sweep

**Files:**
- Create: `tests/Integration/LongDelaySweepTest.php`

- [ ] **Step 20.1: Write the test**

```php
<?php

namespace MasonWorkforce\HorizonSqs\Tests\Integration;

use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Redis;
use MasonWorkforce\HorizonSqs\Queue\Delay\DelayedJobReenqueuer;

class LongDelaySweepTest extends IntegrationTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->ensureLocalStackAvailable();
        $this->deleteAllQueues();
        $url = $this->createQueue('default');
        config(['queue.connections.sqs.prefix' => str_replace('/default', '', $url)]);
        Redis::connection('default')->del('horizon-sqs:delayed');
    }

    public function test_long_delay_is_buffered_and_swept(): void
    {
        // dispatch with > 15 min delay → goes to Redis sorted set
        $now = time();
        Queue::later(3600, '{"id":"abc","tags":[],"_horizon_nonce":"n"}', '', 'default');

        $this->assertGreaterThan(0, Redis::connection('default')->zcard('horizon-sqs:delayed'));

        // simulate sweep with "now" advanced to within sweep window
        $reenqueuer = $this->app->make(DelayedJobReenqueuer::class);
        $reenqueuer->sweep($now + 3700);

        // entry removed
        $this->assertSame(0, Redis::connection('default')->zcard('horizon-sqs:delayed'));

        // and is now in SQS (within 900s delay so receivable immediately on LocalStack)
        sleep(1);
        $job = Queue::connection('sqs')->pop('default');
        $this->assertNotNull($job);
    }
}
```

- [ ] **Step 20.2: Run test**

Run: `vendor/bin/phpunit --filter LongDelaySweepTest`
Expected: PASS.

- [ ] **Step 20.3: Commit**

```bash
git add tests/Integration/LongDelaySweepTest.php
git commit -m "test: long-delay buffering and sweep integration"
```

---

## Task 21: Integration — Horizon dashboard JSON

**Files:**
- Create: `tests/Integration/DashboardJsonTest.php`

- [ ] **Step 21.1: Write the test**

```php
<?php

namespace MasonWorkforce\HorizonSqs\Tests\Integration;

use Illuminate\Support\Facades\Queue;
use MasonWorkforce\HorizonSqs\Tests\Fixtures\Jobs\RecordingJob;

class DashboardJsonTest extends IntegrationTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->ensureLocalStackAvailable();
        $this->deleteAllQueues();
        $url = $this->createQueue('default');
        config([
            'queue.connections.sqs.prefix' => str_replace('/default', '', $url),
            'horizon.environments.testing.supervisor-1.queue' => ['default'],
            'horizon.environments.testing.supervisor-1.connection' => 'sqs',
        ]);
    }

    public function test_workload_endpoint_returns_sqs_backed_data(): void
    {
        Queue::push(new RecordingJob('m1'));
        Queue::push(new RecordingJob('m2'));

        sleep(1); // SQS approximate count latency

        $response = $this->get('/horizon/api/workload');

        $response->assertOk();
        $data = $response->json();

        $default = collect($data)->firstWhere('name', 'default');
        $this->assertNotNull($default);
        $this->assertGreaterThanOrEqual(2, $default['length']);
        $this->assertArrayHasKey('wait', $default);
    }
}
```

- [ ] **Step 21.2: Run test**

Run: `vendor/bin/phpunit --filter DashboardJsonTest`
Expected: PASS.

- [ ] **Step 21.3: Commit**

```bash
git add tests/Integration/DashboardJsonTest.php
git commit -m "test: Horizon dashboard workload JSON via SqsWorkloadRepository"
```

---

## Task 22: CI workflow

**Files:**
- Create: `.github/workflows/tests.yml`

- [ ] **Step 22.1: Write the workflow**

```yaml
name: tests

on:
  push:
    branches: [main, master]
  pull_request:

jobs:
  unit:
    runs-on: ubuntu-latest
    strategy:
      fail-fast: false
      matrix:
        php: ['8.2', '8.3', '8.4']
        laravel: ['^10.0', '^11.0', '^12.0']
        exclude:
          - php: '8.2'
            laravel: '^12.0'
    steps:
      - uses: actions/checkout@v4
      - uses: shivammathur/setup-php@v2
        with:
          php-version: ${{ matrix.php }}
          coverage: none
      - run: composer require "illuminate/queue:${{ matrix.laravel }}" "illuminate/support:${{ matrix.laravel }}" "illuminate/redis:${{ matrix.laravel }}" --no-update
      - run: composer update --prefer-stable --no-interaction
      - run: vendor/bin/phpunit --testsuite Unit

  integration:
    runs-on: ubuntu-latest
    services:
      localstack:
        image: localstack/localstack:3
        ports: ['4566:4566']
        env:
          SERVICES: sqs,s3
      redis:
        image: redis:7-alpine
        ports: ['6379:6379']
    steps:
      - uses: actions/checkout@v4
      - uses: shivammathur/setup-php@v2
        with:
          php-version: '8.3'
          coverage: none
      - run: composer install --no-interaction
      - run: vendor/bin/phpunit --testsuite Integration
        env:
          LOCALSTACK_ENDPOINT: http://localhost:4566
          REDIS_HOST: 127.0.0.1
```

- [ ] **Step 22.2: Commit**

```bash
git add .github/workflows/tests.yml
git commit -m "ci: GitHub Actions matrix for unit + integration tests"
```

---

## Task 23: README

**Files:**
- Create: `README.md`

- [ ] **Step 23.1: Write the README**

```markdown
# horizon-sqs

Laravel Horizon for Amazon SQS — same dashboard, same metrics, SQS underneath.

## Why

Laravel Horizon is built for Redis queues. This package makes Horizon's dashboard work when your transport is SQS, using Redis only as a stats sidecar (Horizon's existing repositories, unchanged).

## What works

- Throughput metrics (jobs/min, jobs/hour)
- Recent / Failed / Completed jobs lists with payloads + retry
- Workload page (pending counts + estimated wait time)
- Tags and Monitored tags
- Job batches
- Retry from dashboard
- FIFO queues (standard + FIFO)
- S3 spill-over for payloads > 256 KB (opt-in)
- Long delays (>15 min) buffered in Redis, swept into SQS

## Not yet in v0.1.0

- In-worker visibility-timeout heartbeat (config knob reserved; planned for v0.2).

## Install

```bash
composer require masonworkforce/horizon-sqs
php artisan vendor:publish --tag=horizon-sqs-config
```

## Configure

`config/queue.php` — your `sqs` connection stays the same.

`config/horizon-sqs.php`:

```php
return [
    'redis_connection' => env('HORIZON_SQS_REDIS', 'default'),
    'workload_cache_ttl' => 5,
    'sqs_max_delay' => 900,
    'long_delay_sweep_interval' => 60,
    'visibility_heartbeat' => false,
    'fifo' => [
        'message_group_id' => 'queue-name', // 'queue-name' | 'job-class' | callable
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

`config/horizon.php` — set your supervisor connection to `sqs`.

## Operational notes

- **Visibility timeout:** set on each SQS queue to ≥ your job `timeout` × 1.5. (The `visibility_heartbeat` config knob is reserved for v0.2 — currently ignored.)
- **Extended payload cleanup:** add a lifecycle rule to your S3 bucket prefix (default `horizon-sqs-payloads/`) to clean up orphans from worker crashes.
- **Long-delay sweep:** auto-registered via Laravel's scheduler. Ensure `schedule:run` is wired in your cron.

## Testing locally

```bash
docker compose up -d
vendor/bin/phpunit
```

## License

MIT.
```

- [ ] **Step 23.2: Commit**

```bash
git add README.md
git commit -m "docs: README with install, config, operational notes"
```

---

## Task 24: Final verification

- [ ] **Step 24.1: Run full test suite**

Run: `vendor/bin/phpunit`
Expected: all unit and integration tests pass (LocalStack and Redis must be running for integration).

- [ ] **Step 24.2: Verify package can be installed in a fresh Laravel app**

Manual verification step. Skip in CI; do once locally before tagging v0.1.0:
```bash
# in a fresh Laravel 11 app:
composer config repositories.horizon-sqs path /path/to/HorizonSQS
composer require masonworkforce/horizon-sqs:dev-master
php artisan vendor:publish --tag=horizon-sqs-config
# set QUEUE_CONNECTION=sqs, AWS_*, point at LocalStack or real SQS
php artisan horizon
# dispatch a job, observe in dashboard
```

- [ ] **Step 24.3: Tag v0.1.0**

```bash
git tag v0.1.0
git log --oneline
```

---

## Acceptance Criteria check

Map back to spec acceptance criteria:

1. **Fresh app shows full dashboard parity** → covered by Tasks 15 (wiring), 17, 21 (dashboard JSON test), 24.2 (manual verification).
2. **FIFO ordering** → Task 18.
3. **600 KB payload roundtrip** → Task 19.
4. **delay(3600) processed ~1 hour later** → Task 20.
5. **CI matrix green** → Task 22.
