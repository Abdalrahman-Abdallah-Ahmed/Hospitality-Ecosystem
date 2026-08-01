# Hotel Policy API Documentation

This document describes the hotel-policy management APIs, defined by `App\Http\Controllers\HotelPolicyController` and gated by `App\Policies\HotelPolicyPolicy`.

A "hotel policy" is a knowledge-base entry attached to a hotel (e.g. a cancellation policy, a check-in rule) — used, among other things, to ground the hotel's AI assistant in that hotel's actual rules.

It covers `index`, `store`, `update`, and `destroy`. **There is no `show` endpoint** — the route is explicitly excluded (`Route::resource('/hotel-policy', ...)->except(['edit', 'create', 'show'])`), and the policy class has no `view()` method either. Build the detail/edit view from the row you already have in the list, not from a dedicated fetch-by-id call.

## Base URL

All endpoints below are defined in `routes/api.php`, so they are served under:

`/api`

Examples:

- `GET /api/hotel-policy`
- `POST /api/hotel-policy`
- `PUT /api/hotel-policy/{id}`
- `DELETE /api/hotel-policy/{id}`

## Required Headers

Every request below needs all three:

```http
X-API-KEY: {your_api_key}
Authorization: Bearer {login_token}
Accept: application/json
Content-Type: application/json
```

Notes:

- `X-API-KEY` is checked by the `api.key` middleware. If `API_KEY` is unset on the server, this header is not enforced; when it is set, a missing/wrong key returns HTTP `401`.
- `Authorization: Bearer {login_token}` is required because every hotel-policy route is inside the `auth:sanctum` middleware group. Get this token from `POST /api/login`.
- Without a valid bearer token, the API returns HTTP `401 Unauthenticated.` before controller/policy logic ever runs.

## Who Can Call These Endpoints

Every action is gated by `App\Policies\HotelPolicyPolicy`. The policy has a `before()` hook: **a user whose `role` is `super_admin` passes every check below, unconditionally.**

| Action | Rule (non-super-admin) | Super admin |
| --- | --- | --- |
| `index` (list) | The user's `role` must be `admin`. | Allowed. |
| `store` (create) | The user's `role` must be `admin`, **and** they must have an associated hotel (see below). | Allowed, but see the same associated-hotel caveat under [Create](#2-create-a-hotel-policy). |
| `update` / `destroy` | The user must be `admin`, **and** the policy's `hotel_id` must equal the hotel the user owns. | Allowed for any hotel's policy. |

Practical implications for the UI:

- A non-admin user should never reach this screen; treat a `403` here as "shouldn't be on this page."
- `index` is additionally query-scoped to the caller's own hotel for regular admins (see below) — a super admin's scoping is different, see the caveat there.
- A `403` on `update`/`destroy` for a specific policy id most likely means it belongs to a different hotel — treat it like a 404.

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
- `body`: the actual payload (a hotel-policy object, a paginated list, or `null`).

**Validation errors (`422`) are the one exception** — they use Laravel's default shape, not the `message/code/body` wrapper (see [Validation Errors](#validation-errors)).

## The Hotel Policy Object

```json
{
  "id": "019facde-1111-7000-9000-abcdef123456",
  "hotel_id": "019f9b37-c265-726d-a6fe-f7eaa7852636",
  "title": "Cancellation Policy",
  "category": "recommendation_rules",
  "content": "Free cancellation up to 24 hours before arrival. Cancellations within 24 hours are charged one night's stay.",
  "keywords": ["cancellation", "refund", "no-show"],
  "is_active": true,
  "created_at": "2026-08-01T10:00:00.000000Z",
  "updated_at": "2026-08-01T10:00:00.000000Z",
  "deleted_at": null
}
```

Field notes for the UI:

