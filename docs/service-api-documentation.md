# Service API Documentation

This document describes the hotel **service** management API — the admin-facing CRUD endpoints (`index`, `store`, `show`, `update`, `destroy`) defined by `App\Http\Controllers\ServiceController`, gated by `App\Policies\ServicePolicy`.

A service is something a hotel sells to guests beyond the room itself (spa treatment, airport transfer, breakfast buffet, etc.), optionally grouped under a `ServiceCategory`.

## Base URL

All endpoints below are defined in `routes/api.php`, served under:

`/api`

Examples:

- `GET /api/service`
- `POST /api/service`
- `GET /api/service/{id}`
- `PUT /api/service/{id}`
- `DELETE /api/service/{id}`

## Required Headers

Every request below needs all three:

```http
X-API-KEY: {your_api_key}
Authorization: Bearer {login_token}
Accept: application/json
Content-Type: application/json
```

- `X-API-KEY` is checked by the `api.key` middleware.
- `Authorization: Bearer {login_token}` is required because every service route is inside `auth:sanctum`. Get this token from `POST /api/login` (see `docs/auth-api-documentation.md`) — the token is at `body.token` in that response.
- Without a valid bearer token, the API returns HTTP `401 Unauthenticated.` before any controller/policy logic runs.

## Who Can Call These Endpoints

