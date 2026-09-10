# CLAUDE.md

This file provides guidance to Claude Code (claude.ai/code) when working with code in this repository.

## What this is

**RVC Learn** (`rvc/learn`) — a learning-management system for วิทยาลัยเทคนิคร้อยเอ็ด (Roi Et Technical College). PHP 8.1+, Slim 4 + PHP-DI, Latte templates, MariaDB 10.4+. All user-facing strings and code comments are in Thai; keep it that way.

The repo is at an early stage: only the install/bootstrap/migration foundation and a login + dashboard + migration-admin UI exist. The LMS feature domains (courses, assessments, AI) exist only as migration schemas, not yet as code.

`README.md` is **not** about this codebase — it is a design handoff bundle note from Claude Design pointing at `project/RVC Learn.dc.html` (an HTML/CSS design prototype under `project/`). Treat that prototype as a visual spec to implement, not as code to run.

## Commands

```bash
composer install                    # install dependencies (required before anything works)
composer serve                      # dev server at http://localhost:8080 (php -S, docroot public/)
```

Then open `http://localhost:8080/install.php` to run the 4-step installer (requirements → database → site/admin → install). The installer creates the database, runs migrations, seeds settings + admin user, and writes `config/config.php` + `storage/install.lock`. Re-running `install.php` after install requires authenticating as an admin user.

There is **no test suite, linter, or build step** configured. `composer.json` has no `require-dev`. If adding tests, PHPUnit is the natural choice but nothing is wired up yet.

Database migrations are run through the web UI at `/admin/migrations` (admin only) — run / rollback last batch / reset all / preview SQL. There is no CLI migration runner.

## Architecture

### Request lifecycle
`public/index.php` → checks `vendor/` and `Config::isInstalled()` (redirects to `install.php` if not) → starts session (`RVCSESSID`) → `app/bootstrap.php` builds the PHP-DI container, creates the Slim app, sets base path from `app.url`, registers a custom error handler that renders `error.latte` with Thai messages per status code → loads `app/routes.php` → `$app->run()`.

### Key seams
- **`App\Support\Config`** — immutable dot-notation accessor over `config/config.php` (a `return [...]` PHP array). `Config::write()` is installer-only. `config/config.php` is gitignored and must never be committed.
- **`App\Support\Paths`** — all filesystem paths, derived from its own location so `install.php` can use it before `vendor/` exists. `install.php` manually `require`s `Paths.php` + `Requirements.php` when there is no autoloader.
- **`App\Support\Database`** — PDO factory (`connect` with db, `connectServer` without) + server version inspection. Enforces `utf8mb4`, `STRICT_TRANS_TABLES`, real prepares.
- **`App\Support\View`** — thin Latte wrapper; `render()` injects shared vars + `flash` (`Flash::pull()`) + `csrf` (`Csrf::token()`) into every template.
- **`App\Support\Url`** — static base-path holder; use `Url::to('/path')` for internal links, `{$base}/path` in templates.
- **`Csrf`** / **`Flash`** — session-backed. Every state-changing POST checks `Csrf::check($data['_token'])`; forms include `<input name="_token" value="{$csrf}">`.

### Auth
`App\Auth\Auth` owns everything: `attempt()` (with throttling — 5 failed attempts / 15 min per username-or-IP via `login_attempts` table), session start with `session_regenerate_id`, `user()` lookup, `log()` to `audit_logs`. Two PSR-15 middlewares: `AuthMiddleware` (requires login, sets `user` request attribute, redirects to `/login`) and `RoleMiddleware(['admin'])` (throws `HttpForbiddenException`). Routes in `app/routes.php`: public login group, then an auth group wrapping `/dashboard` and an admin subgroup for `/admin/migrations`.

### Migration system (`app/Migration/`, `database/migrations/`)
Custom, Laravel-flavored. Files named `YYYY_MM_DD_NNNNNN_snake_case.php` containing one class extending `App\Migration\Migration` (class name = StudlyCase of the part after the timestamp, e.g. `CreateCoreTables`). Each migration implements `description()` (Thai), `up(Runner)`, `down(Runner)` and calls `$this->table('name')` (applies the configurable table prefix) and `$this->options()` (shared `ENGINE=InnoDB ... utf8mb4`).

- **`Migrator`** — discovers files, tracks state in the `<prefix>migrations` table, runs pending in filename order stopping on first failure, batches, rollback/reset, MySQL `GET_LOCK` around runs.
- **`Runner`** interface with two impls: **`LiveRunner`** executes SQL; **`DryRunner`** just records it. `preview()` and `declaredTables()` use `DryRunner` to get SQL without touching the DB — `declaredTables()` regex-parses `CREATE TABLE` names so the installer's "fresh install" drops only *this system's* tables, never co-tenant tables sharing the database.

Prefix awareness is pervasive: `Auth`, `Migrator`, `Installer`, controllers all take the prefix from `db.prefix` config and interpolate it into backtick-quoted table names. When writing queries, follow this pattern — there is no query builder or ORM.

### Schema domains (migration files)
`000101_create_core_tables` (users, remember_tokens, login_attempts, settings, audit_logs), `000102_create_lms_tables`, `000103_create_assessment_tables`, `000104_create_ai_tables`. Only core-table columns are consumed by code so far.

## Conventions

- `declare(strict_types=1)` in every PHP file; `final` classes; constructor property promotion; readonly where possible.
- Controllers are `final`, constructor-injected via PHP-DI autowiring, return PSR-7 responses. Note the comment in `routes.php`: Slim route closures must **not** be `static`.
- Templates: Latte in `resources/views/`, `.latte`, extend `layout.latte` via `{block content}`. Thai UI text, `data-theme` dark-mode toggle stored in `localStorage`.
- Assets are plain CSS in `public/assets/css/app.css` — no bundler, no npm.
- Web root is `public/`; `config/` and `storage/` sit outside it, with root `.htaccess` rewriting everything into `public/`.
