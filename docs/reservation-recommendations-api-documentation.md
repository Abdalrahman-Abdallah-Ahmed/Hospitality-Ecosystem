# Recommendations API Documentation

This document describes the activity-recommendations API, defined by `App\Http\Controllers\RecommendationController` and gated by `App\Policies\RecommendationPolicy`.

It covers **four CRUD-style actions** (`index`, `show`, `update`, `destroy`) plus one **trigger action** (`generate`) that kicks off AI generation of new recommendations for a reservation's guest.

**There is no `store`/create endpoint.** Recommendations cannot be created directly through this API — the only way a new recommendation comes into existence is via `generate`, which queues an AI agent (`App\Ai\Agents\RecommendationAgent`) that decides what to recommend and writes the rows itself (through an internal tool, not this controller). If you send `POST /api/recommendation`, you'll get a `404` — that route doesn't exist.

## Base URL

All endpoints below are defined in `routes/api.php`, served under `/api`:

- `GET /api/recommendation`
- `GET /api/recommendation/{id}`
- `PUT /api/recommendation/{id}` (or `PATCH`)
- `DELETE /api/recommendation/{id}`
- `POST /api/reservation/{reservation}/recommendations` — the generation trigger. Note this one is nested under `/reservation`, not `/recommendation`, and sits outside the `Route::resource('/recommendation', ...)` block as a plain `Route::post`.

## Required Headers

Every request below needs all three:

```http
X-API-KEY: {your_api_key}
Authorization: Bearer {login_token}
Accept: application/json
Content-Type: application/json
```

- `X-API-KEY` is checked by the `api.key` middleware.
- `Authorization: Bearer {login_token}` is required because every route below is inside `auth:sanctum`. Get this token from `POST /api/login`.
- Without a valid bearer token, the API returns HTTP `401 Unauthenticated.` before any controller/policy logic runs.

## Who Can Call These Endpoints

Gated by `App\Policies\RecommendationPolicy`, which has a `before()` hook: **a user whose `role` is `super_admin` passes every check below, unconditionally.**

| Action | Rule (non-super-admin) |
| --- | --- |
| `index` | The user's `role` must be `admin`. |
| `show` / `update` / `destroy` | The user's `role` must be `admin`, **and** the recommendation's `hotel_id` must equal the hotel the user owns. |
| `generate` | Gated by `App\Policies\ReservationPolicy::view` instead (same rule as `GET /api/reservation/{id}`): `role` must be `admin`, and the *reservation's* `hotel_id` must equal the caller's own hotel. |

Practical implications for the UI:

- A `403`/`404` on `show`/`update`/`destroy`/`generate` should be treated as "not yours," same as elsewhere in this API.
- `index` is additionally query-scoped to `where('hotel_id', <caller's own hotel>)` — a regular admin's list can never contain another hotel's recommendations.

## Response Format

```json
{
  "message": "Some message",
  "code": 200,
  "body": {}
}
```

**Validation errors (`422`) are the one exception** — they use Laravel's default shape, not the `message/code/body` wrapper.

## The Recommendation Object

```json
{
  "id": "01a01134-5b9e-7336-a95f-eb1a4c5ce28e",
  "conversation_id": "35414a31-131c-4834-a843-5316542305c9",
  "reservation_id": "01a01133-2cfc-717b-bbb3-a61663b8b201",
  "activity_id": "01a00c93-47a1-70b6-8395-9bee9d767dd4",
  "hotel_id": "01a00c93-4565-73b6-9d97-5e9b1a57c20a",
  "reason": "With 1 child in the party during a long stay, Kids Club offers engaging activities for children.",
  "predicted_confidence": "0.90",
  "guest_confidence": null,
  "priority": 0,
  "status": "pending",
  "recommended_at": "2026-08-17T19:30:39.000000Z",
  "accepted_at": null,
  "rejected_at": null,
  "dismissed_at": null,
  "evidence_level": "L3",
  "evidence_sources": ["01a01133-2cfc-717b-bbb3-a61663b8b201", "01a00c93-47a1-70b6-8395-9bee9d767dd4"],
  "created_at": "2026-08-17T19:30:39.000000Z",
  "updated_at": "2026-08-17T19:30:39.000000Z",
  "hotel": { "id": "...", "name": "Grand Harbor Hotel", "...": "..." },
  "reservation": {
    "id": "...", "reservation_id": "RES-...", "adults": 2, "children": 1, "...": "...",
    "guest": { "id": "...", "first_name": "Youssef", "last_name": "Kamal", "...": "..." }
  },
  "activity": { "id": "...", "name": "Kids Club", "price": "25.00", "currency": "USD", "...": "..." }
}
```

