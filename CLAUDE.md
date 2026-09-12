# CLAUDE.md

Guidance for Claude Code (and any AI assistant) working in this repository.

## Project

Multi-tenant hospitality backend: hotels, guests, rooms, reservations, stays,
activities, bookings, transactions, staff tasks, a WhatsApp AI concierge, and
AI-generated insights/recommendations.

PHP 8.3 · Laravel 13 · PostgreSQL + `pgvector` · `laravel/ai` · Sanctum · Pest 4.
JSON API only — there is no Blade UI to maintain (`resources/` is a stock skeleton).

## Commands

```bash
composer dev                                    # serve + queue listener + vite
docker compose -f .postgres/compose.yaml up -d  # Postgres with pgvector (required)
php artisan migrate --seed
php artisan test                                # or: php artisan test --filter=SomeTest
./vendor/bin/pint                               # run before every commit
```

Tests run against a **real Postgres** database (`Hospitality_Ecosystem_testing`,
settings in `phpunit.xml`), never SQLite — pgvector columns and Postgres-specific
SQL are used. Start the container before running the suite.

## Architecture

### Tenancy — read this before touching any query

Every tenant-owned model uses `App\Concerns\BelongsToHotel`, which adds a global
`hotel` scope and stamps `hotel_id` on create. The scope is driven by
`App\Support\Tenancy\TenantContext`, populated per request by the `tenant`
middleware (`ResolveTenant`).

- `hotelIds() === null` means **unrestricted** (super admin, or no context at all).
- `hotelIds() === []` means **restricted to zero hotels** and must match nothing.
  Never collapse these two — `!== null` is the correct check, not truthiness.
- Code without an HTTP request (queue jobs, webhooks, schedulers) must opt in
  explicitly via `TenantContext::runForHotel()` or `TenantContext::withoutScope()`.
  Use the escape hatches deliberately; never to make a failing isolation test pass.
- `tests/TestCase.php` resets `TenantContext` between tests because it is
  process-static. Keep it that way.

### Request flow

```
api.key  →  auth:sanctum  →  tenant  →  (policy authorize)  →  controller
```

Middleware aliases are registered in `bootstrap/app.php`. Cross-account
super-admin reporting lives in `routes/admin.php`, which is registered in
`bootstrap/app.php` with the same stack **plus `super_admin`** and the
`api/admin` prefix — the guard is applied to the whole file on purpose, so a
new route there cannot be left unprotected. Do not put tenant-facing endpoints
in that file, and do not wrap its routes in their own group.

### Layers

| Directory | Role |
| --- | --- |
| `app/Http/Controllers` | Thin: authorize, validate, delegate, return `apiResponse()` |
| `app/Http/Requests` | Validation; `Generic/` holds the shared index/store/update rules |
| `app/Http/Resources` | Every response body goes through a Resource |
| `app/Services` | Business logic with more than one caller (bookings, transactions, stays, metering, AI cost) |
| `app/Jobs` | Queued/scheduled work; schedule entries live in `routes/console.php` |
| `app/Policies` | Per-model authorization, called via `$this->authorize(...)` |
| `app/Support` | Framework-adjacent helpers (tenancy, audit, knowledge, request rules) |
| `app/Enums` | Backed enums for every status/type/channel column — add one rather than a string literal |

### Conventions

- **Responses**: always `apiResponse(string $message, int $code, mixed $body)`
  from `app/Helpers/Helper.php`. It unwraps Resources and returns
  `{ message, code, body }`. Do not hand-roll `response()->json()` in controllers.
- **Other helpers**: `resolveHotel()` (scoped admin gets their own hotel; super
  admin must name one), `invalidRelation()` (reject cross-tenant foreign keys),
  `unsetAttributes()` (strip client-supplied `hotel_id` before writing).
- **Models**: UUID primary keys (`HasUuids`, `$keyType = 'string'`,
  `$incrementing = false`), `SoftDeletes`, explicit `$fillable` and `$casts`,
  plus `Filterable` where the model is listed by an index endpoint.
- **Index endpoints**: `GenericIndexRequest` + `GenericQuery::apply()` give
  filtering, search, sort (`-field` for desc) and pagination. Reuse it.
- **Migrations**: never edit a shipped migration — add a new one. Filenames in
  this repo use a `YYYY_MM_DD_NNNNNN_description.php` sequence.
- **Style**: Pint (Laravel preset) is the only formatter. Run it before committing.
- The `.cursor/skills/laravel-best-practices/rules/` directory holds the detailed
  per-topic conventions (eloquent, queries, jobs, validation, security, testing…).
  Consult it when a decision isn't settled by the code around you.

## Testing

- Pest, feature tests in `tests/Feature`, one file per controller/service.
- `uses(RefreshDatabase::class)` per file; `tests/Pest.php` does **not** apply it globally.
- Set the API key in `beforeEach`: `putenv('API_KEY=test-api-key')` +
  `config(['app.api_key' => 'test-api-key'])`, then send `X-API-KEY` on requests.
- Shared fixtures go in file-local helper functions (see `TaskControllerTest.php`)
  or in `tests/Pest.php` when more than one file needs them.
- Only `UserFactory` exists; other models are built with `Model::create([...])`.
- Any new tenant-scoped feature needs a test proving another hotel cannot see it —
  `tests/Feature/TenantIsolationTest.php` is the pattern.

## Documentation

Every API change updates the matching `docs/<resource>-api-documentation.md`.
Breaking changes are additionally summarized in `docs/latest-changes-<date>.md`.
Phase/work-package plans live in `docs/PGRIP_Ecosystem_Phase2_Implementation_Plan_v1_0.md`.

## Git

- Do **not** add yourself as a co-author. No `Co-Authored-By:` trailer, no
  "Generated with Claude Code" line, no 🤖 attribution in commit messages or
  pull request descriptions. Commits are authored by the repository owner only.
- Commit or push only when asked.
- Conventional-ish subject lines (`feat:`, `fix:`, `refactor:`) matching the
  existing history.
- Run `./vendor/bin/pint` and `php artisan test` before proposing a commit.

## Secrets

`.env` and `phpunit.xml` contain local credentials — never copy their values into
code, docs, commit messages, or anything leaving the machine. `API_KEY`,
`WHATSAPP_VERIFY_TOKEN`, and `WHATSAPP_APP_SECRET` are required for the API and
webhook to function; read them through `config()`, never `env()` outside config files.
