# User Management API Documentation

This document describes the user-management APIs, defined by `App\Http\Controllers\UserController` and gated by `App\Policies\UserPolicy`.

It covers `index`, `store`, `show`, `update`, and `destroy` — full CRUD, unlike some other resources in this app (e.g. Hotel Policy has no `show`).

**Do not confuse this with `GET /api/user` (singular).** That's a completely different, unrelated endpoint — "give me the currently authenticated user" — available to any logged-in user regardless of role. Everything in this document is under `/api/users` (plural) and is admin/super-admin only.

## Base URL

All endpoints below are defined in `routes/api.php`, so they are served under:

`/api`

Examples:

- `GET /api/users`
- `POST /api/users`
- `GET /api/users/{id}`
- `PUT /api/users/{id}`
- `DELETE /api/users/{id}`

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
- `Authorization: Bearer {login_token}` is required because every `/api/users` route is inside the `auth:sanctum` middleware group. Get this token from `POST /api/login`.
- Without a valid bearer token, the API returns HTTP `401 Unauthenticated.` before controller/policy logic ever runs.

## Who Can Call These Endpoints

Every action is gated by `App\Policies\UserPolicy`. The policy has a `before()` hook: **a user whose `role` is `super_admin` passes every check below, unconditionally.**

| Action | Rule (non-super-admin) | Super admin |
| --- | --- | --- |
| `index` (list) | The user's `role` must be `admin`. Results are scoped to their own hotel (see below). | Allowed. Sees **every** user in the system, unscoped by hotel. |
| `show` (view one) | The user must be `admin`, **and** the target's `hotel_id` must equal the caller's own hotel. | Allowed for any user. |
| `store` (create) | The user's `role` must be `admin`, **and** they must have an associated hotel (see [Create](#2-create-a-user)). | Allowed, but must explicitly supply `hotel_id` — see [Create](#2-create-a-user). |
| `update` / `destroy` | The user must be `admin`, **and** the target's `hotel_id` must equal the caller's own hotel. | Allowed for any user. |

Anyone who is not `admin` or `super_admin` (i.e. `employee`) gets HTTP `403` on **every** route in this document, including `index`.

Practical implications for the UI:

- A non-admin, non-super-admin user should never reach this screen; treat a `403` here as "shouldn't be on this page."
- A `403` on `show`/`update`/`destroy` for a specific user id most likely means that user belongs to a different hotel — treat it like a `404`.
- An admin's `index` always includes their own user record, even if — for data-consistency reasons unrelated to this endpoint — their own `hotel_id` column happens to be unset (the backend resolves "my hotel" via ownership as a fallback and explicitly OR's in the caller's own id, so this can't silently exclude them).

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
- `body`: the actual payload (a user object, a paginated list, or `null`).

**Validation errors (`422`) are the one exception** — they use Laravel's default shape, not the `message/code/body` wrapper (see [Validation Errors](#validation-errors)).

## The User Object

```json
{
  "id": "019facde-1111-7000-9000-abcdef123456",
  "hotel_id": "019f9b37-c265-726d-a6fe-f7eaa7852636",
  "team_id": "019fabcd-1234-7000-9000-123456789abc",
  "name": "Youssef Kamal",
  "email": "youssef@example.com",
  "phone_number": "+201234567890",
  "role": "employee",
  "email_verified_at": null,
  "created_at": "2026-08-01T10:00:00.000000Z",
  "updated_at": "2026-08-01T10:00:00.000000Z",
  "hotel": {
    "id": "019f9b37-c265-726d-a6fe-f7eaa7852636",
    "name": "Grand Harbor Hotel",
    "slug": "grand-harbor-hotel"
  }
}
```

Field notes for the UI:

- `id`, `hotel_id`, and `team_id` are UUID strings, not integers. `hotel_id` and `team_id` can both be `null`.
- `password` and `remember_token` are **never** included in the response — they're hidden at the model level.
- `role` is one of `admin`, `employee`, `super_admin`. Defaults to `employee` at the database level if omitted on create.
- `hotel` is eager-loaded and included on every response from this controller (`show`, `store`, `update`, and each row in `index`). It can be `null` if the user has no `hotel_id`.
- There is **no `team` relation** eager-loaded here — you only get `team_id`. Resolve the team name from your own team list/cache if you need to display it.
- Users are **not** soft-deleted — `DELETE` permanently removes the row (see [Delete a User](#5-delete-a-user)).

## 1. List Users

### Endpoint

`GET /api/users`

### Query Parameters

All optional:

| Param | Type | Example | Behavior |
| --- | --- | --- | --- |
| `filter[<column>]` | string, or array for multiple values | `filter[role]=employee` | Exact match on any real `users` column. |
| `search` | string | `search=youssef` | Partial (`LIKE %term%`) match across the user's string/text columns. |
| `sort` | string | `sort=-created_at` | Sort by a real column. Prefix with `-` for descending. |
| `page` | integer | `page=2` | Page number, 1-indexed. |
| `per_page` | integer, 1–100 | `per_page=25` | Page size. Defaults to 15. |

`filter`/`sort` are validated against the `users` table's real columns; an unknown key returns a `422`.

**Scoping:** for a regular admin, the query returns only users whose `hotel_id` matches the admin's own hotel, **plus the admin's own record** (belt-and-suspenders — see [Who Can Call These Endpoints](#who-can-call-these-endpoints)). For a super admin, the query is entirely unscoped and returns every user in the system.

### Success Response

HTTP `200 OK`. `body` is a Laravel paginator object; `body.data` contains [user objects](#the-user-object).

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

## 2. Create a User

### Endpoint

`POST /api/users`

### Request Body

```json
{
  "name": "Youssef Kamal",
  "email": "youssef@example.com",
  "password": "Password123!",
  "phone_number": "+201234567890",
  "role": "employee",
  "team_id": "019fabcd-1234-7000-9000-123456789abc"
}
```

### Validation Rules

| Field | Rules |
| --- | --- |
| `hotel_id` | **Do not send this as a regular admin** — see below. A super admin must send it explicitly. |
| `name` | required, string, max 255. |
| `email` | required, string, max 255, must be unique across all users. |
| `password` | required, string, max 255. Hashed automatically — **do not** pre-hash it client-side. Unlike `POST /api/register`, there is **no `password_confirmation` field** on this endpoint. |
| `phone_number` | optional, string, must be unique across all users if sent. |
| `role` | optional, must be one of `admin`, `employee`, `super_admin`. Defaults to `employee` if omitted. |
| `team_id` | optional, must reference an existing team, and that team must belong to the resolved hotel (see below) — otherwise `403`. |

**Important — `hotel_id` behaves differently depending on who's calling:**

- **Regular admin:** whatever you send is discarded and overwritten with the caller's own hotel. Sending a *real* hotel id that isn't yours is silently ignored (the user is still created under your own hotel) — but sending a hotel id that **doesn't exist at all** fails validation first with a `422` (`hotel_id` is checked against `hotels.id` before the controller ever runs). Simplest correct behavior: don't include `hotel_id` in the request body at all.
- **Super admin:** `hotel_id` is taken directly from what you send. If you omit it, the new user is created with **no hotel at all** (`hotel_id: null`) — useful for e.g. provisioning another super admin, but almost certainly wrong for a regular staff member. Always send `hotel_id` explicitly when a super admin is creating a hotel-scoped user.

**Important — the calling admin must have an associated hotel:** if the logged-in admin has no hotel, the request is rejected before any user is created (see [Error: No Associated Hotel](#error-no-associated-hotel)). This restriction does not apply to super admins.

**Important — `team_id` must belong to the same hotel:** if you send `team_id`, the backend checks it against the hotel the user is being created under (the admin's own hotel, or the `hotel_id` a super admin supplied). A team from a different hotel — or any `team_id` at all when there's no hotel context (e.g. a super admin who omitted `hotel_id`) — returns `403`.

**Important — `role` is not restricted by the caller's own role:** a regular `admin` can create another `admin` or even a `super_admin` through this endpoint; the backend does not prevent privilege escalation here. **Enforce your intended role options client-side** (e.g. only expose "Employee" in a normal admin's create-user form) — don't rely on this endpoint to gate it.

### Success Response

HTTP `201 Created`. `body` is the created [user object](#the-user-object).

### Error: Missing/Invalid Fields

HTTP `422`:

```json
{
  "message": "The given data was invalid.",
  "errors": {
    "email": ["The email has already been taken."],
    "password": ["The password field is required."]
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

### Error: Team Belongs to a Different Hotel (or No Hotel Context)

HTTP `403`:

```json
{
  "message": "The selected team does not belong to you.",
  "code": 403,
  "body": null
}
```

### Error: Non-Admin

HTTP `403`, Laravel's default authorization-failure shape:

```json
{ "message": "This action is unauthorized." }
```

## 3. Get a User

### Endpoint

`GET /api/users/{id}`

### Success Response

HTTP `200 OK`. `body` is the [user object](#the-user-object).

### Error: Not Found

HTTP `404` if the id doesn't exist:

```json
{ "message": "No query results for model [App\\Models\\User] {id}" }
```

### Error: Belongs to a Different Hotel

HTTP `403` for a regular admin whose own hotel doesn't match the target user's hotel — Laravel's default authorization-failure shape:

```json
{ "message": "This action is unauthorized." }
```

Treat this the same as a `404` in the UI. Super admins bypass this entirely.

**Known gap:** unlike `index` (see [Who Can Call These Endpoints](#who-can-call-these-endpoints)), this comparison is a direct `hotel_id === hotel_id` check with no fallback for an admin whose own `hotel_id` column happens to be unset. In the (currently rare) case where an admin's `hotel_id` isn't backfilled, they could get an unexpected `403` viewing/editing/deleting their own record. If you see this happen for a "should definitely be allowed" admin, it's a backend data/logic issue, not a real permissions denial — flag it rather than assuming the user genuinely lacks access.

## 4. Update a User

### Endpoint

`PUT /api/users/{id}` (or `PATCH`, same behavior)

### Request Body

Send only the fields you want to change:

```json
{
  "name": "Youssef Kamal (Updated)",
  "team_id": "019fabcd-1234-7000-9000-123456789abc"
}
```

### Validation Rules

Same field-level rules as [create](#validation-rules), except every field is optional, and uniqueness checks (`email`, `phone_number`) ignore the record being updated.

**`hotel_id` can never be changed through this endpoint — for anyone, including super admins.** It is always forced server-side back to the user's existing `hotel_id` before saving. Sending a different `hotel_id` has no effect and produces no error — the request just succeeds as if you hadn't sent it. Simplest correct behavior: don't include `hotel_id` in the request body.

**`team_id` is re-validated against the target user's own hotel on every update**, the same way as on create — a team from a different hotel returns `403`. This check runs even if you don't touch `team_id` in this request, using the user's *current* `team_id`; in practice this only matters if the user's hotel and team have somehow drifted out of sync already.

### Success Response

HTTP `200 OK`. `body` is the updated [user object](#the-user-object).

### Error: Not Found / Wrong Hotel / Invalid Team

Same shapes as [create](#error-missinginvalid-fields) and [show](#error-belongs-to-a-different-hotel): `404` if the id doesn't exist, `403` (unauthorized shape) if the target belongs to a different hotel, `403` (`message/code/body` wrapper, "The selected team does not belong to you.") if `team_id` doesn't resolve to the user's own hotel.

## 5. Delete a User

### Endpoint

`DELETE /api/users/{id}`

This is a **hard delete** — the row is permanently removed, unlike e.g. Hotel Policy or Guest which soft-delete.

### Success Response

HTTP `200 OK`:

```json
{
  "message": "User deleted successfully.",
  "code": 200,
  "body": null
}
```

### Error: Not Found / Wrong Hotel

Same as [update](#4-update-a-user): `404` if the id doesn't exist, `403` if it belongs to a different hotel (bypassed for super admins).

## Validation Errors

For any field-shape validation failure (missing required field, wrong type, duplicate unique value), Laravel returns its default shape — **not** the custom `message/code/body` wrapper:

```json
{
  "message": "The given data was invalid.",
  "errors": {
    "field_name": ["Human-readable error message."]
  }
}
```

The UI should distinguish this from the custom-wrapper business-rule errors (`"User does not have an associated hotel."`, `"The selected team does not belong to you."`), which have no `errors` key.

## Example cURL Requests

### List

```bash
curl -X GET "http://your-domain.com/api/users?search=youssef&sort=-created_at" \
  -H "Accept: application/json" \
  -H "X-API-KEY: YOUR_API_KEY" \
  -H "Authorization: Bearer USER_LOGIN_TOKEN"
```

### Create

```bash
curl -X POST http://your-domain.com/api/users \
  -H "Accept: application/json" \
  -H "Content-Type: application/json" \
  -H "X-API-KEY: YOUR_API_KEY" \
  -H "Authorization: Bearer USER_LOGIN_TOKEN" \
  -d '{
    "name": "Youssef Kamal",
    "email": "youssef@example.com",
    "password": "Password123!",
    "role": "employee"
  }'
```

### Get

```bash
curl -X GET http://your-domain.com/api/users/019facde-1111-7000-9000-abcdef123456 \
  -H "Accept: application/json" \
  -H "X-API-KEY: YOUR_API_KEY" \
  -H "Authorization: Bearer USER_LOGIN_TOKEN"
```

### Update (partial)

```bash
curl -X PUT http://your-domain.com/api/users/019facde-1111-7000-9000-abcdef123456 \
  -H "Accept: application/json" \
  -H "Content-Type: application/json" \
  -H "X-API-KEY: YOUR_API_KEY" \
  -H "Authorization: Bearer USER_LOGIN_TOKEN" \
  -d '{ "name": "Youssef Kamal (Updated)" }'
```

### Delete

```bash
curl -X DELETE http://your-domain.com/api/users/019facde-1111-7000-9000-abcdef123456 \
  -H "Accept: application/json" \
  -H "X-API-KEY: YOUR_API_KEY" \
  -H "Authorization: Bearer USER_LOGIN_TOKEN"
```

## Summary for the Frontend

- `/api/users` (plural, this document) is the admin/super-admin user-management CRUD. `/api/user` (singular) is an unrelated "who am I" endpoint open to any authenticated user — don't conflate the two in routing/permissions logic.
- Employees (any non-admin, non-super-admin) get `403` on every route here — treat as "not your page," don't render this UI for them at all.
- `index` scoping differs by role: an admin sees only their own hotel's users (plus themselves, always); a super admin sees every user in the system.
- Never send `hotel_id` on **update** — it's always forced server-side and any value you send is silently ignored.
- On **create**, only a super admin's `hotel_id` is actually used; a regular admin's is always overwritten with their own hotel. A super admin who omits `hotel_id` creates a hotel-less user — make sure your super-admin create form always sends one for regular staff.
- `team_id` (create and update) must belong to the same hotel the user is/will be in, or you get a `403` with a dedicated message — surface it as a form-level error near the team picker.
- `role` is **not** gated server-side by the caller's own role — a plain admin can create/promote someone to `admin` or `super_admin`. Restrict the role options you expose in the UI to whatever your product actually wants a given caller to grant.
- There is no `password_confirmation` field on this endpoint (unlike `/api/register`) — if your form has a "confirm password" field, only send `password` to the API.
- `DELETE` is a **hard delete** here — no `deleted_at`, no undo. Confirm destructively in the UI.
- A `403` on `show`/`update`/`destroy` for a specific id should be treated like a `404` (wrong hotel), except see the [known gap](#error-belongs-to-a-different-hotel) note if it happens to an admin acting on their own record.

## Related Docs

- [Auth API Documentation](/D:/Hospitality%20Ecosystem/docs/auth-api-documentation.md)
- [Hotel API Documentation](/D:/Hospitality%20Ecosystem/docs/hotel-api-documentation.md)
- [Hotel Policy API Documentation](/D:/Hospitality%20Ecosystem/docs/hotel-policy-api-documentation.md)
- [Task Management API Documentation](/D:/Hospitality%20Ecosystem/docs/task-management-api-documentation.md)
- Companion Postman collection: `docs/postman/user-management-api.postman_collection.json`