Field notes for the UI:

- `id`, `conversation_id`, `reservation_id`, `activity_id`, `hotel_id` are UUID/string ids. `conversation_id` can be `null` — see below.
- `predicted_confidence` is the AI's own confidence when it made the recommendation (0.00–1.00, a decimal **string** like `reservation_value` elsewhere in this API — parse before doing math). `guest_confidence` is a separate field capturing how confident/interested the *guest* actually seemed, written by the AI concierge as it observes the guest's reaction in conversation — it's `null` until the guest has responded to this specific recommendation, and it is **not settable through this API** (see [3. Update a Recommendation](#3-update-a-recommendation)).
- `priority` is an integer set by the AI (0 = top recommendation for that guest, higher = lower priority) — not the same `Priority` enum (`low`/`normal`/`high`) used elsewhere in this app (e.g. tasks).
- `status` is one of `pending`, `sent`, `accepted`, `rejected`, `purchased`, `ignored`, `expired`, `cancelled` (`App\Enums\RecommendationStatus`). New recommendations from the AI agent always start `pending`. It only advances when the guest actually reacts during a WhatsApp conversation (the AI concierge sets it, alongside the matching `accepted_at`/`rejected_at`/`dismissed_at`) — **this API cannot set it**, so don't build an admin "mark as accepted" button against `update`.
- `hotel`, `reservation.guest`, and `activity` are eager-loaded on `index`/`show`/`update`; `reservation` itself is loaded specifically for its `guest` (i.e. `reservation.guest`), so other reservation fields like `room`/`adults`/`children` are also present on the nested object, but its own `hotel`/`room` relations are **not** further eager-loaded — don't expect `reservation.room` to be populated.
- `conversation_id` can be `null`. It's resolved (or a new conversation started) automatically server-side whenever `reservation_id` is set on `update` — you never send it directly (see below).
- `evidence_level` is always `L3` for a fresh recommendation — it's a prediction about a guest, a hypothesis until they act on it (`L1` observed → `L4` unverified is the full scale). `evidence_sources` holds the `[reservation_id, activity_id]` the recommendation was reasoned from. Neither field is settable through this API.

## 1. List Recommendations

### Endpoint

`GET /api/recommendation`

### Query Parameters

All optional, same generic behavior as every other list endpoint in this API:

| Param | Type | Example | Behavior |
| --- | --- | --- | --- |
| `filter[<column>]` | string, or array for multiple values | `filter[reservation_id]=019f...` | Exact match on any real `recommendations` column: `id`, `conversation_id`, `reservation_id`, `activity_id`, `hotel_id`, `reason`, `predicted_confidence`, `guest_confidence`, `priority`, `status`, `recommended_at`, `accepted_at`, `rejected_at`, `dismissed_at`, `created_at`, `updated_at`. |
| `search` | string | `search=snorkel` | Partial (`LIKE %term%`) match across string/text columns (mainly `reason`, `status`, and the id columns). |
| `sort` | string | `sort=-predicted_confidence` | Sort by a real column. Prefix with `-` for descending. |
| `page` | integer | `page=2` | Page number, 1-indexed. |
| `per_page` | integer, 1–100 | `per_page=25` | Page size. Defaults to 15. |

