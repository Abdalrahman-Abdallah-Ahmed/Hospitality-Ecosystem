# Reservations API Documentation

This document describes the reservation management APIs the frontend team should use to build the reservations list/detail/create/edit UI.

It covers the **admin-facing CRUD endpoints** only (`index`, `store`, `show`, `update`, `destroy`). It does **not** cover `POST /api/whatsapp-reservation`, which is a separate machine-to-machine ingestion endpoint used by the WhatsApp/AI extraction pipeline, not by the frontend.

## Base URL

All endpoints below are defined in `routes/api.php`, so they are served under:

`/api`

Examples:

- `GET /api/reservation`
- `POST /api/reservation`
- `GET /api/reservation/{id}`
- `PUT /api/reservation/{id}`
- `DELETE /api/reservation/{id}`

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
- `Authorization: Bearer {login_token}` is required because every reservation route is inside the `auth:sanctum` middleware group. Get this token from `POST /api/login` (see `docs/auth-api-documentation.md`).
- Without a valid bearer token, the API returns HTTP `401 Unauthenticated.` before your controller/policy logic ever runs.

## Who Can Call These Endpoints

Every action is gated by `App\Policies\ReservationPolicy`, on top of the bearer-token check above:

| Action | Rule |
| --- | --- |
| `index` (list) | The logged-in user's `role` must be `admin`. |
| `store` (create) | The logged-in user's `role` must be `admin`. |
| `show` / `update` / `destroy` | The user must be `admin`, **and** the reservation's `hotel_id` must equal the hotel the user owns. |

Practical implications for the UI:

- A non-admin user should never reach this screen — treat any `403` here as "this user shouldn't be able to see this page," not a recoverable in-page error.
- A `403` on `show`/`update`/`destroy` for a specific reservation id most likely means the id belongs to a different hotel (e.g. a stale link/bookmark) — show a "not found or not yours" style message rather than a raw permission error.
- There's currently no per-hotel scoping on `index`'s own authorization check, but the query itself is scoped to `where('hotel_id', <the user's hotel>)`, so the list only ever contains reservations for the user's own hotel.

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
- `body`: the actual payload (a reservation object, a paginated list, or `null`).

**Validation errors (`422`) are the one exception** — they are Laravel's default shape, not the `message/code/body` wrapper (see [Validation Errors](#validation-errors) below).

## The Reservation Object

Every endpoint that returns a reservation returns it in this shape, with `hotel`, `guest`, and `room` always eager-loaded:

```json
{
  "id": "019f9b37-c26b-703f-bd9b-2ebe9eb03a55",
  "hotel_id": "019f9b37-c265-726d-a6fe-f7eaa7852636",
  "guest_id": "019f9b37-c266-7099-9793-ad875bf378d1",
  "room_id": "019f9b37-c268-738c-bc46-53281c1763cf",
  "reservation_id": "RES-DOC0001",
  "arrival_date": "2026-09-01T00:00:00.000000Z",
  "departure_date": "2026-09-04T00:00:00.000000Z",
  "status": "confirmed",
  "adults": 2,
  "children": 1,
  "source": "booking_com",
  "special_requests": "Late check-in",
  "reservation_value": "450.50",
  "currency": "USD",
  "created_at": "2026-07-25T21:39:10.000000Z",
  "updated_at": "2026-07-25T21:39:10.000000Z",
  "hotel": { "id": "...", "name": "Grand Harbor Hotel", "slug": "...", "...": "..." },
  "guest": { "id": "...", "first_name": "Youssef", "last_name": "Kamal", "...": "..." },
  "room": { "id": "...", "room_number": "101", "...": "..." }
}
```

Field notes for the UI:

