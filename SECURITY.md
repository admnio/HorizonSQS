# Security Policy

## Supported versions

| Version | Supported          |
|---------|--------------------|
| 1.x     | Yes — actively maintained |
| 0.x     | No — please upgrade to 1.0 |

## Reporting a vulnerability

Please do NOT open a public GitHub issue for security reports.

Instead, email **security@admn.io** with:

- A description of the vulnerability
- Steps to reproduce (or a minimal reproduction repo)
- Affected Sunset versions
- Your assessment of the severity / blast radius

We'll acknowledge within 72 hours and aim to ship a fix within two weeks for confirmed high-severity issues. Coordinated disclosure: we'll work with you on a timeline before publishing details.

## Scope

Sunset is a Laravel package. Relevant attack surfaces include:

- The dashboard at `/sunset` — auth gate, CSRF protection, destructive POST actions (retry/delete/pause)
- Rate-limit Redis keyspace — concurrency-slot lifecycle, sweep correctness
- Transport pop paths — payload deserialization, gate logic
- Failed-job storage — exception payload handling
- Bundle integrity — the `public-dist/app.{js,css}` shipped in the Composer package

Out of scope: vulnerabilities in `laravel/framework`, `inertiajs/inertia-laravel`, `predis/predis`, `vladimir-yuldashev/laravel-queue-rabbitmq`, or other upstream dependencies. Please report those to the respective projects.

## Disclosure

Confirmed and fixed vulnerabilities are documented in `CHANGELOG.md` with a `[security]` tag.
