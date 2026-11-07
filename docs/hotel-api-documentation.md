# Hotel API Documentation

This document describes the hotel management APIs, defined by `App\Http\Controllers\HotelController` and gated by `App\Policies\HotelPolicy`.

It covers `index`, `show` and `update`. Creating and deleting a hotel moved to the super admin API on 2026-11-06: see [superadmin-accounts-api-documentation.md](superadmin-accounts-api-documentation.md). `POST /api/hotel` and `DELETE /api/hotel/{id}` now return `405` for everyone.

## Important — This Is Not Where Most Hotels Get Created

When a new hotel owner signs up, the hotel is created as part of **`POST /api/register`** (see `docs/register-api-update.md`, which supersedes the registration section in `docs/auth-api-documentation.md`), in the same transaction as the user account. That endpoint is open to any admin registering themselves plus their hotel in one call.

A super admin onboards a customer, or restores a deleted hotel, with `POST /api/superadmin/accounts/{account}/hotels`. A normal "sign up and create my hotel" flow should use `/api/register`.

## Base URL

All endpoints below are defined in `routes/api.php`, so they are served under:

`/api`

Examples:

- `GET /api/hotel`
- `GET /api/hotel/{id}`
- `PUT /api/hotel/{id}`

## Required Headers

Every request below needs all three:

```http
X-API-KEY: {your_api_key}
Authorization: Bearer {login_token}
Accept: application/json
Content-Type: application/json
```

Notes:

- `X-API-KEY` is checked by the `api.key` middleware against the server's configured `API_KEY`; a missing/wrong key returns HTTP `401`. The check is skipped only when no key is configured **and** the server runs in a `local` or `testing` environment — anywhere else, an unset key rejects every request.
- `Authorization: Bearer {login_token}` is required because every hotel route is inside the `auth:sanctum` middleware group. Get this token from `POST /api/login`.
- Without a valid bearer token, the API returns HTTP `401 Unauthenticated.` before controller/policy logic ever runs.

## Who Can Call These Endpoints

Every action is gated by `App\Policies\HotelPolicy`. The policy has a `before()` hook: **a user whose `role` is `super_admin` passes every check below, unconditionally** — the per-action rules only apply to non-super-admins.

| Action | Rule (non-super-admin) | Super admin |
| --- | --- | --- |
| `index` (list) | Always denied. | Allowed — sees **every** hotel, not scoped to any owner. |
| `show` | The user must be `admin`, **and** the hotel's `owner_id` must equal the user's own id. | Allowed for any hotel. |
| `update` | Same as `show`: must be the owning `admin`. | Allowed for any hotel, regardless of owner. |

Practical implications for the UI:

- **`index` is a super-admin-only screen.** A regular admin should never see a "list all hotels" or "create a hotel" screen — they manage their own hotel via `show`/`update` on the hotel they already own (fetched from their own user/hotel context, e.g. `GET /api/user`), and got that hotel via `/api/register` in the first place.
- `show`/`update` for a regular admin: a `403` on their own hotel id should not normally happen; a `403` on someone else's hotel id means "not yours" — treat it like a 404.
- A super admin's UI can freely browse and edit any hotel; there is no ownership restriction for that role anywhere in this resource.
- **A soft-deleted hotel can't be reached by its staff.** It drops out of what a user can reach, by default or with `X-Hotel-Id`. Links and tokens are kept, so access returns if the hotel is restored.

## Response Format

Successful custom API responses use this structure:

```json
{
  "message": "Some message",
  "code": 200,
  "body": {}
}
```

- `message`: human-readable message, safe to show in a toast.
- `code`: repeats the HTTP status code.
- `body`: the actual payload (a hotel object, a paginated list, or `null`).

**Validation errors (`422`) are the one exception** — they use Laravel's default shape, not the `message/code/body` wrapper (see [Validation Errors](#validation-errors)).

## The Hotel Object

```json
{
  "id": "019f9b37-c265-726d-a6fe-f7eaa7852636",
  "owner_id": "019f9b37-c260-7abc-9000-1234567890ab",
  "name": "Grand Harbor Hotel",
  "slug": "grand-harbor-hotel",
  "timezone": "Africa/Cairo",
  "currency": "USD",
  "country_code": "EG",
  "city": "Alexandria",
  "address": "12 Corniche Road",
  "whatsapp_number": "201000000001",
  "email": "reservations@grandharbor.test",
  "phone": "+201000000001",
  "branding": { "primary_color": "#0f172a" },
  "ai_preferences": { "tone": "friendly", "language": "en" },
  "is_active": true,
  "housekeeping_team_id": "uuid",
  "cleaning_task_category_id": "uuid",
  "inspection_task_category_id": "uuid",
  "maintenance_team_id": "uuid",
  "maintenance_task_category_id": "uuid",
  "inspection_required": false,
  "proactive_settings": {
    "enabled": false,
    "triggers": { "first_morning": true, "mid_stay": true, "upcoming_activity": true, "recommendation_approved": true },
    "quiet_hours": { "start": "21:00", "end": "09:00" },
    "daily_cap": 1,
    "milestone_time": "10:00",
    "reminder": { "morning_cutoff": "12:00", "evening_before_at": "18:00", "hours_before": 4 }
  },
  "created_at": "2026-07-25T21:39:10.000000Z",
  "updated_at": "2026-07-25T21:39:10.000000Z",
  "deleted_at": null
}
```

