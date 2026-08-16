# Activity API Documentation

This document describes the hotel **activity** and **activity category** management APIs — the admin-facing CRUD endpoints (`index`, `store`, `show`, `update`, `destroy`) defined by `App\Http\Controllers\ActivityController` / `App\Policies\ActivityPolicy` and `App\Http\Controllers\ActivityCategoryController` / `App\Policies\ActivityCategoryPolicy`.

An activity is something a hotel sells to guests beyond the room itself (spa treatment, airport transfer, breakfast buffet, etc.), optionally grouped under an activity category (e.g. "Wellness", "Transport"). Build the category picker first — an activity's `category_id` references it — but categories aren't required; an activity can exist with none.

## Base URL

All endpoints below are defined in `routes/api.php`, served under:

`/api`

Examples:

- `GET /api/activity`, `POST /api/activity`, `GET /api/activity/{id}`, `PUT /api/activity/{id}`, `DELETE /api/activity/{id}`
- `GET /api/activity-category`, `POST /api/activity-category`, `GET /api/activity-category/{id}`, `PUT /api/activity-category/{id}`, `DELETE /api/activity-category/{id}`

## Required Headers

Every request below needs all three:

```http
X-API-KEY: {your_api_key}
Authorization: Bearer {login_token}
Accept: application/json
Content-Type: application/json
```

- `X-API-KEY` is checked by the `api.key` middleware.
- `Authorization: Bearer {login_token}` is required because every activity route is inside `auth:sanctum`. Get this token from `POST /api/login` (see `docs/auth-api-documentation.md`) — the token is at `body.token` in that response.
- Without a valid bearer token, the API returns HTTP `401 Unauthenticated.` before any controller/policy logic runs.

## Who Can Call These Endpoints

