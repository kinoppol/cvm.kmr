# CLAUDE.md

This file provides guidance to Claude Code (claude.ai/code) when working with code in this repository.

## What this is

**RVC Learn** (`rvc/learn`) — a learning-management system for วิทยาลัยเทคนิคร้อยเอ็ด (Roi Et Technical College). PHP 8.1+, Slim 4 + PHP-DI, Latte templates, MariaDB 10.4+. All user-facing strings and code comments are in Thai; keep it that way.

The full teacher/student/admin experience is implemented (branch `feature/lms-teacher-flow`): role-aware dashboards, courses + lessons, an AI quiz generator and lesson-plan generator (streamed over SSE), a review-before-publish queue, BYOK AI settings, student quiz-taking with auto-grading, and an admin AI/quota dashboard. `project/RVC Learn.dc.html` is the visual spec these were built from.

**Quiz generation, lesson-plan generation and the test chat all call the real API** (`AiRouter::route(..., real: true)`); `SimulatedProvider` is now only the fallback path when `real` is false. The central AI machine is called **AI ของส่วนกลาง** in the UI (never "AI ของวิทยาลัย"), though the routing source key stays `college` in code and the DB.

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

### Trailing slashes
Routes are declared without a trailing slash (`/courses`, not `/courses/`), and Slim treats the two as different paths — so a hand-typed `/courses/` used to render the Thai 404 page. A middleware registered in `bootstrap.php` *after* `addRoutingMiddleware()` (Slim runs middleware LIFO, so it executes before routing) strips the trailing slash and redirects: 301 for GET/HEAD, 308 for anything else so the method and body survive. The app root (`/` or `/cvm.kmr/`) is left alone.

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
- **`app/AI/`** — `AiProvider` interface (`stream()` yields plain text chunks as they arrive), the real providers plus `SimulatedProvider`, `QuestionBank` (real vocational questions by subject, used by the simulator), `Prompt` (spec → Thai instruction) and `JsonStream` (text → JSON objects), `QuizGenerator` / `LessonPlanGenerator` (stream → structs), `AiRouter` → `AiRoute` (provider + source label + chip + wait + quota flag), `KeyCipher` (AES-256-GCM over `app.key`), `AiUnavailableException` (`reason` drives `_partials/ai-state.latte`).
- Lesson plans are stored as `ai_generations` rows (`target_type='lesson_plan'`, doc JSON in `payload`), not a dedicated table.

### SSE generation flow
Quiz/plan generation streams over `text/event-stream` (`QuizWizardController::stream`, `LessonPlanController::stream`): `ignore_user_abort(true)` + `set_time_limit(0)` so the job finishes and persists even if the browser closes; events are `meta` / `question`|`section` / `done` / `error`. The result is always saved server-side as a draft + a pending `ai_generations` row regardless of the client. Both controllers pass the teacher's `userQualityPref()` through to the provider, and both bail out with reason `empty` (rendered by `_partials/ai-state.latte`) rather than saving an empty draft when the model returns nothing parsable.

### Real AI providers vs. the simulator
`app/AI/HttpClient.php` (cURL + `curl_multi`, so `lines()` is a generator that yields as bytes arrive) backs three real providers: `GoogleAiProvider` (Gemini `streamGenerateContent?alt=sse`), `OpenAiCompatibleProvider` (`/chat/completions` with `stream:true` — OpenRouter or any base_url; it deliberately sends no `max_tokens`, because self-hosted servers reserve KV cache for it and answer ~20x slower), `OllamaProvider` (`/api/chat` NDJSON, `isAvailable()` probes `/api/tags` with a 2s timeout). `AiRouter::route(..., real: true)` builds those instead of `SimulatedProvider`, and every generation path passes it. `Prompt::userText()` turns the JSON spec into the actual instruction the model sees — the chat spec becomes its plain message, while `quiz` and `lesson_plan` become Thai prompts that spell out the exact NDJSON shape `QuizGenerator` / `LessonPlanGenerator` parse. Because small local models rarely obey "one JSON per line", both generators read the stream through `JsonStream::objects()`, which brace-counts its way through code fences, prose and pretty-printed JSON and yields each complete object as soon as it closes. HTTP failures become `AiUnavailableException` with a Thai message (`HttpClient::describeError`), reason `key_invalid` on 401/403. `SettingsController::probeKey()` is a real check: list models, then a one-token generate call (a key can list models yet be denied generation).

