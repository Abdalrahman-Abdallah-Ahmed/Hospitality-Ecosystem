# Hotel API Documentation

This document describes the hotel management APIs, defined by `App\Http\Controllers\HotelController` and gated by `App\Policies\HotelPolicy`.

It covers the **admin-facing CRUD endpoints** only (`index`, `store`, `show`, `update`, `destroy`).

## Important — This Is Not Where Most Hotels Get Created

When a new hotel owner signs up, the hotel is created as part of **`POST /api/register`** (see `docs/register-api-update.md`, which supersedes the registration section in `docs/auth-api-documentation.md`), in the same transaction as the user account. That endpoint is open to any admin registering themselves plus their hotel in one call.

`POST /api/hotel` (documented below) is a separate, **super-admin-only** administrative endpoint — e.g. provisioning a hotel on behalf of an admin who already exists, or restoring a previously deleted hotel. A normal "sign up and create my hotel" flow should use `/api/register`, not this endpoint.

## Base URL

All endpoints below are defined in `routes/api.php`, so they are served under:

`/api`

Examples:

- `GET /api/hotel`
- `POST /api/hotel`
- `GET /api/hotel/{id}`
- `PUT /api/hotel/{id}`
- `DELETE /api/hotel/{id}`

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
- `Authorization: Bearer {login_token}` is required because every hotel route is inside the `auth:sanctum` middleware group. Get this token from `POST /api/login`.
- Without a valid bearer token, the API returns HTTP `401 Unauthenticated.` before controller/policy logic ever runs.

## Who Can Call These Endpoints

Every action is gated by `App\Policies\HotelPolicy`. The policy has a `before()` hook: **a user whose `role` is `super_admin` passes every check below, unconditionally** — the per-action rules only apply to non-super-admins.

| Action | Rule (non-super-admin) | Super admin |
| --- | --- | --- |
| `index` (list) | Always denied. | Allowed — sees **every** hotel, not scoped to any owner. |
| `store` (create) | Always denied. | Allowed. |
| `show` | The user must be `admin`, **and** the hotel's `owner_id` must equal the user's own id. | Allowed for any hotel. |
| `update` | Same as `show`: must be the owning `admin`. | Allowed for any hotel, regardless of owner. |
| `destroy` | Always denied. | Allowed for any hotel. |

Practical implications for the UI:

- **`index` and `store` are super-admin-only screens/actions.** A regular admin should never see a "list all hotels" or "create a hotel" screen — they manage their own hotel via `show`/`update` on the hotel they already own (fetched from their own user/hotel context, e.g. `GET /api/user`), and got that hotel via `/api/register` in the first place.
- `show`/`update` for a regular admin: a `403` on their own hotel id should not normally happen; a `403` on someone else's hotel id means "not yours" — treat it like a 404.
- A super admin's UI can freely browse and edit any hotel; there is no ownership restriction for that role anywhere in this resource.

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
- Hotels use `SoftDeletes`, so `DELETE` does **not** permanently erase the row — see the restore behavior under [Create a Hotel](#2-create-a-hotel).
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

## 2. Create a Hotel

**Super admin only.**

### Endpoint

`POST /api/hotel`

### Request Body

```json
{
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
  "ai_preferences": { "tone": "friendly" },
  "is_active": true
}
```

### Validation Rules

| Field | Rules |
| --- | --- |
| `owner_id` | optional, must exist in `users.id`. **The API does not auto-fill this to the caller** (unlike other resources) — a super admin must explicitly pick the target owner. Omitting it creates an unowned hotel. |
| `name` | required, string, max 255. |
| `slug` | required, string, max 255, must be unique among **active** hotels (soft-deleted hotels don't block a new/duplicate slug at the validation layer — see the restore behavior below). |
| `timezone` | optional, string. Defaults to `"UTC"` if omitted. |
| `currency` | optional, string, exactly 3 characters. Defaults to `"USD"` if omitted. |
| `country_code` | optional, string, exactly 2 characters. |
| `city`, `address`, `whatsapp_number`, `email`, `phone` | all optional, string, max 255. |
| `branding`, `ai_preferences` | optional, JSON object/array. |
| `is_active` | optional, boolean. Defaults to `true` if omitted. |

### Special Behavior: Recreating a Deleted Slug Restores the Hotel

Because deleting a hotel is a soft delete, its row (and its `slug`) still technically exists afterwards. If you `POST` a `slug` that belongs to a **soft-deleted** hotel:

- **If that deleted hotel's `owner_id` matches the `owner_id` you send in this request**, the backend restores it and applies the rest of your payload as an update — you get back the *same* hotel `id` as before, not a new one. This is intentional: re-provisioning the same owner's hotel should reuse the record instead of erroring.
- **If the owners don't match** (including omitting `owner_id` when the deleted hotel had one), the request is rejected — see the error below. This prevents accidentally hijacking a different owner's deleted hotel by reusing their slug.

The frontend does not need to detect or branch on this itself — just submit the create form normally. But be aware: a `201` response's `body.id` might not be a freshly-generated id if this restore path was taken.

### Success Response

HTTP `201 Created`. `body` is the created (or restored) [hotel object](#the-hotel-object).

### Error: Missing/Invalid Fields, or Slug Taken by an Active Hotel

HTTP `422`, Laravel's default validation shape:

```json
{
  "message": "The given data was invalid.",
  "errors": {
    "slug": ["The slug has already been taken."]
  }
}
```

### Error: Slug Belongs to a Different Owner's Deleted Hotel

This looks similar but is a **different response shape** — the custom `message/code/body` wrapper, not the validation shape, because it's a business-rule check that runs after validation passes:

HTTP `422`:

```json
{
  "message": "The slug has already been taken.",
  "code": 422,
  "body": null
}
```

Both cases show the same human-readable message, so it's safe to display either directly — but if you need to distinguish them programmatically (e.g. for logging), check for the presence of an `errors` key.

### Error: Non-Super-Admin

HTTP `403`, Laravel's default shape (see [List Hotels](#error-non-super-admin)).

## 3. Get a Single Hotel

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

## 4. Update a Hotel

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

**Note:** there is currently **no server-side guard preventing `owner_id` from being changed** on update. If an owning admin includes `owner_id` in their payload, it will be validated (must exist in `users.id`) and saved as-is — which would transfer the hotel away from themselves. The frontend should not include `owner_id` in a regular admin's edit form; reserve that field for a super-admin "reassign owner" feature, if one is built.

### Success Response

HTTP `200 OK`. `body` is the updated [hotel object](#the-hotel-object).

### Error: Not Found / Not Yours

Same as [show](#3-get-a-single-hotel): `404` if the id doesn't exist, `403` if the caller is neither the owner nor a super admin.

## 5. Delete a Hotel

**Super admin only.**

### Endpoint

`DELETE /api/hotel/{id}`

This is a **soft delete** — the row is marked deleted via `deleted_at`, not permanently removed. See [Create a Hotel](#2-create-a-hotel) for how recreating the same `slug` later restores it.

### Success Response

HTTP `200 OK`:

```json
{
  "message": "Hotel deleted successfully.",
  "code": 200,
  "body": null
}
```

### Error: Not Found

HTTP `404` if the id doesn't exist.

### Error: Non-Super-Admin

HTTP `403`, Laravel's default shape — **note this applies even to the hotel's own owner**: deleting a hotel is exclusively a super-admin action, unlike `show`/`update`.

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

Always check for an `errors` key to distinguish this from the custom-wrapper business-rule errors documented above (slug-owner-mismatch on create, non-super-admin on delete), which can also carry a `422` or `403` status without that key.

## Example cURL Requests

### List (super admin)

```bash
curl -X GET "http://your-domain.com/api/hotel?search=Harbor&sort=-created_at&per_page=20" \
  -H "Accept: application/json" \
  -H "X-API-KEY: YOUR_API_KEY" \
  -H "Authorization: Bearer SUPER_ADMIN_LOGIN_TOKEN"
```

### Create (super admin)

```bash
curl -X POST http://your-domain.com/api/hotel \
  -H "Accept: application/json" \
  -H "Content-Type: application/json" \
  -H "X-API-KEY: YOUR_API_KEY" \
  -H "Authorization: Bearer SUPER_ADMIN_LOGIN_TOKEN" \
  -d '{
    "owner_id": "019f9b37-c260-7abc-9000-1234567890ab",
    "name": "Grand Harbor Hotel",
    "slug": "grand-harbor-hotel",
    "currency": "USD"
  }'
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

### Delete (super admin)

```bash
curl -X DELETE http://your-domain.com/api/hotel/019f9b37-c265-726d-a6fe-f7eaa7852636 \
  -H "Accept: application/json" \
  -H "X-API-KEY: YOUR_API_KEY" \
  -H "Authorization: Bearer SUPER_ADMIN_LOGIN_TOKEN"
```

## Summary for the Frontend

- Most hotels are created via `/api/register`, not this resource. Build a "manage my hotel" screen (`show`/`update` on the admin's own hotel) for regular admins, and a separate super-admin "all hotels" screen (`index`/`store`/`destroy`) that a regular admin never sees.
- Every request needs `X-API-KEY` and `Authorization: Bearer {login_token}`.
- `index`, `store`, and `destroy` are super-admin-only; `show`/`update` work for the owning admin **or** a super admin.
- List with `GET /api/hotel`, filter with `filter[column]=value`, free-text search with `search=`, sort with `sort=column`/`sort=-column`, paginate with `page`/`per_page`.
- On create, a super admin must explicitly pass `owner_id` — it is not auto-filled.
- Recreating a soft-deleted hotel's `slug` with the same `owner_id` restores it instead of creating a new record; with a different `owner_id` it's rejected.
- Don't include `owner_id` in a regular admin's update form — nothing currently blocks reassigning the hotel away from them.
- `email`, `currency`, `country_code` are only length/type validated server-side — enforce format client-side.
- `DELETE` is soft-delete; the slug can later be restored via create.