Both resources follow the same shape: `App\Policies\ActivityPolicy` / `App\Policies\ActivityCategoryPolicy`, each with a `before()` hook that lets `super_admin` bypass every rule below unconditionally — but see [Super Admin Caveats](#super-admin-caveats), because several of these endpoints have a separate hotel check that isn't bypassed.

| Resource | Action | Rule (non-super-admin) |
| --- | --- | --- |
| Activity | `index`, `store` | `role` must be `admin`. |
| Activity | `show`, `update`, `destroy` | `role` must be `admin`, **and** the activity's `hotel_id` must equal the caller's own hotel. |
| Activity Category | `index`, `store` | `role` must be `admin`. |
| Activity Category | `show`, `update`, `destroy` | `role` must be `admin`, **and** the category's `hotel_id` must equal the caller's own hotel. |

Practical implications for the UI:

- A non-admin user (`employee`) should never reach either screen — treat any `403` here as "this user shouldn't be able to see this page," not a recoverable in-page error.
- A `403` on `show`/`update`/`destroy` for a specific id most likely means the id belongs to a different hotel (e.g. a stale link/bookmark) — show a "not found or not yours" style message rather than a raw permission error.
- Both `index` queries are separately scoped to `where('hotel_id', <the user's hotel>)`, so the list only ever contains rows for the user's own hotel.

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
- `body`: the actual payload (an activity object, a paginated list, or `null`).

**Validation errors (`422`) and Laravel's own authorization/not-found failures are exceptions to this** — they use Laravel's default shapes, not the `message/code/body` wrapper. See [Error Shapes](#error-shapes).

## The Activity Object

Every endpoint that returns an activity (`index`, `store`, `show`, `update`) eager-loads and embeds its **`category`** — you don't need a separate lookup to show the category name on an activity row/detail view. No other relation (e.g. `hotel`) is eager-loaded:

```json
{
  "id": "019fc000-4444-7000-9000-abcdef123456",
  "hotel_id": "019f9b37-c265-726d-a6fe-f7eaa7852636",
  "category_id": "019fabcd-1234-7000-9000-123456789abc",
  "name": "Airport Pickup",
  "description": "Private airport transfer for guests.",
  "price": "35.50",
  "currency": "USD",
  "is_active": true,
  "created_at": "2026-08-01T10:00:00.000000Z",
  "updated_at": "2026-08-01T10:00:00.000000Z",
  "deleted_at": null,
  "category": {
    "id": "019fabcd-1234-7000-9000-123456789abc",
    "hotel_id": "019f9b37-c265-726d-a6fe-f7eaa7852636",
    "name": "Wellness & Spa",
    "slug": "wellness-spa",
    "description": "Massages, spa treatments, and relaxation sessions.",
    "created_at": "2026-08-01T10:00:00.000000Z",
    "updated_at": "2026-08-01T10:00:00.000000Z"
  }
}
```

Field notes for the UI:

- `id`, `hotel_id`, and `category_id` are UUID strings, not integers.
- `price` is cast as `decimal:2` — it comes back as a **string** (e.g. `"35.50"`), not a JSON number. Parse it before doing math on it.
- `currency` is a free-text 3-character string, not a restricted enum server-side — the frontend should constrain it to real currency codes.
- **Activities use `SoftDeletes`** — `DELETE` sets `deleted_at`, it does not remove the row (unlike rooms, which hard-delete). A deleted activity simply stops showing up in `index`/`show`.
- `category_id` is nullable — an activity can exist with no category, in which case **`category` comes back as `null`**, not an object. Always null-check `activity.category` before reading `activity.category.name`. Populate the category picker itself from [`GET /api/activity-category`](#activity-category-api) (this embedded object is read-only convenience, not a substitute for the picker list).

## 1. List Activities

### Endpoint

`GET /api/activity`

### Query Parameters

All optional, same generic behavior as every other list endpoint in this API:

| Param | Type | Example | Behavior |
| --- | --- | --- | --- |
| `filter[<column>]` | string, or array for multiple values | `filter[is_active]=1` | Exact match on any real `activities` column. `filter[category_id][]=a&filter[category_id][]=b` matches either. |
| `search` | string | `search=airport` | Partial (`LIKE %term%`) match across the activity's string/text columns: `name`, `description`, `currency`. (`hotel_id`/`category_id` are UUID columns, not string/text, so they're excluded from free-text search — use `filter[category_id]=` for an exact match instead.) |
| `sort` | string | `sort=-created_at` | Sort by a real column. Prefix with `-` for descending. |
| `page` | integer | `page=2` | Page number, 1-indexed. |
| `per_page` | integer, 1–100 | `per_page=25` | Page size. Defaults to 15. |

`filter`/`sort` are validated against the activities table's real columns: `id`, `hotel_id`, `category_id`, `name`, `description`, `price`, `currency`, `is_active`, `created_at`, `updated_at`, `deleted_at`. An unknown key in either returns a `422`.

### Example Request

```
GET /api/activity?filter[is_active]=1&search=airport&sort=-created_at&per_page=20&page=1
```

### Success Response

HTTP `200 OK`. `body` is a Laravel paginator object:

```json
{
  "message": "Activities fetched successfully.",
  "code": 200,
  "body": {
    "current_page": 1,
    "data": [
      { "...": "one activity object, shape as above" }
    ],
    "first_page_url": "http://your-domain.com/api/activity?page=1",
    "from": 1,
    "last_page": 1,
    "last_page_url": "http://your-domain.com/api/activity?page=1",
    "links": [ { "url": null, "label": "&laquo; Previous", "page": null, "active": false } ],
    "next_page_url": null,
    "path": "http://your-domain.com/api/activity",
    "per_page": 15,
    "prev_page_url": null,
    "to": 1,
    "total": 1
  }
}
```

For the UI: read the list from `body.data`, and drive pagination controls from `body.current_page`, `body.last_page`, and `body.total`.

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

## 2. Create an Activity

### Endpoint

`POST /api/activity`

### Request Body

```json
{
  "hotel_id": "any-valid-hotel-uuid",
  "category_id": "019fabcd-1234-7000-9000-123456789abc",
  "name": "Airport Pickup",
  "description": "Private airport transfer for guests.",
  "price": 35.5,
  "currency": "USD",
  "is_active": true
}
```

### Validation Rules

| Field | Rules |
| --- | --- |
| `hotel_id` | **Required by validation** (`exists:hotels,id`) but **always ignored** — the backend overwrites it with the caller's own hotel before saving. You must still send *some* valid hotel id or you'll get a `422` for a missing field; simplest is to send the caller's own `hotel_id`. |
| `category_id` | optional, uuid, `exists:activity_categories,id`. If provided, **must belong to the caller's own hotel**, or you get `403` (see below). |
| `name` | required, string, max 255. No uniqueness constraint. |
| `description` | optional, string. |
| `price` | optional, numeric. Defaults to `0` at the database level if omitted. |
| `currency` | optional, string, max 3. Defaults to `USD` if omitted. |
| `is_active` | optional, boolean. Defaults to `true` if omitted. |

**Important — hotel scoping:** unlike `room`/`guest` (which reject a mismatched `hotel_id` with a `403`), `activity` follows the same pattern as `team`/`task-category`/`task`: whatever `hotel_id` you send is **discarded**, and the record is always created under the caller's own hotel. Auto-fill and hide this field rather than trying to validate it client-side.

### Error: Missing Required Fields

HTTP `422`:

```json
{
  "message": "The given data was invalid.",
  "errors": {
    "name": ["The name field is required."]
  }
}
```

### Error: No Associated Hotel

HTTP `403`, custom wrapper:

```json
{
  "message": "You do not belong to any hotel.",
  "code": 403,
  "body": null
}
```

### Error: Category Belongs to a Different Hotel

HTTP `403`, custom wrapper — note the message names the **relation**, not the field:

```json
{
  "message": "The selected activityCategories does not belong to you.",
  "code": 403,
  "body": null
}
```

Treat this as a form-level error on the category picker (don't try to map the string `"activityCategories"` to anything user-facing — just surface a generic "selected category isn't yours" message).

### Success Response

HTTP `201 Created`. `body` is an [activity object](#the-activity-object).

## 3. Get a Single Activity

### Endpoint

`GET /api/activity/{id}`

### Success Response

HTTP `200 OK`. `body` is an [activity object](#the-activity-object).

### Error: Not Found

HTTP `404` if the id doesn't exist at all — Laravel's default model-not-found response, not the custom wrapper:

```json
{ "message": "No query results for model [App\\Models\\Activity] {id}" }
```

### Error: Belongs to a Different Hotel

HTTP `403` if the activity exists but belongs to a different hotel than the logged-in admin's, or the caller isn't `admin` — Laravel's default authorization-failure response, not the custom wrapper:

```json
{ "message": "This action is unauthorized." }
```

Treat this the same as a `404` in the UI.

## 4. Update an Activity

### Endpoint

`PUT /api/activity/{id}` (or `PATCH`, same behavior)

### Request Body

Send only the fields you want to change — every field is optional on update:

```json
{
  "price": 40,
  "is_active": false
}
```

### Validation Rules

Same field-level rules as [create](#validation-rules), except every field is optional (`sometimes` instead of `required`).

**`hotel_id` is still forced server-side** to the caller's own hotel on every update, exactly like create — you cannot reassign an activity to a different hotel through this endpoint, even though the target activity's authorization already required it to match your hotel anyway.

**`category_id`, if sent, is re-validated against the caller's hotel** the same way as create — same `403`/`"activityCategories"` error on mismatch.

### Success Response

HTTP `200 OK`. `body` is the updated [activity object](#the-activity-object).

### Error: Not Found / Wrong Hotel

Same as [show](#3-get-a-single-activity): `404` if the id doesn't exist, `403` if it belongs to a different hotel or the caller isn't `admin`.

## 5. Delete an Activity

### Endpoint

`DELETE /api/activity/{id}`

This is a **soft delete** — `deleted_at` is set, the row is not removed. A deleted activity simply stops appearing in `index`/`show` results; there is no restore endpoint exposed here.

### Success Response

HTTP `200 OK`:

```json
{
  "message": "Activity deleted successfully.",
  "code": 200,
  "body": null
}
```

### Error: Not Found / Wrong Hotel

Same as [show](#3-get-a-single-activity): `404` if the id doesn't exist, `403` if it belongs to a different hotel or the caller isn't `admin`.

---

## Activity Category API

Endpoints defined by `App\Http\Controllers\ActivityCategoryController`, gated by `App\Policies\ActivityCategoryPolicy`.

### The Activity Category Object

**No relations are eager-loaded** — same convention as the activity object:

```json
{
  "id": "019fabcd-1234-7000-9000-123456789abc",
  "hotel_id": "019f9b37-c265-726d-a6fe-f7eaa7852636",
  "name": "Wellness",
  "slug": "wellness",
  "description": "Spa and wellness experiences.",
  "created_at": "2026-08-01T10:00:00.000000Z",
  "updated_at": "2026-08-01T10:00:00.000000Z"
}
```

- `slug` and `description` are both nullable free text — no uniqueness constraint on `slug`, and it isn't auto-generated from `name` server-side, so generate/validate it client-side if your UI needs one.
- **No `SoftDeletes`** — `DELETE` is a hard delete, unlike Activity. Deleting a category that activities still reference does **not** cascade-delete those activities: `activities.category_id` uses `nullOnDelete()`, so they're left with `category_id: null` instead of erroring. Warn the user before deleting a category that's still in use, if your product cares about that.

### C.1 List Activity Categories — `GET /api/activity-category`

Same generic `filter`/`search`/`sort`/`page`/`per_page` params as everywhere else in this API. Filterable/sortable columns: `id`, `hotel_id`, `name`, `slug`, `description`, `created_at`, `updated_at`. Searchable (string/text) columns: `name`, `slug`, `description`.

Success: `200`, `body` is a paginator (same shape as [List Activities](#success-response)) whose `data` is an array of [activity category objects](#the-activity-category-object).

### C.2 Create an Activity Category — `POST /api/activity-category`

```json
{
  "hotel_id": "any-valid-hotel-uuid",
  "name": "Wellness",
  "slug": "wellness",
  "description": "Spa and wellness experiences."
}
```

| Field | Rules |
| --- | --- |
| `hotel_id` | **Required by validation** (`exists:hotels,id`) but **always ignored** — overwritten server-side with the caller's own hotel, exactly like Activity. Send *some* valid hotel id (simplest: the caller's own) or you'll get a `422` for a missing field. |
| `name` | required, string, max 255. No uniqueness constraint — two categories in the same hotel can share a name. |
| `slug` | optional, string, max 255. |
| `description` | optional, string. |

**No associated hotel:** if the caller has no hotel, you get the custom wrapper: `{"message": "You do not belong to any hotel.", "code": 403, "body": null}`.

Success: `201`, `body` is the created [activity category object](#the-activity-category-object).

### C.3 Get a Single Activity Category — `GET /api/activity-category/{id}`

Success: `200`, `body` is the [activity category object](#the-activity-category-object). `404` if the id doesn't exist; `403` (Laravel default, treat as not-found) if it belongs to a different hotel or the caller isn't `admin`.

### C.4 Update an Activity Category — `PUT /api/activity-category/{id}`

Same fields as create, all optional (partial update). **`hotel_id` in the payload is silently ignored** — the record keeps its own existing `hotel_id`; you cannot move a category to a different hotel through this endpoint.

Success: `200`, `body` is the updated [activity category object](#the-activity-category-object).

### C.5 Delete an Activity Category — `DELETE /api/activity-category/{id}`

Hard delete. Activities referencing this category have `category_id` set to `NULL` automatically (see [above](#the-activity-category-object)).

Success: `200`, `body: null`.

---

## Super Admin Caveats

Both policies' `before()` hooks let a `super_admin` pass every *authorization* check unconditionally. But `index` and `store` on **both** controllers separately gate on `$request->user()->hotel` for their own business logic — and a super admin typically has no owned hotel. The two checks don't agree, so behavior is inconsistent per endpoint:

| Endpoint | Super admin can actually use it? |
| --- | --- |
| `GET /api/activity`, `GET /api/activity-category` (list) | **No** — the query is hardcoded to `where('hotel_id', <caller's hotel>)`, which is `null` for a super admin, so this returns an **empty list**, not "everything." |
| `POST /api/activity`, `POST /api/activity-category` | **No** — blocked by the "You do not belong to any hotel." check before the insert. |
| `GET /api/activity/{id}`, `GET /api/activity-category/{id}` | **Yes** — only the (bypassed) policy gates this; a super admin can view any hotel's row by id. |
| `PUT /api/activity/{id}`, `PUT /api/activity-category/{id}` | **Yes** — same reasoning; no hotel check beyond the bypassed policy. |
| `DELETE /api/activity/{id}`, `DELETE /api/activity-category/{id}` | **Yes** — same reasoning. |

If a super-admin "manage everything across all hotels" screen is in scope, flag this gap to the backend team — as of this writing a super admin can view/edit/delete an individual activity or category by id but cannot list or create either.

## Error Shapes

| Situation | HTTP | Shape |
| --- | --- | --- |
| Field-level validation failure (missing/wrong-type field, failed `exists` check) | `422` | Laravel default: `{"message": "The given data was invalid.", "errors": {"field": ["..."]}}` |
| No associated hotel | `403` | Custom wrapper: `{"message": "You do not belong to any hotel.", "code": 403, "body": null}` |
| Category belongs to a different hotel | `403` | Custom wrapper: `{"message": "The selected activityCategories does not belong to you.", "code": 403, "body": null}` |
| Wrong role, or record belongs to a different hotel (authorization failure) | `403` | Laravel default: `{"message": "This action is unauthorized."}` |
| Unknown id | `404` | Laravel default: `{"message": "No query results for model [App\\Models\\{Activity|ActivityCategory}] {id}"}` |
| Unknown `filter`/`sort` column | `422` | Laravel default, same shape as field validation |

Always check for an `errors` key to distinguish Laravel's native validation shape from this API's custom `message/code/body` wrapper — both can appear with `422`.

## Example cURL Requests

### List (filtered, searched, sorted, paginated)

```bash
curl -X GET "http://your-domain.com/api/activity?filter[is_active]=1&search=airport&sort=-created_at&per_page=20" \
  -H "Accept: application/json" \
  -H "X-API-KEY: YOUR_API_KEY" \
  -H "Authorization: Bearer USER_LOGIN_TOKEN"
```

### Create

```bash
curl -X POST http://your-domain.com/api/activity \
  -H "Accept: application/json" \
  -H "Content-Type: application/json" \
  -H "X-API-KEY: YOUR_API_KEY" \
  -H "Authorization: Bearer USER_LOGIN_TOKEN" \
  -d '{
    "hotel_id": "YOUR_HOTEL_ID",
    "name": "Airport Pickup",
    "description": "Private airport transfer for guests.",
    "price": 35.5,
    "currency": "USD",
    "is_active": true
  }'
```

### Update (partial)

```bash
curl -X PUT http://your-domain.com/api/activity/019fc000-4444-7000-9000-abcdef123456 \
  -H "Accept: application/json" \
  -H "Content-Type: application/json" \
  -H "X-API-KEY: YOUR_API_KEY" \
  -H "Authorization: Bearer USER_LOGIN_TOKEN" \
  -d '{ "price": 40, "is_active": false }'
```

### Delete

```bash
curl -X DELETE http://your-domain.com/api/activity/019fc000-4444-7000-9000-abcdef123456 \
  -H "Accept: application/json" \
  -H "X-API-KEY: YOUR_API_KEY" \
  -H "Authorization: Bearer USER_LOGIN_TOKEN"
```

### Create an Activity Category

```bash
curl -X POST http://your-domain.com/api/activity-category \
  -H "Accept: application/json" \
  -H "Content-Type: application/json" \
  -H "X-API-KEY: YOUR_API_KEY" \
  -H "Authorization: Bearer USER_LOGIN_TOKEN" \
  -d '{
    "hotel_id": "YOUR_HOTEL_ID",
    "name": "Wellness",
    "slug": "wellness",
    "description": "Spa and wellness experiences."
  }'
```

## Summary for the Frontend

- Every request needs `X-API-KEY` and `Authorization: Bearer {login_token}`.
- `hotel_id` is required in the create request body (send the caller's own hotel id) but is **always overwritten server-side** on both create and update — you can never create or move an activity into a different hotel through this endpoint.
- List with `GET /api/activity`, filter with `filter[column]=value`, free-text search with `search=` (matches `name`/`description`/`currency` only), sort with `sort=column` / `sort=-column`, paginate with `page`/`per_page`.
- Every activity response embeds its `category` object (`null` if it has none) — no separate lookup needed to show the category name. Build the category *picker* itself from [`GET /api/activity-category`](#activity-category-api).
- `price` comes back as a **string** (`"35.50"`), not a number, due to the `decimal:2` cast — parse before doing math.
- `currency` is free-text server-side — enforce a real currency-code list client-side.
- Deleting an activity is a **soft delete** (`deleted_at`); it simply disappears from list/show results.
- Treat `403` on `show`/`update`/`destroy` the same as `404` in the UI — it means "not yours" (or you're not an admin).
- A cross-hotel `category_id` error names the relation (`"activityCategories"`), not the field — show it as a form-level error, not tied to one input.
- A `super_admin` can view/edit/delete an individual activity by id, but currently **cannot** list or create activities (see [Super Admin Caveats](#super-admin-caveats)) — don't build a super-admin activity console assuming full parity with a regular admin yet.
- Activity categories are a separate, simpler resource at `/api/activity-category` — no `SoftDeletes` (hard delete), no cross-hotel FK to validate beyond `hotel_id` itself, and `hotel_id` on update is silently ignored (kept as the record's existing value) rather than re-forced to the caller's hotel. Same super-admin list/create gap applies to it too.

## Related Docs

- [Auth API Documentation](/D:/Hospitality%20Ecosystem/docs/auth-api-documentation.md)
- [Room API Documentation](/D:/Hospitality%20Ecosystem/docs/room-api-documentation.md)
- [Task Management API Documentation](/D:/Hospitality%20Ecosystem/docs/task-management-api-documentation.md)
- [Frontend Create Modules API Guide](/D:/Hospitality%20Ecosystem/docs/frontend-create-modules-api.md)
