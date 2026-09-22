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

- `X-API-KEY` is checked by the `api.key` middleware against the server's configured `API_KEY`; a missing/wrong key returns HTTP `401`. The check is skipped only when no key is configured **and** the server runs in a `local` or `testing` environment — anywhere else, an unset key rejects every request.
- `Authorization: Bearer {login_token}` is required because every room route is inside the `auth:sanctum` middleware group. Get this token from `POST /api/login` (see `docs/auth-api-documentation.md`).
- Without a valid bearer token, the API returns HTTP `401 Unauthenticated.` before your controller/policy logic ever runs.

## Who Can Call These Endpoints

> **Staff roles (2026-09-15):** an employee whose [staff role](/D:/Hospitality%20Ecosystem/docs/staff-roles-api-documentation.md) grants the matching permission passes the `admin` checks below, always within their own hotel: `rooms.view` (index, show), `rooms.create`, `rooms.update`, `rooms.delete`. Employees without a role have none of these.

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

Every endpoint that returns a room returns it in this shape, with its room type embedded (but not the `hotel`):

```json
{
  "id": "019f9b37-c268-738c-bc46-53281c1763cf",
  "hotel_id": "019f9b37-c265-726d-a6fe-f7eaa7852636",
  "room_number": "101",
  "room_type_id": "019f9b37-c266-7a01-8f3c-2b8d1e4a9c10",
  "room_type": { "id": "019f9b37-c266-7a01-8f3c-2b8d1e4a9c10", "name": "Double", "...": "room type object, see room-types-api-documentation.md" },
  "floor": "1",
  "status": "available",
  "housekeeping_status": "clean",
  "created_at": "2026-07-25T21:39:10.000000Z",
  "updated_at": "2026-07-25T21:39:10.000000Z"
}
```

Field notes for the UI:

- `id` and `hotel_id` are UUID strings, not integers — don't parse them as numbers.
- `room_number` and `floor` are free-text strings and are nullable — a room can exist with either blank.
- `room_type_id` is **required** and points at one of the hotel's [room types](room-types-api-documentation.md). `room_type` is that type embedded as an object (not a string any more). The hotel's types are echoed on every `index` call as `body.room_types` so the frontend can build the picker without a second request.
- `status` is also a free-text string, not a restricted enum server-side (there's no `Rule::in`/cast enforcing specific values). It defaults to `"available"` at the database level when omitted on create. The API will accept any string here, so **the frontend should be the one constraining input** (e.g. a fixed dropdown of `available` / `occupied` / `maintenance` / whatever values the product actually uses) — don't rely on the server to reject typos.
- `housekeeping_status` **is** a real, server-enforced enum (`App\Enums\HousekeepingStatusesEnum`): `clean`, `dirty`, `blocked`. Unlike `status`, an invalid value here is rejected with a `422`, so you can bind a dropdown straight to those three and trust the server to back you up. It defaults to `"clean"` at the database level when omitted on create, and is never null.
- `status` and `housekeeping_status` are **two independent axes** — don't collapse them into one badge. `status` answers "can this room be sold" (`available` / `occupied` / `maintenance`); `housekeeping_status` answers "is it ready for a guest". A room can legitimately be `occupied` **and** `dirty` at the same time.
- `blocked` means **out of order** — a fault, a leak, an unfinished repair — not merely "needs cleaning". A blocked room should be excluded from assignment even when `status` still reads `available`, and the UI should make clearing it a deliberate action rather than something housekeeping ticks off in passing.
- `housekeeping_status` can change **without any user action**: a nightly job (`App\Jobs\MakeRoomDirtyOvernightJob`, scheduled `00:01` server time) flips every room holding an in-house stay to `dirty`, so housekeeping starts the day with an accurate worklist. It deliberately skips `blocked` rooms, so a fault is never silently downgraded to "just dirty". Don't cache a room's housekeeping status across a date boundary — refetch.
- There is no soft-delete on rooms — `DELETE` permanently removes the row (see [Delete a Room](#5-delete-a-room)).
- If a room has active reservations pointing at it (`reservations.room_id`), deleting it does **not** cascade-delete those reservations; their `room_id` is left pointing at a now-missing row (no `ON DELETE` rule enforced from this side). Consider warning the user before deleting a room that's referenced by upcoming reservations.

## 1. List Rooms

### Endpoint

`GET /api/room`

### Query Parameters

All optional:

| Param | Type | Example | Behavior |
| --- | --- | --- | --- |
| `filter[<column>]` | string, or array for multiple values | `filter[housekeeping_status]=dirty` | Exact match on any real `rooms` column. `filter[housekeeping_status][]=dirty&filter[housekeeping_status][]=blocked` matches either. |
| `search` | string | `search=101` | Partial (`LIKE %term%`) match across the room's string-typed columns: `room_number`, `floor`, `status`, `housekeeping_status`. |
| `sort` | string | `sort=-created_at` | Sort by a real column. Prefix with `-` for descending. |
| `page` | integer | `page=2` | Page number, 1-indexed. |
| `per_page` | integer, 1–100 | `per_page=25` | Page size. Defaults to 15. |

`filter`/`sort` are validated against the rooms table's real columns: `id`, `hotel_id`, `room_number`, `room_type_id`, `floor`, `status`, `housekeeping_status`, `created_at`, `updated_at`. An unknown key in either returns a `422` (see below).

### Example Request

```
GET /api/room?filter[status]=available&search=101&sort=-created_at&per_page=20&page=1
```

The housekeeping worklist — every room needing service, ordered by room number — is just a filter on the new column:

```
GET /api/room?filter[housekeeping_status]=dirty&sort=room_number
```

### Success Response

HTTP `200 OK`. `index` serializes through `App\Http\Resources\RoomResource`, wrapped in a paginated Laravel resource collection, with the hotel's `room_types` merged in as an extra top-level key:

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
    "room_types": [ { "id": "019f9b37-c266-7a01-8f3c-2b8d1e4a9c10", "name": "Double", "...": "room type object" } ]
  }
}
```

Notes:

- The room array is at **`body.data`** (flat — this is Laravel's standard paginated resource collection shape, not a raw paginator).
- Pagination controls (`current_page`, `last_page`, `total`, etc.) are on **`body.meta`**; first/last/prev/next page URLs are on **`body.links`**.
- `body.room_types` lists the hotel's room types (full room type objects, sorted by name). Send the chosen one's `id` as `room_type_id` on create/update. For a super admin it lists every hotel's types; filter them by `hotel_id` to match the room's hotel.

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
  "room_type_id": "019f9b37-c266-7a01-8f3c-2b8d1e4a9c10",
  "floor": "1",
  "status": "available",
  "housekeeping_status": "clean"
}
```

### Validation Rules

| Field | Rules |
| --- | --- |
| `hotel_id` | **required**, string, must exist in `hotels.id` — see the hotel-scoping note below. |
| `room_number` | optional, string, max 255. |
| `room_type_id` | **required**, uuid, must be a room type of the **same hotel** (see [`body.room_types`](#1-list-rooms)). Missing → `422`; another hotel's type → `403`. |
| `floor` | optional, string, max 255. |
| `status` | optional, string, max 255. Defaults to `"available"` if omitted. Not restricted to a fixed list server-side — enforce allowed values client-side. |
| `housekeeping_status` | optional, must be one of `clean`, `dirty`, `blocked` — any other value returns a `422`. Defaults to `"clean"` if omitted, so you can leave it out of the create form entirely. |

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

### Error: Room Type From Another Hotel

HTTP `403`:

```json
{
  "message": "The selected roomTypes does not belong to you.",
  "code": 403,
  "body": null
}
```

Same error applies on [update](#4-update-a-room) if `room_type_id` names another hotel's type.

### Error: Invalid `housekeeping_status`

HTTP `422`:

```json
{
  "message": "The given data was invalid.",
  "errors": {
    "housekeeping_status": ["The selected housekeeping status is invalid."]
  }
}
```

Same error applies on [update](#4-update-a-room). Note the contrast with `status`, which accepts any string — `housekeeping_status` is the one of the two the server actually validates.

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

Marking a room clean once housekeeping has serviced it is the same one-key `PUT`, and is likely the most frequent write this endpoint will see:

```json
{
  "housekeeping_status": "clean"
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
    "room_type_id": "019f9b37-c266-7a01-8f3c-2b8d1e4a9c10",
    "floor": "1",
    "status": "available",
    "housekeeping_status": "clean"
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

### Mark a room clean after servicing

```bash
curl -X PUT http://your-domain.com/api/room/019f9b37-c268-738c-bc46-53281c1763cf \
  -H "Accept: application/json" \
  -H "Content-Type: application/json" \
  -H "X-API-KEY: YOUR_API_KEY" \
  -H "Authorization: Bearer USER_LOGIN_TOKEN" \
  -d '{ "housekeeping_status": "clean" }'
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
- **Breaking change (2026-09-23):** rooms belong to a hotel-defined room type. Send `room_type_id` (required) instead of the old `room_type` string; `room_type` in responses is now the embedded room type object. `body.room_types` (only on `index`) lists the hotel's types for the picker.
- `status` is still a free-text string server-side — enforce your own fixed option list in the UI.
- **New field:** every room now also returns `housekeeping_status` — a server-enforced enum of `clean` / `dirty` / `blocked`, defaulting to `clean`. It's a **separate axis from `status`**, so render it as its own badge: `status` says whether the room can be sold, `housekeeping_status` says whether it's ready. `blocked` means out of order and should block assignment on its own. Filter the housekeeping worklist with `filter[housekeeping_status]=dirty`, and mark a room serviced with a one-key `PUT`. Expect it to change overnight without user action — a scheduled job dirties every in-house room at `00:01` server time (leaving `blocked` rooms alone), so refetch rather than caching it across a date boundary.
- `hotel_id` can be set on create but **should not** be included on update — the API doesn't block reassignment, but doing so can strand the room outside the current admin's access.
- Treat `403` on `show`/`update`/`destroy` the same as `404` in the UI — it means "not yours."
- Deleting a room is permanent (no soft delete) and does not cascade to reservations referencing it — confirm before deleting a room with existing reservations.
