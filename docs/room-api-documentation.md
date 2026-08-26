# Room API Documentation

This document describes the room management APIs the frontend team should use to build the rooms list/detail/create/edit UI.

It covers the **admin-facing CRUD endpoints** only (`index`, `store`, `show`, `update`, `destroy`), defined by `App\Http\Controllers\RoomController`.

## Base URL

All endpoints below are defined in `routes/api.php`, so they are served under:

`/api`

Examples:

- `GET /api/room`
- `POST /api/room`
- `GET /api/room/{id}`
- `PUT /api/room/{id}`
- `DELETE /api/room/{id}`

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
- `Authorization: Bearer {login_token}` is required because every room route is inside the `auth:sanctum` middleware group. Get this token from `POST /api/login` (see `docs/auth-api-documentation.md`).
- Without a valid bearer token, the API returns HTTP `401 Unauthenticated.` before your controller/policy logic ever runs.

## Who Can Call These Endpoints

Every action is gated by `App\Policies\RoomPolicy`, on top of the bearer-token check above:

| Action | Rule |
| --- | --- |
| `index` (list) | The logged-in user's `role` must be `admin`. |
| `store` (create) | The logged-in user's `role` must be `admin`. |
| `show` / `update` / `destroy` | The user must be `admin`, **and** the room's `hotel_id` must equal the hotel the user owns. |

Practical implications for the UI:

- A non-admin user should never reach this screen — treat any `403` here as "this user shouldn't be able to see this page," not a recoverable in-page error.
- A `403` on `show`/`update`/`destroy` for a specific room id most likely means the id belongs to a different hotel (e.g. a stale link/bookmark) — show a "not found or not yours" style message rather than a raw permission error.
- There's currently no per-hotel scoping on `index`'s own authorization check, but the query itself is scoped to `where('hotel_id', <the user's hotel>)`, so the list only ever contains rooms for the user's own hotel.

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
- `body`: the actual payload (a room object, a paginated list, or `null`).

**Validation errors (`422`) are the one exception** — they are Laravel's default shape, not the `message/code/body` wrapper (see [Validation Errors](#validation-errors) below).

## The Room Object

Every endpoint that returns a room returns it in this shape — **no relations are eager-loaded** (unlike the reservation endpoints, this does not embed a nested `hotel` object):

```json
{
  "id": "019f9b37-c268-738c-bc46-53281c1763cf",
  "hotel_id": "019f9b37-c265-726d-a6fe-f7eaa7852636",
  "room_number": "101",
  "room_type": "double",
  "floor": "1",
  "status": "available",
  "created_at": "2026-07-25T21:39:10.000000Z",
  "updated_at": "2026-07-25T21:39:10.000000Z"
}
```

Field notes for the UI:

