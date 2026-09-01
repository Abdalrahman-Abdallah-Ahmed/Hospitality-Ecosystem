# AI Insights API Documentation

This document describes the AI-insights API, defined by `App\Http\Controllers\AiInsightsController` and gated by `App\Policies\AiInsightsPolicy`.

It covers **only two actions**: `index` (list insights) and `store` (kick off AI generation of new insights). There is no `show`, `update`, or `destroy` — insights are read-only once created, and are only ever produced by the AI job, never edited directly by a client.

**This is an asynchronous, fire-and-forget endpoint.** `POST /api/ai-insights` does not return any insight data — it queues a background job (`App\Jobs\CreateAiInsightsJob`) that calls an AI agent and, some time later, writes up to 5 new rows into the `ai_insights` table. See [How to Integrate This (Read First)](#how-to-integrate-this-read-first) before building the UI.

## Base URL

All endpoints below are defined in `routes/api.php`, served under:

`/api`

- `GET /api/ai-insights`
- `POST /api/ai-insights`

## Required Headers

Every request below needs all three:

```http
X-API-KEY: {your_api_key}
Authorization: Bearer {login_token}
Accept: application/json
Content-Type: application/json
```

- `X-API-KEY` is checked by the `api.key` middleware.
- `Authorization: Bearer {login_token}` is required because both routes are inside `auth:sanctum`. Get this token from `POST /api/login`.
- Without a valid bearer token, the API returns HTTP `401 Unauthenticated.` before any controller/policy logic runs.

## Who Can Call These Endpoints

Gated by `App\Policies\AiInsightsPolicy`. The policy has a `before()` hook: **a user whose `role` is `super_admin` passes every check below, unconditionally.**

| Action | Rule (non-super-admin) |
| --- | --- |
| `index` (list) | The user's `role` must be `admin`. |
| `store` (generate) | The user's `role` must be `admin`. |

Anyone who is not `admin` or `super_admin` (i.e. `employee`) gets HTTP `403` on both routes — treat as "shouldn't be on this page."

`index` additionally scopes the query to `where('hotel_id', <caller's own hotel>)` — a regular admin only ever sees their own hotel's insights. **Caveat:** unlike some other list endpoints in this app, this uses `$request->user()->hotel?->id`, which resolves to `null` for a super admin (who typically has no owned hotel) or for an admin with no hotel — in that case the query becomes `where('hotel_id', null)` and returns an **empty list**, not "everything" or an error. Don't assume a `200` with an empty array means "no insights exist" for a super admin.

## Response Format

Successful custom API responses use this structure:

```json
{
  "message": "Some message",
  "code": 200,
  "body": {}
}
```

**Validation errors (`422`) are the one exception** — they use Laravel's default shape, not the `message/code/body` wrapper.

## The AI Insight Object

```json
{
  "id": "019fc000-4444-7000-9000-abcdef123456",
  "hotel_id": "019f9b37-c265-726d-a6fe-f7eaa7852636",
  "insightable_type": "App\\Models\\Reservation",
  "insightable_id": "019fabcd-5678-7000-9000-123456789abc",
  "title": "3 reservations arriving today with no room assigned",
  "description": "Rooms have not yet been assigned for 3 confirmed check-ins scheduled for today.",
  "category": "reservation",
  "insight_type": "general",
  "evidence_level": "L2",
  "evidence_sources": ["019fabcd-5678-7000-9000-123456789abc"],
  "created_at": "2026-08-08T10:00:00.000000Z",
  "updated_at": "2026-08-08T10:00:00.000000Z"
}
```

Field notes for the UI:

- `id` and `hotel_id` are UUID strings.
- `category` is one of `reservation`, `task`, `guest_message`, `general` (`App\Enums\AiInsightCategories`) — this is what the AI tagged the insight as, and is the field to drive an icon/badge, **not** `insightable_type`.
- `insight_type` is one of `general`, `reservation`, `task` (`App\Enums\InsightTypes`) — this is which *request* produced the insight (what the caller asked for in `POST`'s `insight_type` field), and defaults to `general` if the request omitted it.
- `insightable_type` / `insightable_id` are a **polymorphic link** to the specific record the insight is about (a `Reservation`, `Task`, or `Message`), or both `null` if the insight is general / not tied to one record, or if the AI-supplied source id didn't actually validate against the hotel's own data (see [Sourcing Caveats](#sourcing-caveats)). `insightable_type` is the raw PHP class name (e.g. `"App\\Models\\Reservation"`) — there is no morph map — so don't try to display it directly; branch on `category` instead if you need a type label.
- **Neither `insightable_type`/`insightable_id` are resolved to the actual related record.** `index` does not eager-load the `insightable` relation — you only get the raw id/type pair. If you want to deep-link "view this reservation," resolve it client-side against data you already have (or a separate `GET /api/reservation/{id}` call), the same pattern used elsewhere in this API for non-eager-loaded relations.
- No soft deletes — there is currently no delete endpoint at all, so insights accumulate indefinitely; there's no way to dismiss/archive one through this API yet.
- `evidence_level` is one of `L1` (observed fact), `L2` (strong inference from data), `L3` (hypothesis), `L4` (unverified). An insight tied to a **verified** hotel record (`insightable_id` set) is saved as `L2`; anything else defaults to `L3`. Treat `L3` output as "the AI's opinion", not established fact — the UI should not present it with the same weight as an `L1` figure.
- `evidence_sources` is an array of the record ids the claim rests on (usually just `[insightable_id]`), or `null` when the insight isn't grounded in a specific record.

### Sourcing Caveats

The AI agent is prompted to tag each insight with a `source_id` (the real id of the reservation/task/message it used), but this is model output and can be wrong or missing. The backend re-verifies that the `source_id` actually belongs to the caller's hotel before saving it as `insightable_id`; if it doesn't (or the category is something unexpected), the insight is still saved, just with `insightable_type`/`insightable_id` both `null`. **Don't assume every `reservation`/`task`/`guest_message`-category insight has a working deep link** — always null-check before rendering a "view record" action.

## How to Integrate This (Read First)

1. `POST /api/ai-insights` only queues a background job. The `201` response body is always empty (`body: []`) — **it never contains the generated insights.**
2. There is no job id, correlation id, or status field returned anywhere. You cannot ask "is my job done yet?" directly.
3. To actually show the new insights, poll `GET /api/ai-insights` (e.g. `sort=-created_at`) after calling `store`, until you see new rows. A reasonable approach:
   - Note the timestamp (or the latest existing insight's `id`/`created_at`) right before calling `POST`.
   - After `POST` succeeds, poll `GET /api/ai-insights?sort=-created_at&per_page=5` every few seconds for a bounded window (e.g. up to ~30–60s), and treat any row with `created_at` after your noted timestamp as newly generated.
   - Show a loading/"generating insights…" state in the UI during this window; there is currently no push/webhook notification when the job finishes.
4. The job can produce **0 to 5** insights per call — the AI agent is instructed to return "up to 5," and any individual insight can additionally be dropped from having a usable link (never dropped entirely — see [Sourcing Caveats](#sourcing-caveats)). Don't hardcode an expectation of exactly 5 new rows per `store` call.
5. If the queue worker isn't running, or the job throws (bad AI response shape, an unexpected `category` value, etc.), `POST` still returns `201` — **queuing succeeded even if generation later fails.** There's no user-facing error surfaced for a failed job as of this writing; a `store` call that never produces new rows after a reasonable wait most likely means the job failed server-side (check `php artisan queue:failed` / worker logs), not that the AI genuinely found nothing to report.

## 1. List Insights

### Endpoint

`GET /api/ai-insights`

### Query Parameters

All optional, same generic behavior as other list endpoints in this API:

| Param | Type | Example | Behavior |
| --- | --- | --- | --- |
| `filter[<column>]` | string, or array for multiple values | `filter[category]=reservation` | Exact match on any real `ai_insights` column (`hotel_id`, `insightable_type`, `insightable_id`, `title`, `category`, `insight_type`, `created_at`, `updated_at`). |
| `search` | string | `search=housekeeping` | Partial (`LIKE %term%`) match across the table's string/text columns. |
| `sort` | string | `sort=-created_at` | Sort by a real column. Prefix with `-` for descending. |
| `page` | integer | `page=2` | Page number, 1-indexed. |
| `per_page` | integer, 1–100 | `per_page=25` | Page size. Defaults to 15. |

An unknown `filter`/`sort` column returns `422` (same shape as [Error: Unknown Filter/Sort Column](#error-unknown-filtersort-column) below).

Useful filters for a dashboard: `filter[category]=task`, `filter[insight_type]=reservation`.

### Success Response

HTTP `200 OK`. `body` is a Laravel paginator object; `body.data` contains [AI insight objects](#the-ai-insight-object).

### Error: Unknown Filter/Sort Column

HTTP `422`:

```json
{
  "message": "The given data was invalid.",
  "errors": {
    "filter.not_a_column": ["Unknown filter column [not_a_column]."]
  }
}
```

## 2. Generate Insights

### Endpoint

`POST /api/ai-insights`

### Request Body

```json
{
  "insight_type": "reservation"
}
```

### Validation Rules

| Field | Rules |
| --- | --- |
| `insight_type` | optional, string, must be one of `general`, `reservation`, `task` (`App\Enums\InsightTypes`). Defaults to `general` if omitted. |

What each `insight_type` actually asks the AI agent for:

| `insight_type` | Prompt focus |
| --- | --- |
| `general` (default) | Up to 5 actionable insights across overall hotel operations — today's reservations, open tasks, and recent guest messages. |
| `reservation` | Insights about today's reservations specifically. |
| `task` | Insights about tasks specifically. |

**Important — the calling user must have an associated hotel:** if the logged-in user has no hotel, the request is rejected before any job is queued (see [Error: No Associated Hotel](#error-no-associated-hotel)).

**Note on request typing:** this endpoint is validated by the same generic request class used for `index` (`GenericIndexRequest`), so it technically also accepts `search`/`sort`/`filter`/`page`/`per_page` fields without a `422` — they're simply ignored by `store`. Don't rely on this; only send `insight_type`.

### Success Response

HTTP `201 Created`. `body` is always an empty array — **no insight data is returned synchronously.** See [How to Integrate This](#how-to-integrate-this-read-first).

```json
{
  "message": "AI insights job has been initiated successfully.",
  "code": 201,
  "body": []
}
```

### Error: No Associated Hotel

Custom `message/code/body` wrapper:

HTTP `403`:

```json
{
  "message": "You do not belong to any hotel.",
  "code": 403,
  "body": null
}
```

### Error: Invalid `insight_type`

HTTP `422`:

```json
{
  "message": "The given data was invalid.",
  "errors": {
    "insight_type": ["The selected insight type is invalid."]
  }
}
```

### Error: Non-Admin

HTTP `403`, Laravel's default authorization-failure shape:

```json
{ "message": "This action is unauthorized." }
```

## Example cURL Requests

### List

```bash
curl -X GET "http://your-domain.com/api/ai-insights?sort=-created_at&per_page=10" \
  -H "Accept: application/json" \
  -H "X-API-KEY: YOUR_API_KEY" \
  -H "Authorization: Bearer USER_LOGIN_TOKEN"
```

### Generate

```bash
curl -X POST http://your-domain.com/api/ai-insights \
  -H "Accept: application/json" \
  -H "Content-Type: application/json" \
  -H "X-API-KEY: YOUR_API_KEY" \
  -H "Authorization: Bearer USER_LOGIN_TOKEN" \
  -d '{ "insight_type": "reservation" }'
```

## Summary for the Frontend

- Two endpoints only: `GET /api/ai-insights` (list) and `POST /api/ai-insights` (trigger generation). Both admin/super-admin only.
- **`POST` is fire-and-forget.** It queues a job and returns `201` with an empty body immediately — it does **not** return the generated insights. Poll `GET /api/ai-insights` afterward to pick up new rows; there's no job status, correlation id, or webhook to know when generation finished or failed.
- A single `POST` call can add anywhere from **0 to 5** new rows. Don't assume a fixed count.
- `category` (what the insight is about: `reservation`/`task`/`guest_message`/`general`) and `insight_type` (what the `POST` request asked for) are two different fields — use `category` for any per-insight icon/label.
- `insightable_type`/`insightable_id` give you a link to the source record, but are frequently `null` (general insights, or when the AI's claimed source id didn't check out) — always null-check before offering a "view record" action, and note the related model itself is never eager-loaded, only the raw id/type.
- `index` is hotel-scoped for regular admins; a super admin with no owned hotel currently gets an **empty list**, not "every hotel's insights" — don't build a cross-hotel insights view against this endpoint as-is.
- There is no delete/dismiss endpoint yet — insights accumulate; plan the UI (e.g. "seen" state, client-side dismissal) accordingly if that matters for this release.

## Related Docs

- [User Management API Documentation](/D:/Hospitality%20Ecosystem/docs/user-management-api-documentation.md)
- [Task Management API Documentation](/D:/Hospitality%20Ecosystem/docs/task-management-api-documentation.md)
- [Reservations API Documentation](/D:/Hospitality%20Ecosystem/docs/reservations-api-documentation.md)