Teachers pick which source runs first at `POST /settings/ai/route` (`SettingsRepository::userRoutePref()`, key `ai_route:{userId}`, default `byok`); `AiRouter` tries the chosen source, then falls back to the other, keeping the first failure as the reason if both are out.

### Chat attachments
The chat input takes up to 8,000 characters (`AiChatController::MAX_MESSAGE`). Because `EventSource` can only issue GET requests and a Thai character costs 9 bytes once URL-encoded, the message is **not** put in the query string — that blew past Apache's 8,190-byte `LimitRequestLine` and failed with 414 at roughly 900 Thai characters. Instead `POST /ai/chat/prepare` stashes the message (plus attachment ids) in the session and returns an id, and `GET /ai/chat/stream?p=<id>` consumes it once (`pullPrompt()` deletes it after reading) and accepts one file at a time (up to 3 per question, 2 MB each) via `POST /ai/chat/attach` or drag-and-drop onto the panel. `App\Support\TextExtract` pulls plain text out of txt/md/csv/json/xml/html directly, out of .docx through `ZipArchive` + `word/document.xml`, and out of PDFs best-effort by inflating streams and reading text operators — a scanned PDF raises a Thai message telling the teacher to attach .docx/.txt instead. Extracted text (capped at 20,000 chars) lives in `$_SESSION['ai_chat_files']` keyed by a random id, newest 3 kept; the stream request passes `attach=<id,id>` and `attachedText()` prepends the file contents to the message before the session lock is released. This is what lets a teacher attach a course outline and say "สร้างรายวิชาตามไฟล์นี้".