- `id` is a UUID string, not an integer — don't parse it as a number.
- `arrival_date` / `departure_date` are date-only values but are serialized as full ISO datetimes at midnight UTC — display only the date portion.
- `reservation_value` is a string (e.g. `"450.50"`), not a JSON number, because it's a DB `decimal`. Parse it before doing arithmetic; don't rely on `typeof === 'number'`.
- `status` is one of: `pending`, `confirmed`, `checked_in`, `checked_out`, `cancelled` (see [Status Values](#status-values)).
- `room_id` and `room` are `null` when no room has been assigned yet.
- `hotel`, `guest`, `room` are the full related records (same shape as their own resources), not just ids — you don't need a separate lookup call to render hotel name / guest name / room number on a reservation row.

### Status Values

| Value | Meaning |
| --- | --- |
| `pending` | Default status for a new reservation. |
| `confirmed` | Confirmed by the hotel. |
| `checked_in` | Guest has checked in. |
| `checked_out` | Guest has checked out. |
| `cancelled` | Reservation cancelled. |

Any other string is rejected by the API with a `422` on `status`.

**Side effect (new): setting `status` to `confirmed` occupies the room.** On both create (`POST /api/reservation`, plus the WhatsApp ingestion endpoint) and update (`PUT /api/reservation/{id}`), if the resulting reservation has `status: confirmed` **and** a non-null `room_id`, the backend automatically sets that room's own `status` to `occupied` (`GET /api/room` will reflect this on the next fetch). This is one-directional as of this writing:

- Cancelling, checking out, or otherwise moving a reservation away from `confirmed` does **not** free the room back to `available` — that's still a manual step through the room API.
- If your UI shows room availability, don't assume it self-corrects when a reservation is cancelled; you may need a separate "release room" action until the backend adds the reverse sync.

## 1. List Reservations

### Endpoint

`GET /api/reservation`

### Query Parameters

All optional:

| Param | Type | Example | Behavior |
| --- | --- | --- | --- |
| `filter[<column>]` | string, or array for multiple values | `filter[status]=checked_in` | Exact match on any real `reservations` column. `filter[status][]=confirmed&filter[status][]=pending` matches either. |
| `search` | string | `search=RES-ABC` | Partial (`LIKE %term%`) match across the reservation's string-typed columns: `hotel_id`, `guest_id`, `room_id`, `reservation_id`, `status`, `source`, `special_requests`, `currency`. This does **not** search the joined guest's name — see note below. |
| `sort` | string | `sort=-arrival_date` | Sort by a real column. Prefix with `-` for descending (e.g. `-arrival_date` = newest arrival first). |
| `page` | integer | `page=2` | Page number, 1-indexed. |
| `per_page` | integer, 1–100 | `per_page=25` | Page size. Defaults to 15. |

`filter`/`sort` are validated against the reservation table's real columns: `id`, `hotel_id`, `guest_id`, `room_id`, `reservation_id`, `arrival_date`, `departure_date`, `status`, `adults`, `children`, `source`, `special_requests`, `reservation_value`, `currency`, `created_at`, `updated_at`, `deleted_at`. An unknown key in either returns a `422` (see below).

> **Note for the UI:** `search` only matches columns on the `reservations` row itself, not the nested guest's `first_name`/`last_name` — it's not useful as a "search by guest name" box (it won't find anything typed as a name). It's mainly useful for typing/pasting a full or partial `reservation_id` (e.g. `RES-ABC`) or status/source text; matching against `hotel_id`/`guest_id`/`room_id` only helps if the user is pasting a partial UUID, which is unlikely from this UI. Don't wire a "search by guest" field to this param — that would need a separate feature (searching through the `guest` relation).

### Example Request

```
GET /api/reservation?filter[status]=checked_in&search=RES-ABC&sort=-arrival_date&per_page=20&page=1
```

### Success Response

HTTP `200 OK`. `body` is a Laravel paginator object:

```json
{
  "message": "Reservations fetched successfully.",
  "code": 200,
  "body": {
    "current_page": 1,
    "data": [
      { "...": "one reservation object, shape as above, with hotel/guest/room" }
    ],
    "first_page_url": "http://your-domain.com/api/reservation?page=1",
    "from": 1,
    "last_page": 1,
    "last_page_url": "http://your-domain.com/api/reservation?page=1",
    "links": [ { "url": null, "label": "&laquo; Previous", "page": null, "active": false } ],
    "next_page_url": null,
    "path": "http://your-domain.com/api/reservation",
    "per_page": 15,
    "prev_page_url": null,
    "to": 1,
    "total": 1
  }
}
```

For the UI: read the list from `body.data`, and drive pagination controls from `body.current_page`, `body.last_page`, and `body.total` (don't parse `links[].label` — it's server-rendered HTML for Blade views, not meant for a JS pagination widget).

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

## 2. Create a Reservation

### Endpoint

`POST /api/reservation`

### Request Body

```json
{
  "hotel_id": "019f9b37-c265-726d-a6fe-f7eaa7852636",
  "guest_id": "019f9b37-c266-7099-9793-ad875bf378d1",
  "room_id": "019f9b37-c268-738c-bc46-53281c1763cf",
  "reservation_id": "RES-ABC12345",
  "arrival_date": "2026-09-01",
  "departure_date": "2026-09-04",
  "status": "confirmed",
  "adults": 2,
  "children": 1,
  "source": "walk_in",
  "special_requests": "Late check-in",
  "reservation_value": 450.5,
  "currency": "USD"
}
```

### Validation Rules

| Field | Rules |
| --- | --- |
| `hotel_id` | **required**, string, must exist in `hotels.id` — see the hotel-scoping note below. |
| `guest_id` | **required**, string, must exist in `guests.id`, and must belong to `hotel_id`. |
| `room_id` | optional, string, must exist in `rooms.id`, and must belong to `hotel_id` if provided. |
| `reservation_id` | **required**, string, max 255, must be unique across all reservations. The UI should generate/let the user type a human-readable code (e.g. `RES-XXXXXXXX`) — the API does not auto-generate one on this endpoint (it does on the WhatsApp ingestion endpoint, but not here). |
| `arrival_date` | **required**, date. |
| `departure_date` | **required**, date. **Not currently validated against `arrival_date`** — the API will accept a `departure_date` before `arrival_date`; the frontend should enforce `departure_date >= arrival_date` client-side until this is added server-side. |
| `status` | optional, one of the [status values](#status-values). Defaults to `pending` if omitted. |
| `adults` | optional, integer. |
| `children` | optional, integer. |
| `source` | optional, string, max 255 (e.g. `walk_in`, `booking_com`, `phone`). |
| `special_requests` | optional, string. |
| `reservation_value` | optional, numeric. |
| `currency` | optional, string, exactly 3 characters (e.g. `USD`). |

**Important — hotel scoping:** `hotel_id` must be an id the logged-in admin actually owns. The API does not silently substitute the user's own hotel here — you must pass it explicitly, and it must match the reservation's `guest_id`/`room_id` hotel. In practice, for an admin managing only their own hotel, the frontend should hard-code `hotel_id` to that admin's own hotel (fetched once, e.g. from `GET /api/user` → the hotel relationship) rather than exposing a hotel picker, since attempting to use any other hotel id will be rejected (see below).

### Success Response

HTTP `201 Created`. `body` is a [reservation object](#the-reservation-object).

### Error: Missing Required Fields

HTTP `422`:

```json
{
  "message": "The given data was invalid.",
  "errors": {
    "hotel_id": ["The hotel id field is required."],
    "guest_id": ["The guest id field is required."],
    "reservation_id": ["The reservation id field is required."]
  }
}
```

### Error: Guest/Room From a Different Hotel

This uses the custom `message/code/body` format, **not** the validation-error shape, because it's a business-rule check rather than a field-shape check:

HTTP `422`:

```json
{
  "message": "The selected guest does not belong to this hotel.",
  "code": 422,
  "body": null
}
```

or

```json
{
  "message": "The selected room does not belong to this hotel.",
  "code": 422,
  "body": null
}
```

The UI should treat both the same way: surface the message as a form-level error (it's not tied to a specific input the way `errors.guest_id` would be).

## 3. Get a Single Reservation

### Endpoint

`GET /api/reservation/{id}`

### Success Response

HTTP `200 OK`. `body` is a [reservation object](#the-reservation-object).

### Error: Not Found

HTTP `404` if the id doesn't exist at all — this is Laravel's default model-not-found response, not the custom wrapper:

```json
{ "message": "No query results for model [App\\Models\\Reservation] {id}" }
```

### Error: Belongs to a Different Hotel

HTTP `403` if the reservation exists but belongs to a different hotel than the logged-in admin's — again Laravel's default authorization-failure response, not the custom wrapper:

```json
{ "message": "This action is unauthorized." }
```

Treat this the same as a 404 in the UI (see [Who Can Call These Endpoints](#who-can-call-these-endpoints)).

## 4. Update a Reservation

### Endpoint

`PUT /api/reservation/{id}` (or `PATCH`, same behavior)

### Request Body

Send only the fields you want to change — every field is optional on update:

```json
{
  "status": "checked_in",
  "adults": 3
}
```

### Validation Rules

Same field-level rules as [create](#validation-rules), except every field is optional (`sometimes` instead of `required`), and:

- **`hotel_id` cannot be changed.** If present in the payload and different from the reservation's current `hotel_id`, the API rejects the whole request — a reservation can't be moved to a different hotel through this endpoint. Don't include `hotel_id` in your edit form's payload at all.
- `reservation_id`'s uniqueness check correctly excludes the reservation being edited, so re-submitting the same `reservation_id` it already has is fine.
- If you include `guest_id`/`room_id`, they're checked against the reservation's *current* hotel (same rule as create).

### Success Response

HTTP `200 OK`. `body` is the updated [reservation object](#the-reservation-object).

### Error: Attempting to Reassign Hotel

HTTP `422`:

```json
{
  "message": "Reassigning a reservation to a different hotel is not allowed.",
  "code": 422,
  "body": null
}
```

### Error: Invalid Status

HTTP `422`:

```json
{
  "message": "The given data was invalid.",
  "errors": {
    "status": ["The selected status is invalid."]
  }
}
```

### Error: Guest/Room From a Different Hotel

Same shape as the [create error above](#error-guestroom-from-a-different-hotel).

### Error: Not Found / Wrong Hotel

Same as [show](#3-get-a-single-reservation): `404` if the id doesn't exist, `403` if it belongs to a different hotel.

## 5. Delete a Reservation

### Endpoint

`DELETE /api/reservation/{id}`

This is a **soft delete** — the record is retained in the database (the model uses `SoftDeletes`) but excluded from all normal queries, including `index` and `show`.

### Success Response

HTTP `200 OK`:

```json
{
  "message": "Reservation deleted successfully.",
  "code": 200,
  "body": null
}
```

### Error: Not Found / Wrong Hotel

Same as [show](#3-get-a-single-reservation): `404` if the id doesn't exist, `403` if it belongs to a different hotel.

## Validation Errors

For any field-shape validation failure (missing required field, wrong type, failed `exists`/`unique`/enum check), Laravel returns its default shape — **not** the custom `message/code/body` wrapper:

```json
{
  "message": "The given data was invalid.",
  "errors": {
    "field_name": ["Human-readable error message."]
  }
}
```

Always check for `errors` in the response body to distinguish this case from the custom-wrapper business-rule errors (`guardHotelScopedReferences`, hotel-reassignment) documented above, which use `message/code/body` with a `422` even though they're not raw field validation.

## Example cURL Requests

### List (filtered, sorted, paginated)

```bash
curl -X GET "http://your-domain.com/api/reservation?filter[status]=confirmed&sort=-arrival_date&per_page=20" \
  -H "Accept: application/json" \
  -H "X-API-KEY: YOUR_API_KEY" \
  -H "Authorization: Bearer USER_LOGIN_TOKEN"
```

### Create

```bash
curl -X POST http://your-domain.com/api/reservation \
  -H "Accept: application/json" \
  -H "Content-Type: application/json" \
  -H "X-API-KEY: YOUR_API_KEY" \
  -H "Authorization: Bearer USER_LOGIN_TOKEN" \
  -d '{
    "hotel_id": "019f9b37-c265-726d-a6fe-f7eaa7852636",
    "guest_id": "019f9b37-c266-7099-9793-ad875bf378d1",
    "reservation_id": "RES-ABC12345",
    "arrival_date": "2026-09-01",
    "departure_date": "2026-09-04"
  }'
```

### Update (partial)

```bash
curl -X PUT http://your-domain.com/api/reservation/019f9b37-c26b-703f-bd9b-2ebe9eb03a55 \
  -H "Accept: application/json" \
  -H "Content-Type: application/json" \
  -H "X-API-KEY: YOUR_API_KEY" \
  -H "Authorization: Bearer USER_LOGIN_TOKEN" \
  -d '{ "status": "checked_in" }'
```

### Delete

```bash
curl -X DELETE http://your-domain.com/api/reservation/019f9b37-c26b-703f-bd9b-2ebe9eb03a55 \
  -H "Accept: application/json" \
  -H "X-API-KEY: YOUR_API_KEY" \
  -H "Authorization: Bearer USER_LOGIN_TOKEN"
```

## Summary for the Frontend

- Every request needs `X-API-KEY` and `Authorization: Bearer {login_token}`.
- List with `GET /api/reservation`, filter with `filter[column]=value`, free-text search with `search=`, sort with `sort=column` / `sort=-column`, paginate with `page`/`per_page`.
- Read the list from `body.data`; drive pagination UI from `body.current_page` / `body.last_page` / `body.total`.
- `reservation_value` comes back as a **string** — parse before doing math with it.
- `hotel_id` can be set on create but **never** changed on update — don't put it in the edit form.
- `guest_id`/`room_id` must belong to the same hotel as `hotel_id`, or you'll get a `422` with a plain-language `message` (not a field-level `errors` entry).
- The API does **not** enforce `departure_date >= arrival_date` yet — validate that client-side.
- Treat `403` on `show`/`update`/`destroy` the same as `404` in the UI — it means "not yours."
- Don't use this document for the WhatsApp reservation-creation flow — that's `POST /api/whatsapp-reservation`, unauthenticated (API-key only), and out of scope here.
- Confirming a reservation (`status: confirmed`) with a `room_id` set auto-occupies the room server-side — but nothing frees it back up on cancel/checkout yet. See [Status Values](#status-values).