- `id` and `hotel_id` are UUID strings, not integers — don't parse them as numbers.
- `room_number` and `floor` are free-text strings and are nullable — a room can exist with either blank.
- `room_type` is **now a real, server-enforced enum** (`App\Enums\RoomTypes`), not free text: `single`, `double`, `twin`, `triple`, `suite`, `deluxe`, `family`. It's still nullable (a room can have no type set), but if you send a value, it must be one of these seven or the request is rejected with a `422` — see [Create a Room](#2-create-a-room). The full list is echoed back on every `index` call as `body.room_types` (plain strings, see [List Rooms](#1-list-rooms)) so the frontend doesn't need to hard-code it.
- `status` is also a free-text string, not a restricted enum server-side (there's no `Rule::in`/cast enforcing specific values). It defaults to `"available"` at the database level when omitted on create. The API will accept any string here, so **the frontend should be the one constraining input** (e.g. a fixed dropdown of `available` / `occupied` / `maintenance` / whatever values the product actually uses) — don't rely on the server to reject typos.
- There is no soft-delete on rooms — `DELETE` permanently removes the row (see [Delete a Room](#5-delete-a-room)).
- If a room has active reservations pointing at it (`reservations.room_id`), deleting it does **not** cascade-delete those reservations; their `room_id` is left pointing at a now-missing row (no `ON DELETE` rule enforced from this side). Consider warning the user before deleting a room that's referenced by upcoming reservations.

## 1. List Rooms

### Endpoint

`GET /api/room`

### Query Parameters

All optional:

| Param | Type | Example | Behavior |
| --- | --- | --- | --- |
| `filter[<column>]` | string, or array for multiple values | `filter[status]=available` | Exact match on any real `rooms` column. `filter[status][]=available&filter[status][]=occupied` matches either. |
| `search` | string | `search=101` | Partial (`LIKE %term%`) match across the room's string-typed columns: `hotel_id`, `room_number`, `room_type`, `floor`, `status`. |
| `sort` | string | `sort=-created_at` | Sort by a real column. Prefix with `-` for descending. |
| `page` | integer | `page=2` | Page number, 1-indexed. |
| `per_page` | integer, 1–100 | `per_page=25` | Page size. Defaults to 15. |

`filter`/`sort` are validated against the rooms table's real columns: `id`, `hotel_id`, `room_number`, `room_type`, `floor`, `status`, `created_at`, `updated_at`. An unknown key in either returns a `422` (see below).

### Example Request

```
GET /api/room?filter[status]=available&search=101&sort=-created_at&per_page=20&page=1
```

### Success Response

HTTP `200 OK`. `index` serializes through `App\Http\Resources\RoomResource`, wrapped in a paginated Laravel resource collection, with `room_types` merged in as an extra top-level key:

```json
{
  "message": "Rooms fetched successfully.",
  "code": 200,
  "body": {
    "data": [
      { "...": "one room object, shape as above" }
    ],
    "links": {
      "first": "http://your-domain.com/api/room?page=1",
      "last": "http://your-domain.com/api/room?page=1",
      "prev": null,
      "next": null
    },
    "meta": {
      "current_page": 1,
      "from": 1,
      "last_page": 1,
      "links": [ { "url": null, "label": "&laquo; Previous", "page": null, "active": false } ],
      "path": "http://your-domain.com/api/room",
      "per_page": 15,
      "to": 1,
      "total": 1
    },
    "room_types": ["single", "double", "twin", "triple", "suite", "deluxe", "family"]
  }
}
```

Notes:

- The room array is at **`body.data`** (flat — this is Laravel's standard paginated resource collection shape, not a raw paginator).
- Pagination controls (`current_page`, `last_page`, `total`, etc.) are on **`body.meta`**; first/last/prev/next page URLs are on **`body.links`**.
- `body.room_types` is a fixed, hotel-independent list of the valid `room_type` values (plain strings, e.g. `"double"` — the same string you send back on create/update). Use it to populate a `room_type` dropdown instead of hard-coding the seven values.

Only `index` changed shape — `store`, `show`, `update`, `destroy` still return a bare [room object](#the-room-object) in `body`, unchanged.

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

## 2. Create a Room

### Endpoint

`POST /api/room`

### Request Body

```json
{
  "hotel_id": "019f9b37-c265-726d-a6fe-f7eaa7852636",
  "room_number": "101",
  "room_type": "double",
  "floor": "1",
  "status": "available"
}
```

### Validation Rules

| Field | Rules |
| --- | --- |
| `hotel_id` | **required**, string, must exist in `hotels.id` — see the hotel-scoping note below. |
| `room_number` | optional, string, max 255. |
| `room_type` | optional, must be one of `single`, `double`, `twin`, `triple`, `suite`, `deluxe`, `family` (see [`body.room_types`](#1-list-rooms)) — any other value returns a `422`. |
| `floor` | optional, string, max 255. |
| `status` | optional, string, max 255. Defaults to `"available"` if omitted. Not restricted to a fixed list server-side — enforce allowed values client-side. |

**Important — hotel scoping:** `hotel_id` must be an id the logged-in admin actually owns. The API does not silently substitute the user's own hotel here — you must pass it explicitly. In practice, for an admin managing only their own hotel, the frontend should hard-code `hotel_id` to that admin's own hotel (fetched once, e.g. from `GET /api/user` → the hotel relationship) rather than exposing a hotel picker, since attempting to use any other hotel id will be rejected (see below).

### Success Response

HTTP `201 Created`. `body` is a [room object](#the-room-object).

### Error: Missing Required Fields

HTTP `422`:

```json
{
  "message": "The given data was invalid.",
  "errors": {
    "hotel_id": ["The hotel id field is required."]
  }
}
```

### Error: Invalid `room_type`

HTTP `422`:

```json
{
  "message": "The given data was invalid.",
  "errors": {
    "room_type": ["The selected room type is invalid."]
  }
}
```

Same error applies on [update](#4-update-a-room) if `room_type` is included with a bad value.

### Error: Room For a Different Hotel

This uses the custom `message/code/body` format, **not** the validation-error shape, because it's a business-rule check rather than a field-shape check:

HTTP `403`:

```json
{
  "message": "The selected hotel does not belong to you.",
  "code": 403,
  "body": null
}
```

The UI should treat this as a form-level error (it's not tied to a specific input the way `errors.hotel_id` would be).

## 3. Get a Single Room

### Endpoint

`GET /api/room/{id}`

### Success Response

HTTP `200 OK`. `body` is a [room object](#the-room-object).

### Error: Not Found

HTTP `404` if the id doesn't exist at all — this is Laravel's default model-not-found response, not the custom wrapper:

```json
{ "message": "No query results for model [App\\Models\\Room] {id}" }
```

### Error: Belongs to a Different Hotel

HTTP `403` if the room exists but belongs to a different hotel than the logged-in admin's — again Laravel's default authorization-failure response, not the custom wrapper:

```json
{ "message": "This action is unauthorized." }
```

Treat this the same as a 404 in the UI (see [Who Can Call These Endpoints](#who-can-call-these-endpoints)).

## 4. Update a Room

### Endpoint

`PUT /api/room/{id}` (or `PATCH`, same behavior)

### Request Body

Send only the fields you want to change — every field is optional on update:

```json
{
  "status": "maintenance"
}
```

### Validation Rules

Same field-level rules as [create](#validation-rules), except every field is optional (`sometimes` instead of `required`).

**Note:** unlike the reservation endpoint, there is currently **no server-side guard preventing `hotel_id` from being changed** on update — if you include `hotel_id` in the payload, it will be validated (must exist in `hotels.id`) and saved as-is. The frontend should not include `hotel_id` in the edit form's payload at all, to avoid accidentally moving a room to a different hotel (which would also make it invisible/inaccessible to the current admin afterwards, since access is scoped by `hotel_id`).

### Success Response

HTTP `200 OK`. `body` is the updated [room object](#the-room-object).

### Error: Not Found / Wrong Hotel

Same as [show](#3-get-a-single-room): `404` if the id doesn't exist, `403` if it belongs to a different hotel.

## 5. Delete a Room

### Endpoint

`DELETE /api/room/{id}`

This is a **hard delete** — the row is permanently removed (rooms do not use `SoftDeletes`).

### Success Response

HTTP `200 OK`:

```json
{
  "message": "Room deleted successfully.",
  "code": 200,
  "body": null
}
```

### Error: Not Found / Wrong Hotel

Same as [show](#3-get-a-single-room): `404` if the id doesn't exist, `403` if it belongs to a different hotel.

## Validation Errors

For any field-shape validation failure (missing required field, wrong type, failed `exists` check), Laravel returns its default shape — **not** the custom `message/code/body` wrapper:

```json
{
  "message": "The given data was invalid.",
  "errors": {
    "field_name": ["Human-readable error message."]
  }
}
```

Always check for `errors` in the response body to distinguish this case from the custom-wrapper business-rule error (`hotel does not belong to you`) documented above, which uses `message/code/body` with a `403` even though it's not raw field validation.

## Example cURL Requests

### List (filtered, sorted, paginated)

```bash
curl -X GET "http://your-domain.com/api/room?filter[status]=available&sort=-created_at&per_page=20" \
  -H "Accept: application/json" \
  -H "X-API-KEY: YOUR_API_KEY" \
  -H "Authorization: Bearer USER_LOGIN_TOKEN"
```

### Create

```bash
curl -X POST http://your-domain.com/api/room \
  -H "Accept: application/json" \
  -H "Content-Type: application/json" \
  -H "X-API-KEY: YOUR_API_KEY" \
  -H "Authorization: Bearer USER_LOGIN_TOKEN" \
  -d '{
    "hotel_id": "019f9b37-c265-726d-a6fe-f7eaa7852636",
    "room_number": "101",
    "room_type": "double",
    "floor": "1",
    "status": "available"
  }'
```

### Update (partial)

```bash
curl -X PUT http://your-domain.com/api/room/019f9b37-c268-738c-bc46-53281c1763cf \
  -H "Accept: application/json" \
  -H "Content-Type: application/json" \
  -H "X-API-KEY: YOUR_API_KEY" \
  -H "Authorization: Bearer USER_LOGIN_TOKEN" \
  -d '{ "status": "maintenance" }'
```

### Delete

```bash
curl -X DELETE http://your-domain.com/api/room/019f9b37-c268-738c-bc46-53281c1763cf \
  -H "Accept: application/json" \
  -H "X-API-KEY: YOUR_API_KEY" \
  -H "Authorization: Bearer USER_LOGIN_TOKEN"
```

## Summary for the Frontend

- Every request needs `X-API-KEY` and `Authorization: Bearer {login_token}`.
- List with `GET /api/room`, filter with `filter[column]=value`, free-text search with `search=`, sort with `sort=column` / `sort=-column`, paginate with `page`/`per_page`.
- **Breaking change:** the room list is now at `body.data.data`, not `body.data` — `index`'s `body` is `{ data: <paginator>, room_types: [...] }`. Pagination fields (`current_page`, `last_page`, `total`) moved from `body.*` to `body.data.*`. See [List Rooms](#1-list-rooms).
- `body.room_types` (only on `index`) is the authoritative list of valid `room_type` values — use it to populate the type picker instead of hard-coding it.
- `status` is still a free-text string server-side — enforce your own fixed option list in the UI. `room_type` is **now a real server-enforced enum** (`single`/`double`/`twin`/`triple`/`suite`/`deluxe`/`family`) — an invalid value returns a `422`.
- `hotel_id` can be set on create but **should not** be included on update — the API doesn't block reassignment, but doing so can strand the room outside the current admin's access.
- Treat `403` on `show`/`update`/`destroy` the same as `404` in the UI — it means "not yours."
- Deleting a room is permanent (no soft delete) and does not cascade to reservations referencing it — confirm before deleting a room with existing reservations.