### AI actions from the chat
The floating chat can act, not just talk: when a **teacher** asks it to create a course, the system prompt (`AiChatController::systemPrompt()`) tells the model to answer with a single `{"action":"create_course",…}` object. `stream()` sniffs the first non-blank characters — a leading `{` (after any ``` fence) switches it to action mode, so the JSON is never shown as chat text; `parseAction()` then emits an `action` SSE event and the panel renders a confirmation card. Nothing is written until the teacher presses the button, which POSTs to `/ai/chat/create-course` (teacher-only route, CSRF-checked, re-validates the code against `codeTaken()` and files the course in the current term) and logs `ai.chat.create_course`. Adding another action means extending the system prompt, `parseAction()`, and the card renderer.

### Floating AI chat
`_partials/ai-chat.latte` (included from `layout.latte` for teacher/admin only) is a floating assistant panel available on every page: `GET /ai/chat/status` returns the current `AiRoute` chip as JSON, `GET /ai/chat/stream` streams a plain-text reply as `meta` / `delta` / `done` / `error` SSE events. `AiChatController` calls `AiRouter::route(..., enforceQuota: false, real: true)` so it hits the teacher's actual API at their own quality preference — chatting never burns college quota, but it still writes `ai_usage_logs` + an `ai.chat.test` audit row. The stream calls `session_write_close()` after the CSRF check so it does not hold the session lock while streaming.

### Teacher-managed courses
Teachers create and edit their own courses: `GET /courses/new` and `GET /courses/{id}/edit` render `courses/edit.latte`, `POST /courses[/{id}]` runs `CourseController::save()` (shared by create and edit), `POST /courses/{id}/archive` sets `status=archived` — courses are never deleted because lessons, quizzes and attempts hang off them. `CourseRepository::codeTaken()` guards the `uk_course_term` unique key (code + term + classroom) with a Thai message instead of a SQL error; on any validation failure `save()` redirects back to the form it came from.

### Admin-managed central AI
`/admin/ai` is split into three server-rendered tabs via `?tab=overview|settings|quota` (`AiController::TABS`, invalid/missing values fall back to `overview`; the three `<a n:class="tab, ...">` links follow the same `?tab=` pattern as `courses/show.latte`, no client JS): **overview** — GPU/queue/7-day-chart cards plus the per-teacher usage table; **settings** — the endpoint connection form below; **quota** — the monthly cap picker. `saveEndpoint()`/`testEndpoint()` redirect back to `?tab=settings` and `saveCap()` to `?tab=quota` (via `redirect($response, $tab)`) so a save doesn't bounce the admin back to overview. The endpoint form's inline `<script>` already guards on `document.getElementById('endpointCard')` being null, so it's harmless to still include it in every tab's render even when that tab isn't `settings`.

`POST /admin/ai/endpoint` saves the shared endpoint (name, `kind` = `ollama` | `openai_compatible`, base_url, model, optional API key encrypted with `KeyCipher`, notes) and immediately probes it, writing `status` + `last_checked_at`; `POST /admin/ai/endpoint/test` probes without saving and returns the discovered model list as JSON, which fills the model `<select>` so the admin picks which model to enable (the page auto-probes on load and on the ↻ button; a saved model the endpoint no longer lists is kept in the list marked ไม่พบบนปลายทางแล้ว, and `__custom` + `model_custom` covers typing a name by hand). The test route only rewrites the stored `status` when the posted base_url **and** model both match what is saved, so loading the model list never flips a broken endpoint to online. Probing means `/api/tags` for Ollama and `OpenAiCompatibleProvider::probe()` otherwise. Leaving the key field blank keeps the stored key; `clear_key=1` removes it. Migration `000106_add_endpoint_kind` added `ai_endpoints.kind`.

### Theme colour
The green in `app.css` is only a default: `--brand`, `--brandInk`, `--brandSoft` and `--brandLine` are re-declared in a `<style>` block from whatever colour applies to the viewer. `App\Support\Palette` derives that whole set (light **and** dark variants) from one hex, and `SettingsRepository::uiPrimary($userId)` resolves it: the user's own `ui_primary:{userId}` first, otherwise the site-wide `ui_primary` that an admin set, otherwise `Palette::DEFAULT`. `/settings/appearance` (any logged-in role) offers ten presets plus a colour picker with live preview, Save for yourself, an admin-only "set as everyone's default", and a Reset that clears the personal override. Hex values are stored and emitted **without** `#` — the template writes the `#` itself, per the Latte CSS-escaping gotcha.

Coverage is site-wide except the public landing page, which intentionally has its own independent `--lp-*` palette (see below) and never references `--brand`. Two layers feed `$ui`: `bootstrap.php`'s `View::class` factory shares the **site-wide** default (`uiPrimary()` with no user id) so it reaches every render — including pre-login pages that never go through `ViewContext` (`auth/login.latte`, `auth/register.latte`, `error.latte`, each carrying their own copy of the `<style>` block since they're standalone documents, not `layout.latte` extenders); then `ViewContext` (authenticated group only) overwrites `$ui` with the signed-in user's own colour if they set one. Because `View::renderToString()` always merges the shared array, `$ui` is guaranteed to exist everywhere `layout.latte`'s `{if isset($ui)}` guard now always passes for logged-in pages, and the same guard in the three standalone templates covers guests.

### Public landing page
`/` serves `LandingController` — a guest-facing course showcase (`resources/views/landing.latte`, its own `public/assets/css/landing.css`, originally styled after the Colorlib "Education" template kept in `resources/design/course-template/`, gitignored, now redesigned with an AI-era look under `--lp-*` CSS variables independent of the app theme). Logged-in users are redirected to `/dashboard`. A teacher opts in **one course at a time** via the switch on that course's card on `/courses` (`POST /courses/{id}/landing` → `courses.show_on_landing`, default 0; ownership re-checked with `requireOwnedCourse()`).