- `id` and `hotel_id` are UUID strings, not integers.
- `keywords` is a JSON array field, returned as a parsed array, not a string.
- `category` defaults to `"recommendation_rules"` at the database level when omitted on create. It is **not** restricted to a fixed set server-side (it's a plain string column, not a DB enum) — the backend will accept any string here. Constrain input client-side to the known set:
  `hospitality_best_practices`, `guest_personas`, `communication_guidelines`, `revenue_strategies`, `destination_knowledge`, `seasonal_knowledge`, `recommendation_rules`.
- `is_active` defaults to `true` at the database level when omitted on create.
- Hotel policies use `SoftDeletes`, so `DELETE` does **not** permanently erase the row.
- No relations are eager-loaded on this object.

## 1. List Hotel Policies

### Endpoint

`GET /api/hotel-policy`

### Query Parameters

All optional:

| Param | Type | Example | Behavior |
| --- | --- | --- | --- |
| `filter[<column>]` | string, or array for multiple values | `filter[category]=recommendation_rules` | Exact match on any real `hotel_policies` column. |
| `search` | string | `search=cancellation` | Partial (`LIKE %term%`) match across the policy's string/text columns (`title`, `category`, `content`). |
| `sort` | string | `sort=-created_at` | Sort by a real column. Prefix with `-` for descending. |
| `page` | integer | `page=2` | Page number, 1-indexed. |
| `per_page` | integer, 1–100 | `per_page=25` | Page size. Defaults to 15. |

`filter`/`sort` are validated against the hotel_policies table's real columns; an unknown key returns a `422`.

**Scoping caveat:** the query is hardcoded to `where('hotel_id', <the caller's own hotel>)`. For a regular admin this correctly shows only their hotel's policies. **For a super admin, who has no owned hotel, this currently returns an empty list** rather than every policy across every hotel — the `before()` bypass only affects the authorization check, not this query's scoping. If you're building a super-admin "browse all policies" screen, confirm with the backend team whether this has been fixed before relying on it; as of this writing it has not.

### Success Response

HTTP `200 OK`. `body` is a Laravel paginator object; `body.data` contains [hotel-policy objects](#the-hotel-policy-object).

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

## 2. Create a Hotel Policy

### Endpoint

`POST /api/hotel-policy`

### Request Body

```json
{
  "title": "Cancellation Policy",
  "category": "recommendation_rules",
  "content": "Free cancellation up to 24 hours before arrival.",
  "keywords": ["cancellation", "refund"],
  "is_active": true
}
```

### Validation Rules

| Field | Rules |
| --- | --- |
| `hotel_id` | **Do not send this** — see below. |
| `title` | required, string, max 255. |
| `category` | optional, string, max 255. Defaults to `"recommendation_rules"` if omitted. Not restricted to a fixed list server-side — see [The Hotel Policy Object](#the-hotel-policy-object). |
| `content` | required, string (text column, no practical length cap). |
| `keywords` | optional, JSON array. |
| `is_active` | optional, boolean. Defaults to `true` if omitted. |

**Important — `hotel_id` is always server-resolved, never client-supplied:** the backend ignores whatever you send and overwrites it with `<the caller's own hotel>`. If you send a `hotel_id` for a different hotel, it is silently discarded — the policy is still created under the caller's own hotel, not the one you specified. Simplest correct behavior: don't include `hotel_id` in the request body at all.

**Important — the caller must have an associated hotel:** if the logged-in admin has no hotel (their `hotel` relation resolves to nothing — which is also always true for a super admin, since this endpoint doesn't distinguish "no hotel" from "is a super admin"), the request is rejected. In practice this means **a super admin currently cannot create a hotel policy through this endpoint** — there is no way for them to specify which hotel it belongs to. If a super-admin "create policy for hotel X" flow is needed, confirm with the backend team; it isn't supported as of this writing.

### Success Response

HTTP `201 Created`. `body` is the created [hotel-policy object](#the-hotel-policy-object), with `hotel_id` set to the caller's own hotel.

### Error: Missing Required Fields

HTTP `422`:

```json
{
  "message": "The given data was invalid.",
  "errors": {
    "title": ["The title field is required."],
    "content": ["The content field is required."]
  }
}
```

### Error: No Associated Hotel

Custom `message/code/body` wrapper, not the validation shape:

HTTP `403`:

```json
{
  "message": "User does not have an associated hotel.",
  "code": 403,
  "body": null
}
```

### Error: Non-Admin

HTTP `403`, Laravel's default authorization-failure shape:

```json
{ "message": "This action is unauthorized." }
```

## 3. Update a Hotel Policy

### Endpoint

`PUT /api/hotel-policy/{id}` (or `PATCH`, same behavior)

### Request Body

Send only the fields you want to change:

```json
{
  "title": "Updated Cancellation Policy",
  "is_active": false
}
```

### Validation Rules

Same field-level rules as [create](#validation-rules), except every field is optional.

**`hotel_id` behaves the same as on create — it is always forced server-side, this time to the policy's own existing `hotel_id`.** Sending a different `hotel_id` in the payload has no effect; it's silently overwritten back to the record's original hotel before saving, so a policy can never be reassigned to a different hotel through this endpoint. There is no error response for attempting it — the request just succeeds as if you hadn't sent `hotel_id` at all. Simplest correct behavior: don't include `hotel_id` in the request body.

### Success Response

HTTP `200 OK`. `body` is the updated [hotel-policy object](#the-hotel-policy-object).

### Error: Not Found

HTTP `404` if the id doesn't exist:

```json
{ "message": "No query results for model [App\\Models\\HotelPolicy] {id}" }
```

### Error: Belongs to a Different Hotel

HTTP `403` for a regular admin whose own hotel doesn't match the policy's hotel — Laravel's default authorization-failure shape:

```json
{ "message": "This action is unauthorized." }
```

Treat this the same as a 404 in the UI. Super admins bypass this entirely.

## 4. Delete a Hotel Policy

### Endpoint

`DELETE /api/hotel-policy/{id}`

This is a **soft delete** — the row is marked deleted via `deleted_at`, not permanently removed.

### Success Response

HTTP `200 OK`:

```json
{
  "message": "Hotel policy deleted successfully.",
  "code": 200,
  "body": null
}
```

### Error: Not Found / Wrong Hotel

Same as [update](#3-update-a-hotel-policy): `404` if the id doesn't exist, `403` if it belongs to a different hotel (bypassed for super admins).

## Validation Errors

For any field-shape validation failure (missing required field, wrong type), Laravel returns its default shape — **not** the custom `message/code/body` wrapper:

```json
{
  "message": "The given data was invalid.",
  "errors": {
    "field_name": ["Human-readable error message."]
  }
}
```

The UI should distinguish this from the custom-wrapper business-rule error `"User does not have an associated hotel."` (`403` on create), which has no `errors` key.

## Example cURL Requests

### List

```bash
curl -X GET "http://your-domain.com/api/hotel-policy?search=cancellation&sort=-created_at" \
  -H "Accept: application/json" \
  -H "X-API-KEY: YOUR_API_KEY" \
  -H "Authorization: Bearer USER_LOGIN_TOKEN"
```

### Create

```bash
curl -X POST http://your-domain.com/api/hotel-policy \
  -H "Accept: application/json" \
  -H "Content-Type: application/json" \
  -H "X-API-KEY: YOUR_API_KEY" \
  -H "Authorization: Bearer USER_LOGIN_TOKEN" \
  -d '{
    "title": "Cancellation Policy",
    "content": "Free cancellation up to 24 hours before arrival."
  }'
```

### Update (partial)

```bash
curl -X PUT http://your-domain.com/api/hotel-policy/019facde-1111-7000-9000-abcdef123456 \
  -H "Accept: application/json" \
  -H "Content-Type: application/json" \
  -H "X-API-KEY: YOUR_API_KEY" \
  -H "Authorization: Bearer USER_LOGIN_TOKEN" \
  -d '{ "is_active": false }'
```

### Delete

```bash
curl -X DELETE http://your-domain.com/api/hotel-policy/019facde-1111-7000-9000-abcdef123456 \
  -H "Accept: application/json" \
  -H "X-API-KEY: YOUR_API_KEY" \
  -H "Authorization: Bearer USER_LOGIN_TOKEN"
```

## Summary for the Frontend

- Every request needs `X-API-KEY` and `Authorization: Bearer {login_token}`.
- There is no single-resource `GET` — build the detail/edit view from the row already loaded in the list.
- Never send `hotel_id` on create or update — it's always forced server-side and any value you send is silently ignored (not rejected, not applied).
- `create` requires the caller to have an associated hotel; there is currently no way for a super admin to create a policy for an arbitrary hotel through this endpoint.
- A super admin's `index` currently returns an **empty list**, not "every policy" — the scoping query doesn't know about super admins even though the authorization check does. Don't build a super-admin "browse all policies" screen against this endpoint without confirming that's fixed.
- `category` is free-text server-side — enforce the fixed category list client-side.
- `DELETE` is soft-delete, not hard-delete.
