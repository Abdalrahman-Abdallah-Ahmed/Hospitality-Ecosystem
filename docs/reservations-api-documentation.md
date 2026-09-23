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
- `POST /api/reservation/import` (bulk upload — see [§6](#6-import-reservations-bulk-upload))

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
- `Authorization: Bearer {login_token}` is required because every reservation route is inside the `auth:sanctum` middleware group. Get this token from `POST /api/login` (see `docs/auth-api-documentation.md`).
- Without a valid bearer token, the API returns HTTP `401 Unauthenticated.` before your controller/policy logic ever runs.

## Who Can Call These Endpoints

> **Staff roles (2026-09-15):** an employee whose [staff role](/D:/Hospitality%20Ecosystem/docs/staff-roles-api-documentation.md) grants the matching permission passes the `admin` checks below, always within their own hotel: `reservations.view` (index, show), `reservations.create`, `reservations.update`, `reservations.delete`, and `reservations.import` (import has its own permission and no longer reuses `create`). Employees without a role have none of these.

Every action is gated by `App\Policies\ReservationPolicy`, on top of the bearer-token check above:

| Action | Rule |
| --- | --- |
| `index` (list) | The logged-in user's `role` must be `admin`. |
| `store` (create) | The logged-in user's `role` must be `admin`. |
| `show` / `update` / `destroy` | The user must be `admin`, **and** the reservation's `hotel_id` must equal the hotel the user owns. |
| `import` (bulk upload) | `role` must be `admin` (or an employee with `reservations.import`). **Unlike every other write endpoint, a super admin cannot target an arbitrary hotel here** — see [§6](#6-import-reservations-bulk-upload). |

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

Every endpoint that returns a reservation returns it in this shape, with `hotel`, `guest`, and `rooms` (each with its `room_type` and `room`) always eager-loaded:

```json
{
  "id": "019f9b37-c26b-703f-bd9b-2ebe9eb03a55",
  "hotel_id": "019f9b37-c265-726d-a6fe-f7eaa7852636",
  "guest_id": "019f9b37-c266-7099-9793-ad875bf378d1",
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
  "rooms": [
    {
      "id": "019f9b37-d001-7000-a000-000000000001",
      "reservation_id": "019f9b37-c26b-703f-bd9b-2ebe9eb03a55",
      "room_type_id": "019f9b37-c267-7000-a000-00000000dlx1",
      "room_id": "019f9b37-c268-738c-bc46-53281c1763cf",
      "status": "reserved",
      "room_type": { "id": "...", "name": "Deluxe", "...": "..." },
      "room": { "id": "...", "room_number": "101", "...": "..." }
    },
    {
      "id": "019f9b37-d001-7000-a000-000000000002",
      "reservation_id": "019f9b37-c26b-703f-bd9b-2ebe9eb03a55",
      "room_type_id": "019f9b37-c267-7000-a000-00000000dlx1",
      "room_id": null,
      "status": "reserved",
      "room_type": { "id": "...", "name": "Deluxe", "...": "..." },
      "room": null
    }
  ],
  "room_summary": [
    { "room_type_id": "019f9b37-c267-7000-a000-00000000dlx1", "room_type_name": "Deluxe", "quantity": 2 }
  ]
}
```

**Reservation rooms ("lines").** A reservation books one or more room *units*; each is a line in `rooms`. "2 × Deluxe" is two lines. Each line names the room type booked (`room_type_id`) and, optionally, the physical room (`room_id`, `null` while unassigned). Lines have no dates of their own — they all share the reservation's `arrival_date`/`departure_date`. A line's `status` is `reserved` or `cancelled`; cancelled lines stay in `rooms` for history but hold no room and don't count in `room_summary`. `rooms` is ordered by creation; `room_summary` counts live (non-cancelled) lines per type.

Field notes for the UI:

- `id` is a UUID string, not an integer — don't parse it as a number.
- `arrival_date` / `departure_date` are date-only values but are serialized as full ISO datetimes at midnight UTC — display only the date portion.
- `reservation_value` is a string (e.g. `"450.50"`), not a JSON number, because it's a DB `decimal`. Parse it before doing arithmetic; don't rely on `typeof === 'number'`.
- `status` is one of: `pending`, `confirmed`, `checked_in`, `checked_out`, `cancelled` (see [Status Values](#status-values)).
- There is no top-level `room_id`/`room` any more (removed 2026-09-23) — read rooms from `rooms[]` and `room_summary`.
- `hotel`, `guest`, and each line's `room_type`/`room` are the full related records (same shape as their own resources), not just ids — you don't need a separate lookup call to render hotel name / guest name / room numbers on a reservation row.

### Status Values

| Value | Meaning |
| --- | --- |
| `pending` | Default status for a new reservation. |
| `confirmed` | Confirmed by the hotel. |
| `checked_in` | Guest has checked in. |
| `checked_out` | Guest has checked out. |
| `cancelled` | Reservation cancelled. |

Any other string is rejected by the API with a `422` on `status`.

**Side effect: room status follows the guest's actual stay, not the reservation's `status` field.** On create (`POST /api/reservation`, plus the WhatsApp ingestion endpoint) and update (`PUT /api/reservation/{id}`), the backend keeps the `status` of every physical room on the reservation's lines (`GET /api/room` will reflect this on the next fetch) in step with the underlying stay:

- A room is `occupied` while **any** checked-in reservation has it on a live line. Merely `confirmed` or `pending` does **not** occupy it, and booking a room for a future date does not free it while another guest is checked in.
- A checked-in reservation with several lines occupies **every** room on them.
- Once no guest is checked into the room (`checked_out`, `cancelled`, the line cancelled or moved), an `occupied` room is **freed back to `available`** automatically.
- A room in `maintenance` is left alone unless a guest actually checks into it.
- A room move (changing a line's `room_id`) re-syncs **both** rooms: the old one is freed if nobody else is checked into it.
- Lines with no room have nothing to sync.
- Edits to a reservation's dates, party size, value, currency or source are carried through to its stay. The reservation still has **one** stay (per-room stays arrive later); its `room_id` is the room of the first live line that has one.

## 1. List Reservations

### Endpoint

`GET /api/reservation`

### Query Parameters

All optional:

| Param | Type | Example | Behavior |
| --- | --- | --- | --- |
| `filter[<column>]` | string, or array for multiple values | `filter[status]=checked_in` | Exact match on any real `reservations` column. `filter[status][]=confirmed&filter[status][]=pending` matches either. |
| `filter[room_type_id]` | uuid | `filter[room_type_id]=019f…` | Reservations with **any live line** of this room type. Cancelled lines don't match. Filter only — cannot be used with `sort`. |
| `filter[room_id]` | uuid | `filter[room_id]=019f…` | Reservations with **any live line** on this physical room. Filter only — cannot be used with `sort`. |
| `search` | string | `search=RES-ABC` | Partial (`LIKE %term%`) match across the reservation's string-typed columns: `hotel_id`, `guest_id`, `reservation_id`, `status`, `source`, `special_requests`, `currency`. This does **not** search the joined guest's name or room numbers — see note below. |
| `sort` | string | `sort=-arrival_date` | Sort by a real column. Prefix with `-` for descending (e.g. `-arrival_date` = newest arrival first). |
| `page` | integer | `page=2` | Page number, 1-indexed. |
| `per_page` | integer, 1–100 | `per_page=25` | Page size. Defaults to 15. |

`filter`/`sort` are validated against the reservation table's real columns: `id`, `hotel_id`, `guest_id`, `reservation_id`, `arrival_date`, `departure_date`, `status`, `adults`, `children`, `source`, `special_requests`, `reservation_value`, `currency`, `created_at`, `updated_at`, `deleted_at` — plus, for `filter` only, the two line filters `room_type_id` and `room_id`. An unknown key in either returns a `422` (see below).

> **Note for the UI:** `search` only matches columns on the `reservations` row itself, not the nested guest's `first_name`/`last_name` — it's not useful as a "search by guest name" box (it won't find anything typed as a name). It's mainly useful for typing/pasting a full or partial `reservation_id` (e.g. `RES-ABC`) or status/source text; matching against `hotel_id`/`guest_id` only helps if the user is pasting a partial UUID, which is unlikely from this UI. Don't wire a "search by guest" field to this param — that would need a separate feature (searching through the `guest` relation).

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
      { "...": "one reservation object, shape as above, with hotel/guest/rooms/room_summary" }
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
  "reservation_id": "RES-ABC12345",
  "arrival_date": "2026-09-01",
  "departure_date": "2026-09-04",
  "status": "confirmed",
  "adults": 4,
  "children": 1,
  "source": "walk_in",
  "special_requests": "Late check-in",
  "reservation_value": 450.5,
  "currency": "USD",
  "rooms": [
    { "room_type_id": "<deluxe type id>", "quantity": 2 },
    { "room_type_id": "<suite type id>", "room_id": "<room 501 id>" }
  ],
  "capacity_override": false
}
```

This books three rooms: two unassigned Deluxe rooms and Suite room 501.

### Validation Rules

| Field | Rules |
| --- | --- |
| `hotel_id` | **required**, string, must exist in `hotels.id` — see the hotel-scoping note below. |
| `guest_id` | **required**, string, must exist in `guests.id`, and must belong to `hotel_id`. |
| `rooms` | **required**, array of 1–50 items. The total `quantity` across items must be ≤ 50. |
| `rooms.*.room_type_id` | **required**, uuid. Must be an **active**, non-deleted room type of this hotel; otherwise `422` on `rooms.{i}.room_type_id` ("The selected room type is not available." — the same message whether it is inactive, deleted, or another hotel's). |
| `rooms.*.quantity` | optional integer 1–50, default 1. Expanded into that many lines. |
| `rooms.*.room_id` | optional uuid. A room of this hotel whose type equals `room_type_id`; only allowed when `quantity` is 1. The same room can't appear on two lines. |
| `capacity_override` | optional boolean, default `false`. See capacity below. |
| `room_id` (top level) | **no longer accepted** — `422` "Use rooms[] instead." |
| `reservation_id` | **required**, string, max 255, must be unique **within the hotel** (two hotels may use the same code). A duplicate returns `422` on `reservation_id` ("The reservation id has already been taken."); a code held by a soft-deleted reservation of the same hotel restores that reservation instead. The UI should generate/let the user type a human-readable code (e.g. `RES-XXXXXXXX`) — the API does not auto-generate one on this endpoint (it does on the WhatsApp ingestion endpoint, but not here). |
| `arrival_date` | **required**, date. |
| `departure_date` | **required**, date. **Not currently validated against `arrival_date`** — the API will accept a `departure_date` before `arrival_date`; the frontend should enforce `departure_date >= arrival_date` client-side until this is added server-side. |
| `status` | optional, one of the [status values](#status-values). Defaults to `pending` if omitted. |
| `adults` | optional, integer. |
| `children` | optional, integer. |
| `source` | optional, string, max 255 (e.g. `walk_in`, `booking_com`, `phone`). |
| `special_requests` | optional, string. |
| `reservation_value` | optional, numeric. |
| `currency` | optional, string, exactly 3 characters (e.g. `USD`). |

**Capacity.** The party must fit the booked rooms as a whole: `adults + children` ≤ the sum of the lines' room-type `max_occupancy`, and `adults` ≤ the sum of their `adult_capacity`. Otherwise `422` on `rooms`, e.g. "The party (5 adults, 0 children) is larger than the booked rooms hold (4 guests, 4 adults). Add rooms, or send capacity_override to save it anyway." Staff can resend with `capacity_override: true` to save it anyway; each override is recorded in the audit log (`reservation.capacity_overridden`). The AI can never override.

**Important — hotel scoping:** `hotel_id` must be an id the logged-in admin actually owns. The API does not silently substitute the user's own hotel here — you must pass it explicitly, and it must match the hotel of the reservation's `guest_id` and of every room type and room in `rooms`. In practice, for an admin managing only their own hotel, the frontend should hard-code `hotel_id` to that admin's own hotel (fetched once, e.g. from `GET /api/user` → the hotel relationship) rather than exposing a hotel picker, since attempting to use any other hotel id will be rejected (see below).

### Success Response

HTTP `201 Created`. `body` is a [reservation object](#the-reservation-object).

No email is sent for reservations created through this endpoint. Only reservations the AI creates through WhatsApp (`source: "whatsapp"`) email the hotel's admins — see [AI Advisor Chat API](/D:/Hospitality%20Ecosystem/docs/ai-advisor-chat-api-documentation.md).

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

### Error: Guest From a Different Hotel

This uses the custom `message/code/body` format, **not** the validation-error shape, because it's a business-rule check rather than a field-shape check:

HTTP `422`:

```json
{
  "message": "The selected guest does not belong to this hotel.",
  "code": 422,
  "body": null
}
```

The UI should surface the message as a form-level error.

### Errors: Room Lines

These use the standard validation-error shape, keyed by the index of the item in `rooms`:

| Case | Key | Message |
| --- | --- | --- |
| No lines | `rooms` | "The rooms field is required." |
| More than 50 rooms | `rooms` | "A reservation can hold at most 50 rooms." |
| Room type inactive, deleted, or of another hotel | `rooms.{i}.room_type_id` | "The selected room type is not available." |
| Room of another hotel, or missing | `rooms.{i}.room_id` | "The selected room is not available." |
| Room of a different type than the line | `rooms.{i}.room_id` | "Room 501 is not of the line's room type." |
| `room_id` with `quantity` > 1 | `rooms.{i}.room_id` | "A physical room can only be set on a line with a quantity of 1." |
| Same room on two lines | `rooms.{i}.room_id` | "The same room cannot be on two lines of one reservation." |
| Party over capacity, no override | `rooms` | see Capacity above |

```json
{
  "message": "The selected room type is not available.",
  "errors": {
    "rooms.0.room_type_id": ["The selected room type is not available."]
  }
}
```

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
- `reservation_id`'s uniqueness check (within the hotel) excludes the reservation being edited, so re-submitting the same `reservation_id` it already has is fine. Changing it to a code another reservation of the hotel holds — including a soft-deleted one — returns `422` on `reservation_id`.
- If you include `guest_id`, it's checked against the reservation's *current* hotel (same rule as create).
- A top-level `room_id` is rejected ("Use rooms[] instead.").

### Changing the Rooms

`rooms` is optional on update. **Leave it out and the lines are not touched.** When you send it, it is the **full list of live lines the reservation should have afterwards**:

```json
{
  "rooms": [
    { "id": "<existing line A>" },
    { "id": "<existing line B>", "room_id": "<room 105 id>" },
    { "room_type_id": "<suite type id>", "quantity": 1 }
  ]
}
```

- An item **with `id`** keeps that line (same id, same room). `room_id` on it is optional: send a room to set or change it, `null` to clear it, or leave the key out to keep the current room. A `room_type_id`, if sent, must equal the line's type — a line's type can't change (cancel it and add a new line).
- An item **without `id`** is a new line, with the same rules as create (`room_type_id`, `quantity`, `room_id`).
- A live line **left out of the list** is cancelled (kept for history) and its room is released.
- `capacity_override` works as on create; the capacity check re-runs whenever lines, `adults` or `children` change.

What can change depends on the reservation's status **before** the update:

| Status | Add line | Remove line | Set / change / clear a line's room |
| --- | --- | --- | --- |
| `pending`, `confirmed` | yes | yes (not the last one) | yes (room must match the line's type) |
| `checked_in` | no | no | **room move only**: change to another room of the same type; can't clear |
| `checked_out`, `cancelled` | no | no | no |

Setting `status` to `cancelled` cancels every line and releases their rooms.

Errors (standard validation shape): an unknown or already-cancelled line `id` → `rooms.{i}.id`; an empty list → `rooms` "A reservation needs at least one room; cancel the reservation instead."; a disallowed change for the status → `rooms`, e.g. "Rooms cannot be changed on a checked_out reservation." or "Rooms cannot be added to a checked-in reservation; only room moves are allowed."; clearing a checked-in guest's room → `rooms.{i}.room_id` "A checked-in guest's room can be changed but not cleared."; plus every [room line error](#errors-room-lines) from create.

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

### Error: Guest From a Different Hotel

Same shape as the [create error above](#error-guest-from-a-different-hotel).

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

## 6. Import Reservations (Bulk Upload)

### Endpoint

`POST /api/reservation/import`

Bulk-creates reservations (and any guests/rooms they reference) from an uploaded spreadsheet, all scoped to the logged-in admin's own hotel. Controller: `App\Http\Controllers\ReservationController::import`, backed by `App\Imports\ReservationsImport`.

### Request

`multipart/form-data` with a single field:

| Field | Rules |
| --- | --- |
| `file` | **required**, a file, one of `xlsx` / `xls` / `csv` / `txt`, max 5120 KB (5 MB). |

```bash
curl -X POST http://your-domain.com/api/reservation/import \
  -H "Accept: application/json" \
  -H "X-API-KEY: YOUR_API_KEY" \
  -H "Authorization: Bearer USER_LOGIN_TOKEN" \
  -F "file=@reservations.csv"
```

Do **not** set `Content-Type: application/json` on this request — let the HTTP client set the `multipart/form-data` boundary itself (e.g. use `FormData` in the browser, not a JSON body).

### Expected Columns

The **first row must be a header row** naming these columns (order doesn't matter; unrecognized columns are ignored). Column names are matched case-insensitively and with spaces/punctuation folded to underscores (e.g. a header of `Guest Phone` is read the same as `guest_phone`) — matching Maatwebsite Excel's default heading normalization:

| Column | Required? | Notes |
| --- | --- | --- |
| `guest_phone` | **Required.** Row is skipped without it. | Guests are matched/deduped by phone number within the hotel — re-importing the same phone number reuses the existing guest instead of creating a duplicate. |
| `arrival_date` | **Required.** Row is skipped without it. | Any string `Carbon`/the DB can parse as a date. |
| `departure_date` | **Required.** Row is skipped without it. | Same as above. **Not validated against `arrival_date`** — a departure before arrival is not rejected. |
| `guest_first_name` | optional | |
| `guest_last_name` | optional | |
| `guest_email` | optional | |
| `room_number` | optional | If set and no room with that number exists yet for the hotel, **a new room is created automatically** (see below) rather than the row being rejected. The reservation gets one line of that room's type, on that room. |
| `room_type` | optional | A room type name, matched case-insensitively and **created when missing**. Used for a new room created from `room_number`, and — when there is no `room_number` — as the type of the reservation's single, unassigned line. With neither column, the line uses the hotel's default room type. |
| `floor` | optional | Same as `room_type` — only used if a new room is created. |
| `reservation_id` | optional | If omitted, one is auto-generated (`RES-XXXXXXXX`). If provided and it collides with an existing reservation for a *different* row already processed, that row is skipped (see below). |
| `status` | optional | Defaults to `pending`. **Any value other than the five real statuses (`pending`, `confirmed`, `checked_in`, `checked_out`, `cancelled`) causes that row to be skipped** — with a raw PHP error string as the reason (e.g. `"typo" is not a valid backing value for enum App\Enums\ReservationStatus`), not a friendly message. Show `skipped[].reason` as-is, or map known enum-error patterns to a nicer message client-side. |
| `adults` | optional | Cast to integer; defaults to `1` (falls back to `1` if the value is `0` or non-numeric, since the code uses `(int) ... ?: 1`). |
| `children` | optional | Cast to integer; defaults to `0`. |
| `source` | optional | Free text; defaults to `"import"`. |
| `special_requests` | optional | Free text. |
| `reservation_value` | optional | Cast to float; defaults to `0`. |
| `currency` | optional | Defaults to the hotel's own `currency`. |

### Behavior Notes

- **This endpoint does not use the same validation as manual create** (`POST /api/reservation`) — it bypasses `GenericStoreRequest`/enum casting for everything except `status` (which still throws because `Reservation.status` is a native PHP enum cast) and the numeric fields listed above. Garbage `currency`/`source`/`special_requests` values are written to the database as-is.
- Every imported reservation gets exactly **one room line** (see `room_number`/`room_type` above). The party-capacity check is **not** run on imports — legacy data is recorded as it is.
- A row is processed **independently** — one bad row (missing phone, invalid status, duplicate `reservation_id`, etc.) is caught and skipped; it does not fail the whole import.
- `room_number` with no existing match **creates the room** rather than rejecting the row. If your UI wants to warn the user before this happens, you'd need to cross-check `room_number` values against `GET /api/room` client-side before upload — the API gives no dry-run/preview mode.
- Guests are matched by `phone_number` scoped to the hotel; a soft-deleted guest with a matching phone number is restored rather than duplicated.
- **Super admins cannot target a different hotel with this endpoint.** Every other write endpoint in this API resolves the target hotel via a shared helper that lets a super admin pass an explicit `hotel_id` (since they have no hotel of their own). Import does not — it always uses `$request->user()->hotel`, so a super admin gets `403 You do not belong to any hotel.` calling this endpoint at all. If bulk-import needs to be super-admin-capable later, that's a backend gap, not something the frontend can work around.

### Success Response

HTTP `200 OK` — note the response shape here is **not** the paginator format used by list endpoints, and is **not** the imported reservation objects either, just counts:

```json
{
  "message": "Reservations imported successfully.",
  "code": 200,
  "body": {
    "imported": 42,
    "skipped": [
      { "row": 7, "reason": "Missing required field(s): guest_phone, arrival_date, or departure_date." },
      { "row": 15, "reason": "\"typo\" is not a valid backing value for enum App\\Enums\\ReservationStatus" }
    ]
  }
}
```

- `body.imported`: count of reservations successfully created.
- `body.skipped`: one entry per skipped row, `row` is **1-indexed and counts the header row** (so the first data row is `row: 2`), `reason` is a free-text string — sometimes a friendly message, sometimes a raw PHP exception message (see the `status` note above). Treat it as opaque text for display, not something to pattern-match on.
- To see the actual created reservations, re-fetch the list via `GET /api/reservation` — this endpoint doesn't return them.

### Error: Not Authenticated / Not Admin

`401` with no bearer token; `403` (custom wrapper, `message/code/body`) if the caller isn't an admin.

### Error: Missing/Invalid File

HTTP `422`, Laravel's default validation shape (not the custom wrapper):

```json
{
  "message": "The given data was invalid.",
  "errors": {
    "file": ["The file field is required."]
  }
}
```

Same shape for a wrong file type or a file over 5 MB, with a message specific to that rule.

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
    "departure_date": "2026-09-04",
    "adults": 2,
    "rooms": [{ "room_type_id": "019f9b37-c267-7000-a000-00000000dlx1", "quantity": 1 }]
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
- `guest_id` must belong to the same hotel as `hotel_id`, or you'll get a `422` with a plain-language `message` (not a field-level `errors` entry).
- **Rooms are lines (since 2026-09-23).** Create with `rooms: [{ room_type_id, quantity?, room_id? }]` (1–50 rooms, room types must be active); read `rooms[]` and `room_summary`. There is no top-level `room_id`/`room` any more. On update, `rooms` is the full desired list of live lines (`{ id }` keeps a line, no `id` adds one, omitted lines are cancelled); leave it out to keep the lines. A checked-in reservation only allows room moves. See [Changing the Rooms](#changing-the-rooms).
- An over-capacity party is rejected unless staff send `capacity_override: true` (audited).
- `filter[room_type_id]` / `filter[room_id]` match any live line; they can't be used to sort.
- The API does **not** enforce `departure_date >= arrival_date` yet — validate that client-side.
- Treat `403` on `show`/`update`/`destroy` the same as `404` in the UI — it means "not yours."
- Don't use this document for the WhatsApp reservation-creation flow — that's `POST /api/whatsapp-reservation`, unauthenticated (API-key only), and out of scope here.
- Room status follows who is actually in the room: occupied while any checked-in reservation has it on a live line (every room of a multi-room reservation), freed back to `available` once none does, and `maintenance` left alone. A future booking never frees an occupied room. See [Status Values](#status-values).
- `reservation_id` is unique per hotel, not across the platform. A row in an import whose code the hotel already uses is skipped with `Reservation id … already exists.`
- `POST /api/reservation/import` bulk-creates reservations from an uploaded `.xlsx`/`.xls`/`.csv`/`.txt` file (max 5 MB). It returns only `{ imported, skipped }` counts, not the created records — refresh the list separately. It skips bad rows instead of failing the whole file, and unlike every other write endpoint here, a super admin **cannot** target another hotel with it. See [§6](#6-import-reservations-bulk-upload).