**Most useful filter for the UI:** `filter[reservation_id]=<id>` — to show "recommendations for this reservation" on a reservation detail screen, list with that filter after calling `generate` (see [5. Trigger Recommendation Generation](#5-trigger-recommendation-generation)).

An unknown `filter`/`sort` column returns `422` (same shape as other list endpoints — see [Reservations API Documentation](/D:/Hospitality%20Ecosystem/docs/reservations-api-documentation.md#error-unknown-filtersort-column)).

### Success Response

HTTP `200 OK`. `body` is a Laravel paginator object; `body.data` contains [recommendation objects](#the-recommendation-object).

## 2. Get a Single Recommendation

### Endpoint

`GET /api/recommendation/{id}`

### Success Response

HTTP `200 OK`. `body` is a [recommendation object](#the-recommendation-object).

### Error: Not Found / Wrong Hotel

`404` if the id doesn't exist (Laravel's default model-not-found response), `403` if it belongs to a different hotel (Laravel's default authorization-failure response) — neither uses the custom wrapper. Treat both as "not yours."

## 3. Update a Recommendation

### Endpoint

`PUT /api/recommendation/{id}` (or `PATCH`, same behavior)

### Request Body

Send only the fields you want to change — every field is optional:

```json
{
  "reason": "Updated: guest specifically asked about sunset timing.",
  "priority": 1
}
```

### Validation Rules

Rules are derived automatically from the table schema (same mechanism used by every generic CRUD controller in this app), all optional on update:

| Field | Rules |
| --- | --- |
| `reservation_id` | optional, uuid, must exist in `reservations.id`, and must belong to your hotel. |
| `activity_id` | optional, uuid, must exist in `activities.id`, and must belong to your hotel. |
| `reason` | optional, string. |
| `predicted_confidence` | optional, numeric. |
| `priority` | optional, integer. |
| `recommended_at` | optional, date. |

**`status`, `guest_confidence`, `accepted_at`, `rejected_at`, and `dismissed_at` are silently ignored, even though they're real, fillable columns.** These fields represent the *guest's own response* to the recommendation, captured live by the AI concierge (`App\Ai\Tools\UpdateRecommendationTool`) during the WhatsApp conversation — not something an admin edits after the fact. Sending them in the request body does nothing; they're stripped before the update is applied. If you need to see the guest's reaction, read them via `show`/`index` — this endpoint just can't set them. (If staff need to record their own follow-up action, e.g. "called the guest, they booked over the phone," that belongs in the separate `recommendation_outcomes` table, which isn't exposed by this API yet.)

**`hotel_id` and `conversation_id` in the request body are also silently ignored** — you cannot move a recommendation to a different hotel, and `conversation_id` is always server-derived:

- `hotel_id` is always reset to your own hotel.
- If you include `reservation_id` in the payload (changing which reservation this recommendation is about), `conversation_id` is automatically re-resolved: the backend finds that reservation's existing open conversation, or starts a new one, the same logic the AI agent itself uses. You never set `conversation_id` directly.

### Success Response

HTTP `200 OK`. `body` is the updated [recommendation object](#the-recommendation-object).

### Error: Reservation/Activity From a Different Hotel

Custom `message/code/body` wrapper, HTTP `403`:

```json
{
  "message": "The selected reservation does not belong to you.",
  "code": 403,
  "body": null
}
```

or `"The selected activity does not belong to you."` — same shape.

### Error: No Associated Hotel

HTTP `403`:

```json
{
  "message": "You do not belong to any hotel.",
  "code": 403,
  "body": null
}
```

## 4. Delete a Recommendation

### Endpoint

`DELETE /api/recommendation/{id}`

**This is a hard delete** — unlike `Reservation`/`Guest`/`Task`, the `Recommendation` model does not use `SoftDeletes`, so the row is permanently removed.

### Success Response

HTTP `200 OK`:

```json
{
  "message": "Recommendation deleted successfully.",
  "code": 200,
  "body": null
}
```

### Error: Not Found / Wrong Hotel

Same as [show](#2-get-a-single-recommendation).

## 5. Trigger Recommendation Generation

### Endpoint

`POST /api/reservation/{reservation}/recommendations`

This is the **only** way new recommendations get created. It's an asynchronous, fire-and-forget trigger — e.g. a "Recommend activities" button on a reservation's detail row.

### URL Parameters

| Param | Type | Notes |
| --- | --- | --- |
| `reservation` | string (UUID) | The reservation to generate recommendations for. Must belong to the caller's own hotel (checked via `ReservationPolicy::view`, not `RecommendationPolicy`). |

### Request Body

None.

### What Happens Server-Side

1. The reservation is authorized (`view` ability on `Reservation` — same-hotel admin, or super admin).
2. `App\Jobs\GenerateActivityRecommendationsJob` is dispatched with that reservation.
3. When the queue worker processes it, the job loads the reservation's guest and hotel, then runs `RecommendationAgent` scoped to that one guest. The agent looks at party composition (adults/children), room tier, reservation value, recent guest messages, the hotel's available activities, and the knowledge base (for eligibility/policy rules), then decides on up to 3 activities to recommend.
4. For each one, it creates a `Recommendation` row (`status: pending`) with a `reason` and `predicted_confidence`, resolving/starting a conversation for the reservation automatically.

### Success Response

HTTP `202 Accepted`. `body` is always empty — **this endpoint never returns recommendation data**, even though (unlike when this doc last described this endpoint) you can now fetch the results afterward — see [Reading the Results](#reading-the-results-after-generate) below.

```json
{
  "message": "Activity recommendations job has been initiated successfully.",
  "code": 202,
  "body": []
}
```

### Error: Not Found

HTTP `404` — Laravel's default model-not-found response:

```json
{ "message": "No query results for model [App\\Models\\Reservation] {id}" }
```

### Error: Belongs to a Different Hotel / Not Admin

HTTP `403` — Laravel's default authorization-failure response:

```json
{ "message": "This action is unauthorized." }
```

### Reading the Results After `generate`

1. There's still no job id, correlation id, or status field — you cannot ask "is it done yet?" directly.
2. Poll `GET /api/recommendation?filter[reservation_id]=<id>&sort=-recommended_at` after calling `generate`, until new rows appear (same polling pattern as `POST /api/ai-insights` — see [AI Insights API Documentation](/D:/Hospitality%20Ecosystem/docs/ai-insights-api-documentation.md#how-to-integrate-this-read-first)). Show a "generating…" state during this window; there's no push/webhook notification.
3. The agent can produce **0 to 3** recommendations per call. Don't hardcode an expectation of exactly 3 new rows.
4. If the queue worker isn't running, or the AI job throws, `POST` still returns `202` — queuing succeeded even if generation later fails silently. No new rows after a reasonable wait most likely means the job failed server-side (check `php artisan queue:failed` / worker logs).

## Example cURL Requests

### List (filtered to one reservation)

```bash
curl -X GET "http://your-domain.com/api/recommendation?filter[reservation_id]=019f9b37-c26b-703f-bd9b-2ebe9eb03a55&sort=-recommended_at" \
  -H "Accept: application/json" \
  -H "X-API-KEY: YOUR_API_KEY" \
  -H "Authorization: Bearer USER_LOGIN_TOKEN"
```

### Update (mark accepted)

```bash
curl -X PUT http://your-domain.com/api/recommendation/01a01134-5b9e-7336-a95f-eb1a4c5ce28e \
  -H "Accept: application/json" \
  -H "Content-Type: application/json" \
  -H "X-API-KEY: YOUR_API_KEY" \
  -H "Authorization: Bearer USER_LOGIN_TOKEN" \
  -d '{ "status": "accepted" }'
```

### Delete

```bash
curl -X DELETE http://your-domain.com/api/recommendation/01a01134-5b9e-7336-a95f-eb1a4c5ce28e \
  -H "Accept: application/json" \
  -H "X-API-KEY: YOUR_API_KEY" \
  -H "Authorization: Bearer USER_LOGIN_TOKEN"
```

### Trigger Generation

```bash
curl -X POST http://your-domain.com/api/reservation/019f9b37-c26b-703f-bd9b-2ebe9eb03a55/recommendations \
  -H "Accept: application/json" \
  -H "Content-Type: application/json" \
  -H "X-API-KEY: YOUR_API_KEY" \
  -H "Authorization: Bearer USER_LOGIN_TOKEN"
```

## Summary for the Frontend

- Five endpoints: `GET /api/recommendation` (list), `GET /api/recommendation/{id}` (show), `PUT /api/recommendation/{id}` (update), `DELETE /api/recommendation/{id}` (delete, hard delete), and `POST /api/reservation/{id}/recommendations` (trigger AI generation). Admin (same hotel) or super admin only.
- **There is no create endpoint.** Recommendations only ever come from the AI agent via `generate` — don't build a manual "add recommendation" form against this API.
- `generate` is fire-and-forget: `202` with an empty body, 0–3 new rows appear some time later. Poll `GET /api/recommendation?filter[reservation_id]=...` to pick them up — same pattern as the AI-insights endpoint.
- `predicted_confidence`/`reservation_value`-style decimals come back as **strings** — parse before doing math.
- `status`, `guest_confidence`, `accepted_at`, `rejected_at`, `dismissed_at` reflect the *guest's* own reaction, captured live by the AI concierge during the WhatsApp conversation — none of them are settable through `update`, even though they're returned on every read. Build any "guest response" UI as read-only.
- `hotel_id` and `conversation_id` are never client-settable on `update` — both are silently overwritten/re-derived server-side.
- Treat `403`/`404` the same as elsewhere in this API — "not yours"/"not found."
- Deleting a recommendation is a **hard delete** (no soft-delete/undo), unlike most other resources in this API.

## Related Docs

- [Reservations API Documentation](/D:/Hospitality%20Ecosystem/docs/reservations-api-documentation.md)
- [AI Insights API Documentation](/D:/Hospitality%20Ecosystem/docs/ai-insights-api-documentation.md) — the closest existing pattern for `generate`'s fire-and-forget shape.
