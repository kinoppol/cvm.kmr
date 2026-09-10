# CLAUDE.md

This file provides guidance to Claude Code (claude.ai/code) when working with code in this repository.

## What this is

**RVC Learn** (`rvc/learn`) — a learning-management system for วิทยาลัยเทคนิคร้อยเอ็ด (Roi Et Technical College). PHP 8.1+, Slim 4 + PHP-DI, Latte templates, MariaDB 10.4+. All user-facing strings and code comments are in Thai; keep it that way.

The full teacher/student/admin experience is implemented (branch `feature/lms-teacher-flow`): role-aware dashboards, courses + lessons, an AI quiz generator and lesson-plan generator (streamed over SSE), a review-before-publish queue, BYOK AI settings, student quiz-taking with auto-grading, and an admin AI/quota dashboard. `project/RVC Learn.dc.html` is the visual spec these were built from.

**The AI layer runs against a simulator** (`App\AI\SimulatedProvider`) because there is no GPU box yet. `App\AI\AiRouter` already implements the real routing precedence (teacher key → college endpoint → unavailable) with quota/queue accounting; swapping in a real `OllamaProvider` / `OpenAiCompatibleProvider` is a localized change in `AiRouter::collegeProvider()` / `byokProvider()`.

`README.md` is **not** about this codebase — it is a design handoff bundle note from Claude Design pointing at `project/RVC Learn.dc.html` (an HTML/CSS design prototype under `project/`).

Run `/admin/demo` (admin only) to load realistic ปวช./ปวส. demo data. Demo accounts: teacher `thanaphon`, students `66201001`–`66201028`, password `rvclearn2569`.

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
`App\Auth\Auth` owns everything: `attempt()` (with throttling — 5 failed attempts / 15 min per username-or-IP via `login_attempts` table), session start with `session_regenerate_id`, `user()` lookup, `log()` to `audit_logs`. Two PSR-15 middlewares: `AuthMiddleware` (requires login, sets `user` request attribute, redirects to `/login`) and `RoleMiddleware([...])` (throws `HttpForbiddenException`). Routes in `app/routes.php`: public login group, then an auth group (`ViewContext` + `AuthMiddleware`) with per-role subgroups.

**Impersonation**: an admin can assume another (non-admin, active) user via `POST /admin/users/{id}/impersonate` — `Auth::impersonate()` stashes the admin's id in `$_SESSION['impersonator_id']` and swaps `user_id`/`user_role` (without touching the target's `last_login_at`). While impersonating, admin routes 403 as expected; a sticky banner (shared as `impersonatedBy` by `ViewContext`) and the header button post to `POST /impersonate/stop` (`Auth::stopImpersonating()`, outside the role subgroups). `AuthController::logout` also detects impersonation and restores the admin instead of ending the session. `Auth::log()` adds `via_admin_id` to meta during impersonation.

### Migration system (`app/Migration/`, `database/migrations/`)
Custom, Laravel-flavored. Files named `YYYY_MM_DD_NNNNNN_snake_case.php` containing one class extending `App\Migration\Migration` (class name = StudlyCase of the part after the timestamp, e.g. `CreateCoreTables`). Each migration implements `description()` (Thai), `up(Runner)`, `down(Runner)` and calls `$this->table('name')` (applies the configurable table prefix) and `$this->options()` (shared `ENGINE=InnoDB ... utf8mb4`).

- **`Migrator`** — discovers files, tracks state in the `<prefix>migrations` table, runs pending in filename order stopping on first failure, batches, rollback/reset, MySQL `GET_LOCK` around runs.
- **`Runner`** interface with two impls: **`LiveRunner`** executes SQL; **`DryRunner`** just records it. `preview()` and `declaredTables()` use `DryRunner` to get SQL without touching the DB — `declaredTables()` regex-parses `CREATE TABLE` names so the installer's "fresh install" drops only *this system's* tables, never co-tenant tables sharing the database.

Prefix awareness is pervasive: `Auth`, `Migrator`, `Installer`, controllers all take the prefix from `db.prefix` config and interpolate it into backtick-quoted table names. When writing queries, follow this pattern — there is no query builder or ORM.