Every action is gated by `App\Policies\ServicePolicy`, on top of the bearer-token check above. Its `before()` hook lets `super_admin` bypass every rule below unconditionally — but see [Super Admin Caveats](#super-admin-caveats), because two of these endpoints have a separate hotel check that isn't bypassed.

| Action | Rule (non-super-admin) |
| --- | --- |
| `index` (list) | `role` must be `admin`. |
| `store` (create) | `role` must be `admin`. |
| `show` / `update` / `destroy` | `role` must be `admin`, **and** the service's `hotel_id` must equal the caller's own hotel. |

Practical implications for the UI:

- A non-admin user (`employee`) should never reach this screen — treat any `403` here as "this user shouldn't be able to see this page," not a recoverable in-page error.
- A `403` on `show`/`update`/`destroy` for a specific service id most likely means the id belongs to a different hotel (e.g. a stale link/bookmark) — show a "not found or not yours" style message rather than a raw permission error.
- `index`'s query is separately scoped to `where('hotel_id', <the user's hotel>)`, so the list only ever contains services for the user's own hotel.

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
- `body`: the actual payload (a service object, a paginated list, or `null`).

**Validation errors (`422`) and Laravel's own authorization/not-found failures are exceptions to this** — they use Laravel's default shapes, not the `message/code/body` wrapper. See [Error Shapes](#error-shapes).

## The Service Object

Every endpoint that returns a service returns it in this shape — **no relations are eager-loaded** (you get raw `hotel_id`/`category_id`, not embedded `hotel`/`category` objects):

```json
{
  "id": "019fc000-4444-7000-9000-abcdef123456",
  "hotel_id": "019f9b37-c265-726d-a6fe-f7eaa7852636",
  "category_id": null,
  "name": "Airport Pickup",
  "description": "Private airport transfer for guests.",
  "price": "35.50",
  "currency": "USD",
  "is_active": true,
  "created_at": "2026-08-01T10:00:00.000000Z",
  "updated_at": "2026-08-01T10:00:00.000000Z",
  "deleted_at": null
}
```

Field notes for the UI:

- `id`, `hotel_id`, and `category_id` are UUID strings, not integers.
- `price` is cast as `decimal:2` — it comes back as a **string** (e.g. `"35.50"`), not a JSON number. Parse it before doing math on it.
- `currency` is a free-text 3-character string, not a restricted enum server-side — the frontend should constrain it to real currency codes.
- **Services use `SoftDeletes`** — `DELETE` sets `deleted_at`, it does not remove the row (unlike rooms, which hard-delete). A deleted service simply stops showing up in `index`/`show`.
- `category_id` is nullable — a service can exist with no category.
- **There is currently no API endpoint to list or create `ServiceCategory` rows** (`App\Models\ServiceCategory` exists and `category_id` validates against it, but no controller/route exposes it). Until that's added, a category picker can't be populated from the API — either omit `category_id` from the create/edit form entirely, or coordinate with the backend team on how categories should be seeded/managed in the meantime.

## 1. List Services

### Endpoint

`GET /api/service`

### Query Parameters

All optional, same generic behavior as every other list endpoint in this API:

| Param | Type | Example | Behavior |
| --- | --- | --- | --- |
| `filter[<column>]` | string, or array for multiple values | `filter[is_active]=1` | Exact match on any real `services` column. `filter[category_id][]=a&filter[category_id][]=b` matches either. |
| `search` | string | `search=airport` | Partial (`LIKE %term%`) match across the service's string/text columns: `name`, `description`, `currency`. (`hotel_id`/`category_id` are UUID columns, not string/text, so they're excluded from free-text search — use `filter[category_id]=` for an exact match instead.) |
| `sort` | string | `sort=-created_at` | Sort by a real column. Prefix with `-` for descending. |
| `page` | integer | `page=2` | Page number, 1-indexed. |
| `per_page` | integer, 1–100 | `per_page=25` | Page size. Defaults to 15. |

`filter`/`sort` are validated against the services table's real columns: `id`, `hotel_id`, `category_id`, `name`, `description`, `price`, `currency`, `is_active`, `created_at`, `updated_at`, `deleted_at`. An unknown key in either returns a `422`.

### Example Request

```
GET /api/service?filter[is_active]=1&search=airport&sort=-created_at&per_page=20&page=1
```

### Success Response

HTTP `200 OK`. `body` is a Laravel paginator object:

```json
{
  "message": "Services fetched successfully.",
  "code": 200,
  "body": {
    "current_page": 1,
    "data": [
      { "...": "one service object, shape as above" }
    ],
    "first_page_url": "http://your-domain.com/api/service?page=1",
    "from": 1,
    "last_page": 1,
    "last_page_url": "http://your-domain.com/api/service?page=1",
    "links": [ { "url": null, "label": "&laquo; Previous", "page": null, "active": false } ],
    "next_page_url": null,
    "path": "http://your-domain.com/api/service",
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

## 2. Create a Service

### Endpoint

`POST /api/service`

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
| `category_id` | optional, uuid, `exists:service_categories,id`. If provided, **must belong to the caller's own hotel**, or you get `403` (see below). |
| `name` | required, string, max 255. No uniqueness constraint. |
| `description` | optional, string. |
| `price` | optional, numeric. Defaults to `0` at the database level if omitted. |
| `currency` | optional, string, max 3. Defaults to `USD` if omitted. |
| `is_active` | optional, boolean. Defaults to `true` if omitted. |

**Important — hotel scoping:** unlike `room`/`guest` (which reject a mismatched `hotel_id` with a `403`), `service` follows the same pattern as `team`/`task-category`/`task`: whatever `hotel_id` you send is **discarded**, and the record is always created under the caller's own hotel. Auto-fill and hide this field rather than trying to validate it client-side.

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
  "message": "The selected serviceCategories does not belong to you.",
  "code": 403,
  "body": null
}
```

Treat this as a form-level error on the category picker (don't try to map the string `"serviceCategories"` to anything user-facing — just surface a generic "selected category isn't yours" message).

### Success Response

HTTP `201 Created`. `body` is a [service object](#the-service-object).

## 3. Get a Single Service

### Endpoint

`GET /api/service/{id}`

### Success Response

HTTP `200 OK`. `body` is a [service object](#the-service-object).

### Error: Not Found

HTTP `404` if the id doesn't exist at all — Laravel's default model-not-found response, not the custom wrapper:

```json
{ "message": "No query results for model [App\\Models\\Service] {id}" }
```

### Error: Belongs to a Different Hotel

HTTP `403` if the service exists but belongs to a different hotel than the logged-in admin's, or the caller isn't `admin` — Laravel's default authorization-failure response, not the custom wrapper:

```json
{ "message": "This action is unauthorized." }
```

Treat this the same as a `404` in the UI.

## 4. Update a Service

### Endpoint

`PUT /api/service/{id}` (or `PATCH`, same behavior)

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

**`hotel_id` is still forced server-side** to the caller's own hotel on every update, exactly like create — you cannot reassign a service to a different hotel through this endpoint, even though the target service's authorization already required it to match your hotel anyway.

**`category_id`, if sent, is re-validated against the caller's hotel** the same way as create — same `403`/`"serviceCategories"` error on mismatch.

### Success Response

HTTP `200 OK`. `body` is the updated [service object](#the-service-object).

### Error: Not Found / Wrong Hotel

Same as [show](#3-get-a-single-service): `404` if the id doesn't exist, `403` if it belongs to a different hotel or the caller isn't `admin`.

## 5. Delete a Service

### Endpoint

`DELETE /api/service/{id}`

This is a **soft delete** — `deleted_at` is set, the row is not removed. A deleted service simply stops appearing in `index`/`show` results; there is no restore endpoint exposed here.

### Success Response

HTTP `200 OK`:

```json
{
  "message": "Service deleted successfully.",
  "code": 200,
  "body": null
}
```

### Error: Not Found / Wrong Hotel

Same as [show](#3-get-a-single-service): `404` if the id doesn't exist, `403` if it belongs to a different hotel or the caller isn't `admin`.

## Super Admin Caveats

`ServicePolicy::before()` lets a `super_admin` pass every *authorization* check unconditionally. But `index` and `store` separately gate on `$request->user()->hotel` for their own business logic — and a super admin typically has no owned hotel. The two checks don't agree, so behavior is inconsistent per endpoint:

| Endpoint | Super admin can actually use it? |
| --- | --- |
| `GET /api/service` (list) | **No** — the query is hardcoded to `where('hotel_id', <caller's hotel>)`, which is `null` for a super admin, so this returns an **empty list**, not "everything." |
| `POST /api/service` | **No** — blocked by the "You do not belong to any hotel." check before the insert. |
| `GET /api/service/{id}` | **Yes** — only the (bypassed) policy gates this; a super admin can view any hotel's service by id. |
| `PUT /api/service/{id}` | **Yes** — same reasoning; no hotel check beyond the bypassed policy. |
| `DELETE /api/service/{id}` | **Yes** — same reasoning. |

If a super-admin "manage everything across all hotels" screen is in scope for services, flag this gap to the backend team — as of this writing a super admin can view/edit/delete an individual service by id but cannot list or create one.

## Error Shapes

| Situation | HTTP | Shape |
| --- | --- | --- |
| Field-level validation failure (missing/wrong-type field, failed `exists` check) | `422` | Laravel default: `{"message": "The given data was invalid.", "errors": {"field": ["..."]}}` |
| No associated hotel | `403` | Custom wrapper: `{"message": "You do not belong to any hotel.", "code": 403, "body": null}` |
| Category belongs to a different hotel | `403` | Custom wrapper: `{"message": "The selected serviceCategories does not belong to you.", "code": 403, "body": null}` |
| Wrong role, or record belongs to a different hotel (authorization failure) | `403` | Laravel default: `{"message": "This action is unauthorized."}` |
| Unknown id | `404` | Laravel default: `{"message": "No query results for model [App\\Models\\Service] {id}"}` |
| Unknown `filter`/`sort` column | `422` | Laravel default, same shape as field validation |

Always check for an `errors` key to distinguish Laravel's native validation shape from this API's custom `message/code/body` wrapper — both can appear with `422`.

## Example cURL Requests

### List (filtered, searched, sorted, paginated)

```bash
curl -X GET "http://your-domain.com/api/service?filter[is_active]=1&search=airport&sort=-created_at&per_page=20" \
  -H "Accept: application/json" \
  -H "X-API-KEY: YOUR_API_KEY" \
  -H "Authorization: Bearer USER_LOGIN_TOKEN"
```

### Create

```bash
curl -X POST http://your-domain.com/api/service \
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
curl -X PUT http://your-domain.com/api/service/019fc000-4444-7000-9000-abcdef123456 \
  -H "Accept: application/json" \
  -H "Content-Type: application/json" \
  -H "X-API-KEY: YOUR_API_KEY" \
  -H "Authorization: Bearer USER_LOGIN_TOKEN" \
  -d '{ "price": 40, "is_active": false }'
```

### Delete

```bash
curl -X DELETE http://your-domain.com/api/service/019fc000-4444-7000-9000-abcdef123456 \
  -H "Accept: application/json" \
  -H "X-API-KEY: YOUR_API_KEY" \
  -H "Authorization: Bearer USER_LOGIN_TOKEN"
```

## Summary for the Frontend

- Every request needs `X-API-KEY` and `Authorization: Bearer {login_token}`.
- `hotel_id` is required in the create request body (send the caller's own hotel id) but is **always overwritten server-side** on both create and update — you can never create or move a service into a different hotel through this endpoint.
- List with `GET /api/service`, filter with `filter[column]=value`, free-text search with `search=` (matches `name`/`description`/`currency` only), sort with `sort=column` / `sort=-column`, paginate with `page`/`per_page`.
- The service object has **no** eager-loaded relations — `category_id` is a raw id, not an embedded object. There is currently **no API to list `ServiceCategory` rows**, so a category picker can't be built from this API yet; omit `category_id` from the form until that's addressed.
- `price` comes back as a **string** (`"35.50"`), not a number, due to the `decimal:2` cast — parse before doing math.
- `currency` is free-text server-side — enforce a real currency-code list client-side.
- Deleting a service is a **soft delete** (`deleted_at`); it simply disappears from list/show results.
- Treat `403` on `show`/`update`/`destroy` the same as `404` in the UI — it means "not yours" (or you're not an admin).
- A cross-hotel `category_id` error names the relation (`"serviceCategories"`), not the field — show it as a form-level error, not tied to one input.
- A `super_admin` can view/edit/delete an individual service by id, but currently **cannot** list or create services (see [Super Admin Caveats](#super-admin-caveats)) — don't build a super-admin service console assuming full parity with a regular admin yet.

## Related Docs

- [Auth API Documentation](/D:/Hospitality%20Ecosystem/docs/auth-api-documentation.md)
- [Room API Documentation](/D:/Hospitality%20Ecosystem/docs/room-api-documentation.md)
- [Task Management API Documentation](/D:/Hospitality%20Ecosystem/docs/task-management-api-documentation.md)
- [Frontend Create Modules API Guide](/D:/Hospitality%20Ecosystem/docs/frontend-create-modules-api.md)