Design notes from a round of feedback: the hero is a single centered column (no side-by-side "AI assistant" mockup card — that read as too dominant, and AI is now just a supporting mention across the page: one accent word in the H1, one of the four hero stats, one of the four feature cards) with two large near-invisible (5% opacity) decorative SVG icons for atmosphere. The stats bar is a single rounded card (`.lp-stats-bar-inner`) that overlaps the hero/section boundary by `-56px` instead of a hard color cut. Feature and course cards drop borders in favor of shadow-only elevation to avoid a "boxy" look. `.lp a:not(.lp-btn) { color: inherit }` exists specifically so the `.lp a` descendant-selector rule (specificity class+element) can't beat `.lp-btn-solid`/`.lp-btn-ghost-light`'s own `color` (specificity class-only) — without the `:not()`, button text silently loses its white color and becomes near-invisible against dark/colored backgrounds; this is a real bug class to watch for anywhere a page-wide `a { color }` rule coexists with button classes on `<a>` tags.

**Retail pilot spotlight**: the marketing/retail-trade department (การตลาด/ธุรกิจค้าปลีก) is the system's pilot for the AI rollout, so the hero copy is always framed around it. `LandingController::findPilotDepartment()` regex-matches `publicLanding()` group names against `ค้าปลีก|การตลาด|retail|marketing` and, when found, `pilotFirst()` sorts that group to the top and the view renders an amber "โครงการนำร่อง" ribbon on it. `$pilotDept` is null-safe everywhere (falls back to generic "การตลาด/ธุรกิจค้าปลีก" copy), so this works today with zero matching departments in the demo data and needs no schema change once a real retail department/course exists and is marked `show_on_landing`.

### Latte gotchas
- Interpolating a value that contains `#`, `(`, or `)` **inside** a `style="..."` attribute gets CSS-escaped to `\#` / `\(` (invalid CSS). Put the literal char in the template and interpolate only the safe part: `style="background:#{$c['color']}"` with `$c['color']` = `0E6B60` (no `#`), or use a CSS class (`class="gpu-bar-{$tone}"`). `var(--x)` written literally in the template is fine.
- `class="..."` and `n:class="..."` cannot both be on one element — merge into `n:class="base, cond ? extra"`.
- `n:class` does not do `{}` interpolation; use `class="prefix-{$x}"` or `n:class="'prefix-' . $x"`.
- In inline `<script>`, a `{` followed by a digit is parsed as a Latte tag and **silently removed** — `/^[0-9A-F]{6}$/` shipped to the browser as `/^[0-9A-F]6$/`. Avoid `{n}` quantifiers in templates (check the length separately) or move the script to a `.js` file.
- Anything toggled with `el.hidden` needs the global `[hidden]{display:none!important}` rule in `app.css` to beat class `display` (e.g. `.modal-backdrop{display:grid}`).

### Teacher self-registration
`GET /register` / `POST /register` → `RegisterController`. New teachers fill in username, email, full name, phone, subject area (`subject_area`), and institution (`institution`); accounts are inserted with `status='pending'` and cannot log in until an admin approves them at `/admin/users`. Migration `000107_add_teacher_registration` adds `pending` to the `users.status` enum and the two new columns. `Auth::registerTeacher()` hashes the password and inserts the row; `Auth::usernameTaken()` and `Auth::emailTaken()` guard uniqueness before insert. Password rules: ≥8 chars, must contain at least one letter and one digit.

### Demo data
`App\Install\DemoSeeder` (`/admin/demo`) `TRUNCATE`s the LMS/assessment/AI tables (so demo IDs stay stable) and reseeds departments, 6 teachers, 28 students, 4 courses, lessons, a published quiz with 22 graded attempts, pending review items, AI endpoint, quotas, and 7 days of usage logs.

## Conventions

- `declare(strict_types=1)` in every PHP file; `final` classes; constructor property promotion; readonly where possible.
- Controllers are `final`, constructor-injected via PHP-DI autowiring, return PSR-7 responses. Note the comment in `routes.php`: Slim route closures must **not** be `static`.
- Templates: Latte in `resources/views/`, `.latte`, extend `layout.latte` via `{block content}`. Thai UI text, `data-theme` dark-mode toggle stored in `localStorage`.
- Assets are plain CSS in `public/assets/css/app.css` — no bundler, no npm.
- Web root is `public/`; `config/` and `storage/` sit outside it, with root `.htaccess` rewriting everything into `public/`.