### Schema domains (migration files)
`000101_create_core_tables` (users, remember_tokens, login_attempts, settings, audit_logs), `000102_create_lms_tables`, `000103_create_assessment_tables`, `000104_create_ai_tables`.

### Data access & AI layer
- **`App\Support\Db`** — thin PDO helper. `{table}` in SQL expands to the prefixed backtick-quoted name; `first/all/value/int/run/insert/update/transaction`. All repositories in `app/Domain/` take a `Db`. There is no ORM.
- **`app/Domain/*Repository`** — one per aggregate (Course, Lesson, Quiz, Enrollment, Attempt, Review, Settings, Ai, LessonPlan). `SettingsRepository` also caches the `settings` table and stores per-user prefs as `name:userId` keys.
- **`App\Support\ViewContext`** middleware (on the auth group) shares `user`, `term`, and teacher `reviewCount` into every Latte render.
- **`app/AI/`** — `AiProvider` interface (`stream()` yields NDJSON text chunks), `SimulatedProvider`, `QuestionBank` (real vocational questions by subject), `QuizGenerator` / `LessonPlanGenerator` (parse NDJSON → structs), `AiRouter` → `AiRoute` (provider + source label + chip + wait + quota flag), `KeyCipher` (AES-256-GCM over `app.key`), `AiUnavailableException` (`reason` drives `_partials/ai-state.latte`).
- Lesson plans are stored as `ai_generations` rows (`target_type='lesson_plan'`, doc JSON in `payload`), not a dedicated table.

### SSE generation flow
Quiz/plan generation streams over `text/event-stream` (`QuizWizardController::stream`, `LessonPlanController::stream`): `ignore_user_abort(true)` + `set_time_limit(0)` so the job finishes and persists even if the browser closes; events are `meta` / `question`|`section` / `done` / `error`. The result is always saved server-side as a draft + a pending `ai_generations` row regardless of the client.

### Public landing page
`/` serves `LandingController` — a guest-facing course showcase (`resources/views/landing.latte`, its own `public/assets/css/landing.css`, styled after the Colorlib "Education" template kept in `resources/design/course-template/`, gitignored). Logged-in users are redirected to `/dashboard`. A teacher opts their courses in via the switch on `/courses` (`POST /courses/landing-visibility` → `courses.show_on_landing`, default 0 for all).

### Latte gotchas
- Interpolating a value that contains `#`, `(`, or `)` **inside** a `style="..."` attribute gets CSS-escaped to `\#` / `\(` (invalid CSS). Put the literal char in the template and interpolate only the safe part: `style="background:#{$c['color']}"` with `$c['color']` = `0E6B60` (no `#`), or use a CSS class (`class="gpu-bar-{$tone}"`). `var(--x)` written literally in the template is fine.
- `class="..."` and `n:class="..."` cannot both be on one element — merge into `n:class="base, cond ? extra"`.
- `n:class` does not do `{}` interpolation; use `class="prefix-{$x}"` or `n:class="'prefix-' . $x"`.
- Anything toggled with `el.hidden` needs the global `[hidden]{display:none!important}` rule in `app.css` to beat class `display` (e.g. `.modal-backdrop{display:grid}`).

### Demo data
`App\Install\DemoSeeder` (`/admin/demo`) `TRUNCATE`s the LMS/assessment/AI tables (so demo IDs stay stable) and reseeds departments, 6 teachers, 28 students, 4 courses, lessons, a published quiz with 22 graded attempts, pending review items, AI endpoint, quotas, and 7 days of usage logs.

## Conventions

- `declare(strict_types=1)` in every PHP file; `final` classes; constructor property promotion; readonly where possible.
- Controllers are `final`, constructor-injected via PHP-DI autowiring, return PSR-7 responses. Note the comment in `routes.php`: Slim route closures must **not** be `static`.
- Templates: Latte in `resources/views/`, `.latte`, extend `layout.latte` via `{block content}`. Thai UI text, `data-theme` dark-mode toggle stored in `localStorage`.
- Assets are plain CSS in `public/assets/css/app.css` — no bundler, no npm.
- Web root is `public/`; `config/` and `storage/` sit outside it, with root `.htaccess` rewriting everything into `public/`.