Field notes for the UI:

- `id` and `owner_id` are UUID strings, not integers.
- `branding` and `ai_preferences` are JSON object fields, returned as parsed JSON, not strings. The backend places no shape constraints on either — the frontend owns their structure.
- `email` is only validated as a plain string (max length), not with an email-format rule. Validate format client-side.
- `currency` is capped at 3 characters, `country_code` at 2 — these are not validated against ISO lists server-side; constrain input client-side (e.g. a currency/country picker).
- `is_active` defaults to `true` at the database level when omitted on create.
- **Operational defaults** (*2026-09-24, completed 2026-10-04*): where automatic
  housekeeping and maintenance tasks go.
  - `housekeeping_team_id`, `cleaning_task_category_id`, `inspection_task_category_id`:
    cleaning and inspection tasks (check-out, stay-over, re-clean, return to service).
  - `maintenance_team_id`, `maintenance_task_category_id`: maintenance tasks from room
    issues.
  - **Every hotel gets them on creation**: a *Housekeeping* team with *Cleaning* and
    *Inspection* categories and a *Maintenance* team with a *Maintenance* category (an
    existing team of that name is reused). Existing hotels were given them once. Admins can
    rename or replace them; a setting left `null`, or a team made inactive, means the
    automatic task is created unassigned and the admins are emailed once a day. See
    [task-management-api-documentation.md](task-management-api-documentation.md#4-housekeeping-and-maintenance).
- `inspection_required` (*added 2026-10-04*, default `false`): when `true`, a completed
  clean creates an inspection task and a room counts as ready only once inspected. Rooms
  cleaned before it was turned on still count as ready.
- `transactions_enabled` (*added 2026-10-30*, spec 019, read-only): whether the
  [transactions ledger](transaction-api-documentation.md#feature-flag--off-by-default) is on.
  It is a platform setting (`FEATURE_TRANSACTIONS`, off by default), the same for every hotel;
  sending it on create or update has no effect. The frontend hides the Transactions menu and
  settlement figures while it is `false`.
- `guest_memories_enabled` (*added 2026-10-10*, SPEC-084, default `true`): whether the
  hotel keeps [guest memories](guest-memories-api-documentation.md) — short notes about
  returning guests extracted after check-out — and whether the Concierge uses them. Turning it
  off stops new extraction and the Concierge's use; existing memories stay until deleted.
  Admin-only, like every hotel setting; a change is recorded as `hotel.guest_memories_toggled`.
- `proactive_settings` (*added 2026-10-10*, SPEC-073): whether the WhatsApp Concierge may
  message guests first, and when. Always returned in full, with every unset value at its
  default; **off by default**. All times are hotel-local `H:i`.
  - `triggers`: `first_morning` (the morning after check-in), `mid_stay` (the middle day of
    a stay of 4+ nights), `upcoming_activity` (a reminder of a confirmed booking),
    `recommendation_approved` (an offer as soon as a recommendation is approved for an
    in-house guest who has not been pitched yet).
  - `quiet_hours`: nothing is sent in this window; a message due then waits until it ends.
  - `daily_cap` (1–3): proactive messages per guest per hotel-local day.
  - `milestone_time`: when milestone messages are due.
  - `reminder`: activities starting before `morning_cutoff` are reminded the evening
    before at `evening_before_at`; later ones `hours_before` hours ahead.
  - Messages only go inside WhatsApp's 24-hour window — never as templates or email. See
    [proactive-messages-api-documentation.md](proactive-messages-api-documentation.md).
- Hotels use `SoftDeletes`: a super admin's delete does **not** permanently erase the row, and creating a hotel with the same `slug` in the same account restores it.
- No relations are eager-loaded on this object.

## 1. List Hotels

**Super admin only** — see [Who Can Call These Endpoints](#who-can-call-these-endpoints).

### Endpoint

`GET /api/hotel`

### Query Parameters

All optional:

| Param | Type | Example | Behavior |
| --- | --- | --- | --- |
| `filter[<column>]` | string, or array for multiple values | `filter[is_active]=1` | Exact match on any real `hotels` column. |
| `search` | string | `search=Harbor` | Partial (`LIKE %term%`) match across the hotel's string columns (`name`, `slug`, `city`, `email`, etc). |
| `sort` | string | `sort=-created_at` | Sort by a real column. Prefix with `-` for descending. |
| `page` | integer | `page=2` | Page number, 1-indexed. |
| `per_page` | integer, 1–100 | `per_page=25` | Page size. Defaults to 15. |

`filter`/`sort` are validated against the hotels table's real columns; an unknown key returns a `422`.

### Success Response

HTTP `200 OK`. `body` is a Laravel paginator object; `body.data` contains **every** hotel in the system (not scoped to any owner), each shaped as the [hotel object](#the-hotel-object).

### Error: Non-Super-Admin

HTTP `403`, Laravel's default authorization-failure shape (not the custom wrapper):

```json
{ "message": "This action is unauthorized." }
```

## 2. Get a Single Hotel

### Endpoint

`GET /api/hotel/{id}`

### Success Response

HTTP `200 OK`. `body` is a [hotel object](#the-hotel-object).

### Error: Not Found

HTTP `404` if the id doesn't exist at all:

```json
{ "message": "No query results for model [App\\Models\\Hotel] {id}" }
```

### Error: Not Yours (Non-Super-Admin)

HTTP `403` if the hotel exists but the caller is neither its owner nor a super admin — Laravel's default authorization-failure shape:

```json
{ "message": "This action is unauthorized." }
```

Treat this the same as a 404 in the UI for a regular admin.

## 3. Update a Hotel

### Endpoint

`PUT /api/hotel/{id}` (or `PATCH`, same behavior)

### Request Body

Send only the fields you want to change — every field is optional on update:

```json
{
  "name": "Grand Harbor Hotel & Spa",
  "is_active": false
}
```

### Validation Rules

Same field-level rules as [create](#validation-rules), except every field is optional (`sometimes` instead of `required`), and `slug` uniqueness ignores the hotel's own current row (so re-saving the same slug doesn't trip the unique check).

**Operational defaults.** The five team and category settings (uuid or `null`) must be
this hotel's own — another hotel's gives `403` "The selected teams does not belong to
you." (or `taskCategories`). Each team must be active (`422` "The housekeeping team must
be active." / "The maintenance team must be active."). The cleaning and inspection
categories must belong to the housekeeping team, the maintenance category to the
maintenance team (`422` "The cleaning task category must belong to the housekeeping
team.", and likewise). Choosing another housekeeping or maintenance team without sending
its inspection or maintenance category clears the one left in the old team; the cleaning
category must be sent with a new housekeeping team. On create these five fields are
ignored — the defaults are created with the hotel.

**`inspection_required`** (boolean) is admin-only like every hotel setting.

**`proactive_settings`** (object, admin-only, *2026-10-10*). Send only what changes; it is
merged over the stored settings, e.g. `{"proactive_settings": {"enabled": true}}` or
`{"proactive_settings": {"quiet_hours": {"start": "22:30"}}}`. `422` with the first error
for a bad time (`H:i`), `daily_cap` outside 1–3, `reminder.hours_before` outside 1–24, or an
unknown key (`Unknown proactive setting [send_at_midnight].`).

**`owner_id` and `hotel_group_id` are ignored**, for every caller, super admins included. Moving a hotel to another account goes through [`PUT /api/superadmin/hotels/{hotel}`](superadmin-accounts-api-documentation.md), and there is no API for transferring ownership directly.

**`slug`** is unique across every hotel, including soft-deleted ones: a slug a deleted hotel still holds returns `422`.

### Success Response

HTTP `200 OK`. `body` is the updated [hotel object](#the-hotel-object).

### Error: Not Found / Not Yours

Same as [show](#2-get-a-single-hotel): `404` if the id doesn't exist, `403` if the caller is neither the owner nor a super admin.

## Validation Errors

For any field-shape validation failure (missing required field, wrong type, failed `exists`/`unique` check), Laravel returns its default shape — **not** the custom `message/code/body` wrapper:

```json
{
  "message": "The given data was invalid.",
  "errors": {
    "field_name": ["Human-readable error message."]
  }
}
```

Always check for an `errors` key to distinguish this from the custom-wrapper business-rule errors documented above, which can also carry a `422` or `403` status without that key.

## Example cURL Requests

### List (super admin)

```bash
curl -X GET "http://your-domain.com/api/hotel?search=Harbor&sort=-created_at&per_page=20" \
  -H "Accept: application/json" \
  -H "X-API-KEY: YOUR_API_KEY" \
  -H "Authorization: Bearer SUPER_ADMIN_LOGIN_TOKEN"
```

### Update (owning admin or super admin)

```bash
curl -X PUT http://your-domain.com/api/hotel/019f9b37-c265-726d-a6fe-f7eaa7852636 \
  -H "Accept: application/json" \
  -H "Content-Type: application/json" \
  -H "X-API-KEY: YOUR_API_KEY" \
  -H "Authorization: Bearer USER_LOGIN_TOKEN" \
  -d '{ "is_active": false }'
```

## Summary for the Frontend

- Most hotels are created via `/api/register`, not this resource. Build a "manage my hotel" screen (`show`/`update` on the admin's own hotel) for regular admins. Super admin creation and deletion use `/api/superadmin`.
- Every request needs `X-API-KEY` and `Authorization: Bearer {login_token}`.
- `index` is super-admin-only; `show`/`update` work for the owning admin **or** a super admin.
- List with `GET /api/hotel`, filter with `filter[column]=value`, free-text search with `search=`, sort with `sort=column`/`sort=-column`, paginate with `page`/`per_page`.
- `owner_id` and `hotel_group_id` are ignored on update, so leave them out of edit forms.
- `email`, `currency`, `country_code` are only length/type validated server-side — enforce format client-side.
- `POST` and `DELETE` on these addresses return `405`.
