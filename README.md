# ERP — Backend

Laravel 12 API for the Enterprise ERP. Modular monolith with DDD.

> **Read the canonical [`../CLAUDE.md`](../CLAUDE.md) at the workspace root before contributing.** It captures the locked tech stack, architectural rules, agent rules, and the build order for the whole ERP.

The frontend SPA lives in a sibling repository under [`../frontend/`](../frontend) and talks to this API via `/api/v1/*` (Sanctum SPA + credentialed cookies).

## Stack

- **Framework:** Laravel 12 (API-only)
- **PHP:** 8.3+
- **DB:** PostgreSQL 16+
- **Cache / queue / session:** Redis
- **Search:** Meilisearch (Scout driver)
- **Auth:** Laravel Sanctum + `spatie/laravel-permission` (tenant-scoped, Step 1)
- **Money:** BCMath at scale 4 — floats banned in money code paths
- **Local stack:** XAMPP (PHP only) + native Postgres + native Redis. **No Docker.**

## Quick start (local)

Prerequisites: PHP 8.3+ with `pdo_pgsql`, `pgsql`, `bcmath`, `intl`, `mbstring`, `fileinfo`, `openssl`, `curl`, `zip`, `gd` enabled; Composer; PostgreSQL 16+; Redis.

```sh
cp .env.example .env
# Fill in DB_PASSWORD locally — never commit .env
composer install
php artisan key:generate
php artisan migrate
php artisan serve              # API at http://127.0.0.1:8000

# In another terminal: queue worker
php artisan queue:work --tries=1
```

## Useful Composer scripts

| Command | What it does |
|---|---|
| `composer setup` | First-time bootstrap (.env, key, migrate) |
| `composer test` | Run Pest test suite |
| `composer test:types` | PHPStan level 8 |
| `composer lint` | Pint check (no fix) |
| `composer lint:fix` | Pint apply |

## Repo layout

See [`docs/architecture.md`](./docs/architecture.md) for the directory map and boundary rules.

## Contributing

- PR can't merge unless: PHPStan L8 passes, Pint shows no changes, tests green, ≥1 reviewer.
- **Financial code requires 2 reviewers.**
- Migrations are forward-only in production. To undo, write a new migration.
- Cross-domain imports are forbidden (CI-enforced once `deptrac` is wired in Step 1).

## License

Proprietary.
