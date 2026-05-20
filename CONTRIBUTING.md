# Contributing to Sunset

Thanks for considering a contribution. Sunset aims for a small, careful surface area; the goal is a Laravel queue dashboard + supervisor that's reliable enough to leave running.

## Quick start

```bash
git clone https://github.com/admnio/sunset.git
cd sunset
composer install
npm install
docker compose up -d   # Redis + LocalStack (SQS) + RabbitMQ
vendor/bin/phpunit     # full test suite — should be green
```

## Running the full suite

The test suite has three testsuites:

```bash
vendor/bin/phpunit --testsuite Unit          # fast; no services needed
vendor/bin/phpunit --testsuite Integration   # needs docker compose up
vendor/bin/phpunit --testsuite Browser       # needs ChromeDriver running
```

For browser tests, start ChromeDriver in a separate terminal:

```bash
chromedriver --port=9515
```

Without it, browser tests skip cleanly — they don't fail the build.

## Rebuilding the dashboard bundle

The compiled JS/CSS at `public-dist/` ships in the Composer package. After changing any Vue/Tailwind source under `resources/`, rebuild and commit the output:

```bash
npm run build
git add public-dist/
```

CI verifies the committed bundle matches a fresh build — stale bundles fail the `bundle` job.

## Conventions

- **PHP 8.2+ syntax**: readonly promoted properties, constructor property promotion, named args where it improves readability.
- **PSR-4** autoloading; no inline `require`s.
- **Test naming**: `test_what_it_does_in_this_scenario` (snake_case, no `it_` prefix).
- **TDD where it earns its keep**: write failing tests first for new public-facing API; pragmatic for pure-glue code.
- **No `@dataProvider` doc-comments** — use the `#[DataProvider]` PHPUnit 11+ attribute instead.
- **Internal classes get `@internal`** PHPDoc — see `README.md` "Public API" section for what's stable.
- **Frequent commits**: smaller, focused commits over giant batches. CI re-runs the whole suite per push, so cheap commits are fine.

## Pull request workflow

1. Branch off `master`. Branch names like `feat/something`, `fix/something`, `chore/something`.
2. One concern per PR. If you find yourself fixing five unrelated things in one branch, split them.
3. Tests must be green (`vendor/bin/phpunit`) before requesting review.
4. Update `UPGRADING.md` if the change affects consumer-facing behavior.
5. Update `CHANGELOG.md` under the next-release heading.
6. The bundle CI job will fail your PR if you forgot to commit a fresh `npm run build` after changing the dashboard.

## What's public API (cannot change without a major version bump)

See the "Public API" section in `README.md`. The short version:

- Facade methods: `Sunset::auth()`, `Sunset::for()`, `Sunset::limit()`
- Contracts under `Admnio\Sunset\Contracts\*`
- Events under `Admnio\Sunset\Events\*`
- Exceptions under `Admnio\Sunset\Exceptions\*`
- Value objects under `Admnio\Sunset\RateLimiting\` (Limit/LimitBuilder/ThrottleSpec/ConcurrencySpec/Decision/Targets)
- `JobPayload`, `Tags`, `Manager`, `SunsetServiceProvider`
- Artisan command names (e.g. `sunset:work`, `sunset:install`)
- Dashboard routes under `/sunset` and their props shapes
- Published config keys in `config/sunset.php`

Everything marked `@internal` is fair game to refactor in minor releases.

## Discussion + issues

- Bug reports: include Laravel version, PHP version, transport(s) in use, and a minimum reproduction.
- Feature requests: describe the user-facing problem before the proposed solution.
- Security issues: see `SECURITY.md`.

## Code of conduct

Be kind. Assume good faith. Disagree with the proposal, not the person.
