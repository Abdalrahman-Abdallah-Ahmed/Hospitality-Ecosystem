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
- `GET /api/reservation/imports`, `GET /api/reservation/imports/{id}`, `GET /api/reservation/imports/{id}/rows`, `GET /api/reservation/imports/{id}/report`

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
| `import` (bulk upload), import history and reports | `role` must be `admin` (or an employee with `reservations.import`). A super admin passes `hotel_id` — see [§6](#6-import-reservations-bulk-upload). |

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

### Reservation guests and children's ages (2026-10-14)

`guest_id` and `guest` remain the primary guest for compatibility. Responses also include `guests` as full guest objects with an `is_primary` marker, plus `guest_count` and `children_ages`. Soft-deleted guest profiles are omitted from `guests` and `guest_count`; the reservation's historical `guest_id` remains unchanged.

On create or update, send `guests` as the complete desired list. Each entry uses an existing `guest_id`, or contact details for a guest to resolve or create. Phone matching takes precedence; an email is reused only when it identifies one same-hotel guest. Ambiguous emails and phone/email conflicts are rejected. Exactly one entry must set `is_primary: true`; a single entry can omit it and becomes primary. If top-level `guest_id` is supplied, it must match the resolved primary. On create and update, `guest_id` may be omitted when `guests` is sent: the primary entry may then be a new guest named by contact details, and `guest_id` follows the resolved primary. On create, a guest profile made this way is saved only if the reservation is; a create that fails (validation, capacity, overbooking) leaves no new guest behind. Guest entries must belong to the reservation's hotel, cannot repeat, and cannot exceed `adults + children`. An update that includes `guests` replaces the complete list; removing a guest does not delete their profile. An update that lowers `adults` or `children` below the number of named guests is rejected with a `guests` error; remove guests first.

`children_ages` is an ordered integer array from 0 through 17. A new reservation with children must include one age per child. On update, if `children` or `children_ages` is supplied, the resulting ages count must equal `children`; legacy reservations with no ages can still receive unrelated updates.

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
  "children_ages": [7],
  "guest_count": 2,
  "guests": [
    { "id": "019f9b37-c266-7099-9793-ad875bf378d1", "first_name": "Youssef", "last_name": "Kamal", "is_primary": true },
    { "id": "019f9b37-c267-7099-9793-ad875bf378d2", "first_name": "Maya", "last_name": "Kamal", "is_primary": false }
  ],
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
      "cancelled_with_reservation": false,
      "room_type": { "id": "...", "name": "Deluxe", "...": "..." },
      "room": { "id": "...", "room_number": "101", "...": "..." }
    },
    {
      "id": "019f9b37-d001-7000-a000-000000000002",
      "reservation_id": "019f9b37-c26b-703f-bd9b-2ebe9eb03a55",
      "room_type_id": "019f9b37-c267-7000-a000-00000000dlx1",
      "room_id": null,
      "status": "reserved",
      "cancelled_with_reservation": false,
      "room_type": { "id": "...", "name": "Deluxe", "...": "..." },
      "room": null
    }
  ],
  "room_summary": [
    { "room_type_id": "019f9b37-c267-7000-a000-00000000dlx1", "room_type_name": "Deluxe", "quantity": 2 }
  ]
}
```

**Reservation rooms ("lines").** A reservation books one or more room *units*; each is a line in `rooms`. "2 × Deluxe" is two lines. Each line names the room type booked (`room_type_id`) and, optionally, the physical room (`room_id`, `null` while unassigned). Lines have no dates of their own — they all share the reservation's `arrival_date`/`departure_date`. A line's `status` is `reserved` or `cancelled`; cancelled lines stay in `rooms` for history but hold no room and don't count in `room_summary`. `cancelled_with_reservation` is `true` on a line cancelled because its whole reservation was: exactly the lines un-cancelling the reservation brings back. `rooms` is ordered by creation; `room_summary` counts live (non-cancelled) lines per type.

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
| `checked_in` | At least one of its rooms has checked in (set by check-in). |
| `checked_out` | All of its rooms have checked out (set by check-out). |
| `cancelled` | Reservation cancelled. |
| `no_show` | The guest never arrived (2026-10-21). Set only by the no-show endpoints below, or by the import. Holds no rooms. |

Any other string is rejected by the API with a `422` on `status`.

### Status lifecycle (2026-10-21)

A status can only change along these paths, through the operation named. Anything else is
`422` on `status`, naming both statuses, e.g. "A checked_out reservation can't be changed
to pending."

| From | To | Through |
| --- | --- | --- |
| (new) | `pending`, `confirmed` | `POST /api/reservation` |
| `pending` | `confirmed` | `PUT /api/reservation/{id}` |
| `confirmed` | `pending` | `PUT /api/reservation/{id}` |
| `pending`, `confirmed` | `cancelled` | `PUT /api/reservation/{id}` |
| `cancelled` | `pending`, `confirmed` | `PUT /api/reservation/{id}` (availability re-checked) |
| `confirmed` | `checked_in` | check-in (first room in) |
| `checked_in` | `checked_out` | check-out (last room out) |
| `pending`, `confirmed` | `no_show` | `POST /api/reservation/{id}/no-show`, or the last waiting room marked a no-show |
| `no_show` | the status it had (`pending` or `confirmed`) | `POST /api/reservation/{id}/undo-no-show` |

- `status: no_show` on create or edit → `422`: "Use POST /reservation/{id}/no-show."
- Any status on a `no_show` reservation → `422`: "Use POST /reservation/{id}/undo-no-show."
- The reservation import still records past statuses (`checked_out`, `no_show`) as they were.

### No-shows (2026-10-21)

Needs `reservations.no_show` (not a default; admins have it). Every body is optional:
`{ "reason": "Flight cancelled" }` (string, max 500). The reason is stored with the action
in the reservation's history (`reservation.no_show_marked` / `reservation.no_show_undone`).

| Endpoint | Does |
| --- | --- |
| `POST /api/reservation/{id}/no-show` | Every waiting room becomes a no-show; the reservation becomes `no_show` |
| `POST /api/reservation/{id}/undo-no-show` | Back to the status it had; its no-show rooms wait to check in again |
| `POST /api/stays/{id}/no-show` | One room becomes a no-show (see [stays-api-documentation.md](stays-api-documentation.md)) |
| `POST /api/stays/{id}/undo-no-show` | One room waits again (only while the reservation is not checked out) |

Rules:

- From the arrival day (hotel time); before that → `422` on `stays`.
- A whole no-show needs a `pending` or `confirmed` reservation with no room checked in or
  out. Otherwise mark the rooms that did not arrive one at a time.
- A no-show releases its rooms at once: availability counts them free, the room can be
  assigned to another booking, and no cleaning task is made.
- An undo works at any time, even after the dates have passed. It re-checks availability:
  rooms sold since → `422` on `rooms`, unless `overbook_override: true` with
  `reservations.overbook` (`403` without it).
- No message goes to the guest. Pending recommendations expire and nothing is pitched.
- Repeating a mark or an undo that is already done changes nothing and returns `200`.
- On undo, a room that was given to another booking for overlapping nights while this one
  was a no-show is taken off the restored line (it stays on the other booking), and
  `warnings` says so: assign another room before check-in. Restored rooms also pick up any
  date changes made in the meantime.
- A room can't be restored on a cancelled reservation, or when its own line was cancelled
  (`422` on `stays`).

Response (`200`): `{ "reservation": ReservationResource, "stays": [stay objects], "warnings": [] }`,
with message "Marked as a no-show." or "No-show undone."

**While a reservation is `no_show`** only `guests` and `special_requests` can change on
`PUT /api/reservation/{id}`. Changing any other field (dates, party, `rooms`, `source`,
`reservation_value`, `currency`, `reservation_id`, `guest_id`) → `422` on that field:
"Undo the no-show first." Sending a field with the value it already has is not a change.

**Room lines** now carry `stay_id` and `stay_status` (`expected`, `in_house`, `departed`,
`no_show`, `cancelled`); both are `null` for a legacy line without a stay. Use `stay_id`
for the per-room endpoints (`/api/stays/{id}/...`) without listing the stays.

**Checking in and out.** Use the check-in and check-out endpoints in
[stays-api-documentation.md](stays-api-documentation.md): `POST /api/reservation/{id}/check-in`,
`POST /api/reservation/{id}/check-out`, and the per-room forms under `/api/stays/{id}`.
They set `checked_in` / `checked_out` for you.

**Deprecated: setting `checked_in` / `checked_out` here.** It still works in this release,
but it now runs the real whole-reservation check-in or check-out, with all of its rules
(confirmed reservation, arrival date reached, every room assigned and free, …) and needs
`stays.check_in` / `stays.check_out` on top of `reservations.update` (`403` otherwise). A
room cannot be named on this path, so every line must already have a room. The response
carries `Deprecation: true`, a `Link` header to the replacement endpoint, and a sentence
in `message`. **A later release will reject it: move to the check-in/out endpoints.**

- `POST /api/reservation` with `status: checked_in` creates the reservation `confirmed`
  and checks it in, the same way (deprecated, same headers).
- `POST /api/reservation` with `status: checked_out` → `422`: only the import records
  past stays.
- While any room is in the house, `status` can't be changed to anything but
  `checked_out` (through check-out): `422` "Check out the in-house rooms first." on
  `status`. This includes `cancelled`.

**Side effect: room status follows the guests' actual stays.** Every line has its own stay (see [stays-api-documentation.md](stays-api-documentation.md)), and the backend keeps the `status` of every physical room in step with them (`GET /api/room` will reflect this on the next fetch):

- A room is `occupied` while a guest is checked into it (its stay is `in_house`). Merely `confirmed` or `pending` does **not** occupy it, and booking a room for a future date does not free it while another guest is in it.
- Each room of a multi-room reservation is occupied when **its** guest checks in.
- Once nobody is in the room (checked out, or the guest moved rooms), an `occupied` room is **freed back to `available`** automatically. A room a guest moved out of is also marked housekeeping `dirty`.
- A room in `maintenance` is left alone unless a guest actually checks into it.
- A room move (changing a line's `room_id`) re-syncs **both** rooms: the old one is freed if nobody else is checked into it.
- Lines with no room have nothing to sync.
- Edits to a reservation's dates, party size, value, currency or source, and a line's room, are carried through to the lines' stays. Check-in and check-out times are never changed by an edit.

## 1. List Reservations

### Endpoint

`GET /api/reservation`

### Query Parameters

All optional:

| Param | Type | Example | Behavior |
| --- | --- | --- | --- |
| `filter[<column>]` | string, or array for multiple values | `filter[status]=checked_in` | Exact match on any real `reservations` column. `filter[status][]=confirmed&filter[status][]=pending` matches either. |
| `filter[room_type_id]` | uuid, or array for multiple values | `filter[room_type_id]=019f…` | Reservations with **any live line** of this room type (or of any listed type). Cancelled lines don't match. An empty value is ignored; a value that isn't a uuid matches nothing. Filter only — cannot be used with `sort`. |
| `filter[room_id]` | uuid, or array for multiple values | `filter[room_id]=019f…` | Reservations with **any live line** on this physical room (or any listed room). Same value rules. Filter only — cannot be used with `sort`. |
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

HTTP `200 OK`. The list goes through `ReservationResource::collection()`, so `body` is Laravel's **paginated resource** shape: `data`, `links` and `meta`. It is **not** the raw paginator; there is no `body.current_page`.

```json
{
  "message": "Reservations fetched successfully.",
  "code": 200,
  "body": {
    "data": [
      { "...": "one reservation object, shape as above, with hotel/guest/rooms/room_summary" }
    ],
    "links": {
      "first": "http://your-domain.com/api/reservation?page=1",
      "last": "http://your-domain.com/api/reservation?page=1",
      "prev": null,
      "next": null
    },
    "meta": {
      "current_page": 1,
      "from": 1,
      "last_page": 1,
      "links": [ { "url": null, "label": "&laquo; Previous", "page": null, "active": false } ],
      "path": "http://your-domain.com/api/reservation",
      "per_page": 15,
      "to": 1,
      "total": 1
    }
  }
}
```

For the UI: read the list from `body.data`, and drive pagination controls from `body.meta.current_page`, `body.meta.last_page` and `body.meta.total` (or `body.links.next` / `body.links.prev`). Don't parse `meta.links[].label`: it's server-rendered HTML for Blade views, not meant for a JS pagination widget.

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
  "children_ages": [7],
  "guests": [
    { "guest_id": "019f9b37-c266-7099-9793-ad875bf378d1", "is_primary": true },
    { "first_name": "Maya", "last_name": "Kamal", "phone_number": "+201001234568", "is_primary": false }
  ],
  "source": "walk_in",
  "special_requests": "Late check-in",
  "reservation_value": 450.5,
  "currency": "USD",
  "rooms": [
    { "room_type_id": "<deluxe type id>", "quantity": 2 },
    { "room_type_id": "<suite type id>", "room_id": "<room 501 id>" }
  ],
  "capacity_override": false,
  "overbook_override": false
}
```

This books three rooms: two unassigned Deluxe rooms and Suite room 501.

### Validation Rules

| Field | Rules |
| --- | --- |
| `hotel_id` | **required**, string, must exist in `hotels.id` — see the hotel-scoping note below. |
| `guest_id` | **required unless `guests` is sent**, string, must exist in `guests.id`, and must belong to `hotel_id`. It remains the primary guest pointer and must match the primary entry in `guests` when that list is supplied. Omit it to book a new primary named by contact details in `guests`; it is set to the resolved primary. Sending neither `guest_id` nor `guests` → `422` on `guest_id`. |
| `rooms` | **required**, array of 1–50 items. The total `quantity` across items must be ≤ 50. |
| `rooms.*.room_type_id` | **required**, uuid. Must be an **active**, non-deleted room type of this hotel; otherwise `422` on `rooms.{i}.room_type_id` ("The selected room type is not available." — the same message whether it is inactive, deleted, or another hotel's). |
| `rooms.*.quantity` | optional integer 1–50, default 1. Expanded into that many lines. |
| `rooms.*.room_id` | optional uuid. A room of this hotel whose type equals `room_type_id`; only allowed when `quantity` is 1. The same room can't appear on two lines. |
| `capacity_override` | optional boolean, default `false`. See capacity below. |
| `overbook_override` | optional boolean, default `false`. Save even if a room type is short. Needs `reservations.overbook`. See [Overbooking](#overbooking). |
| `room_id` (top level) | **no longer accepted** — `422` "Use rooms[] instead." |
| `reservation_id` | **required**, string, max 255, must be unique **within the hotel** (two hotels may use the same code). A duplicate returns `422` on `reservation_id` ("The reservation id has already been taken."); a code held by a soft-deleted reservation of the same hotel restores that reservation instead. The UI should generate/let the user type a human-readable code (e.g. `RES-XXXXXXXX`) — the API does not auto-generate one on this endpoint (it does on the WhatsApp ingestion endpoint, but not here). |
| `arrival_date` | **required**, date. |
| `departure_date` | **required**, date. **Not currently validated against `arrival_date`** — the API will accept a `departure_date` before `arrival_date`; the frontend should enforce `departure_date >= arrival_date` client-side until this is added server-side. |
| `status` | optional, one of the [status values](#status-values). Defaults to `pending` if omitted. |
| `adults` | optional, integer. |
| `children` | optional, integer. |
| `children_ages` | optional when there are no children; otherwise required. Ordered array of integers from 0 through 17, exactly one per child. Ages sent with `children: 0` are rejected. |
| `guests` | optional complete guest list. Each item has either a same-hotel `guest_id` or contact details (`phone_number` and/or `email`, optionally names), and one item must be primary when there is more than one. A single item may omit `is_primary`. |
| `source` | optional, string, max 255 (e.g. `walk_in`, `booking_com`, `phone`). |
| `special_requests` | optional, string. |
| `reservation_value` | optional, numeric. |
| `currency` | optional, string, exactly 3 characters (e.g. `USD`). |

**Capacity.** The party must fit the booked rooms as a whole: `adults + children` ≤ the sum of the lines' room-type `max_occupancy`, and `adults` ≤ the sum of their `adult_capacity`. Otherwise `422` on `rooms`, e.g. "The party (5 adults, 0 children) is larger than the booked rooms hold (4 guests, 4 adults). Add rooms, or send capacity_override to save it anyway." Staff can resend with `capacity_override: true` to save it anyway; each override is recorded in the audit log (`reservation.capacity_overridden`). The AI can never override.

### Overbooking

A reservation cannot sell more rooms of a type than the hotel has free on any night (see
[availability-api-documentation.md](availability-api-documentation.md)). Every booked line
uses one room of its type on each night from `arrival_date` up to, but not including,
`departure_date`, whether or not it has a physical room.

Only what the request **adds** is checked: new reservations (including a deleted one
re-created with the same `reservation_id`), added lines, moved or extended dates, and
bringing a cancelled reservation back (its lines return). The
reservation's own current lines never count against it. Changes that only free rooms
(removing lines, shortening the stay, cancelling) and changes that add nothing (special
requests, party size, `pending` → `confirmed`) are never checked, even on a night that is
already overbooked.

When a room type is short, the request fails with `422` and nothing is saved:

```json
{
  "message": "Not enough rooms available: Deluxe is short by 1 on 2026-03-13.",
  "errors": {
    "rooms": ["Not enough rooms available: Deluxe is short by 1 on 2026-03-13."]
  },
  "shortfalls": [
    {
      "room_type_id": "9f1c2d7e-…",
      "room_type_name": "Deluxe",
      "nights": [ { "date": "2026-03-13", "short": 1 } ]
    }
  ]
}
```

`shortfalls` lists only room types from this request. To save anyway, resend with
`overbook_override: true`. This needs the `reservations.overbook` permission on top of
`reservations.create` / `reservations.update`. Admins have it. Sending
`overbook_override: true` without it returns `403` before anything is written, even if the
booking would have fitted. Each override that was actually needed is recorded in the audit
log (`reservation.overbooking_overridden`, with the actor and the shortfalls). The AI can
never override.

Two bookings for the last room of a type are handled one at a time: the second one sees
the first and gets the `422`.

**Important — hotel scoping:** `hotel_id` must be an id the logged-in admin actually owns. The API does not silently substitute the user's own hotel here — you must pass it explicitly, and it must match the hotel of the reservation's `guest_id` and of every room type and room in `rooms`. In practice, for an admin managing only their own hotel, the frontend should hard-code `hotel_id` to that admin's own hotel (fetched once, e.g. from `GET /api/user` → the hotel relationship) rather than exposing a hotel picker, since attempting to use any other hotel id will be rejected (see below).

### Success Response

HTTP `201 Created`. `body` is a [reservation object](#the-reservation-object).

**A new primary guest (walk-in or first-time guest):** leave out `guest_id` and name the
primary in `guests` by contact details. The guest is matched or created by the same rules as
any other entry, and the reservation's `guest_id` is set to it:

```json
"guests": [
  { "first_name": "Omar", "last_name": "Fathy", "phone_number": "+201001112223", "email": "omar@example.com", "is_primary": true }
]
```

Errors on that entry use the same keys as any other entry: `guests.{i}` (no phone or email,
ambiguous email, phone and email naming different guests), `guests.{i}.guest_id`,
`guests.{i}.is_primary`, and `guests` (not exactly one primary, list larger than the party,
`guest_id` sent but not matching the primary).

No email is sent for reservations created through this endpoint. Only reservations the AI creates through WhatsApp (`source: "whatsapp"`) email the hotel's admins — see [AI Advisor Chat API](/D:/Hospitality%20Ecosystem/docs/ai-advisor-chat-api-documentation.md).

### Error: Missing Required Fields

HTTP `422`:

```json
{
  "message": "The given data was invalid.",
  "errors": {
    "hotel_id": ["The hotel id field is required."],
    "guest_id": ["The guest id field is required when guests is not present."],
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
- `overbook_override` works as on create. Only added lines and new or longer dates are checked against availability; see [Overbooking](#overbooking).

What can change depends on the reservation's status **before** the update:

| Status | Add line | Remove line | Set / change / clear a line's room |
| --- | --- | --- | --- |
| `pending`, `confirmed` | yes | yes (not the last one) | yes (room must match the line's type) |
| `checked_in` | no | only a line whose guest never checked in (to close the reservation, see [check-out](stays-api-documentation.md#check-out)) | **room move only**: change to another room of the same type; can't clear |
| `checked_out`, `cancelled` | no | no | no |

Setting `status` to `cancelled` cancels every line and releases their rooms. It is refused while any guest is in the house, and so is removing a line whose guest is in: `422` "Check out the in-house rooms first." / "Room 204 is checked in; check it out first."

**Bringing a cancelled reservation back.** Changing a `cancelled` reservation to any other status restores the lines it had when it was cancelled; lines staff had removed before the cancellation stay removed. To choose different lines instead, send `rooms` in the same request: an item with `id` may name any of the reservation's lines (it is reinstated, optionally with a new `room_id`), items without `id` add lines, and lines left out stay cancelled. The capacity check runs either way.

Line ids, `room_type_id` and `room_id` are accepted in any letter case.

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

This is a **soft delete** — the record is retained in the database (the model uses `SoftDeletes`) but excluded from all normal queries, including `index` and `show`. Its stays are soft-deleted with it and leave every stays list.

A reservation with a guest in the house can't be deleted: `422` on `reservation`, "Check out the in-house rooms first."

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

> **Changed 2026-10-23 (SPEC-100/101, breaking):** the upload is queued and returns `202`
> with an import record. Progress and the reconciliation report are read from the
> `/api/reservation/imports` endpoints below. The old `200 { imported, skipped }` response
> is gone. See `docs/latest-changes-2026-10-23.md`.

An import moves a hotel's reservations from its previous PMS. Every file goes through the
same stages, and every data row ends with one outcome and a reason:

```
parse → normalize → validate → map → import → report
```

Controller: `ReservationController::import` (upload) and `ReservationImportController`
(history, status, rows, report). The pipeline lives in `app/Support/Import/Reservations`
and runs in `PrepareReservationImportJob` and `WriteReservationImportJob`.

### Endpoints

All need `reservations.import` and the same-hotel check. Another hotel's import returns
`404` or `403`.

| Method | Path | Does |
| --- | --- | --- |
| `POST` | `/api/reservation/import` | Upload a file; queue an import or a dry run |
| `GET` | `/api/reservation/imports` | The hotel's imports, newest first, paginated |
| `GET` | `/api/reservation/imports/{id}` | One import: status, progress, totals, report summary |
| `GET` | `/api/reservation/imports/{id}/rows` | Row results, in row order, paginated |
| `GET` | `/api/reservation/imports/{id}/report` | Download the non-imported rows and warnings as `.xlsx` |
| `POST` | `/api/reservation/import-previews` | Upload a file to map its columns first (see "Column mapping" below) |
| `GET` | `/api/reservation/import-previews` | The hotel's uploads waiting to be mapped |
| `GET` | `/api/reservation/import-previews/{id}` | One upload's mapping screen, for a header row |
| `POST` | `/api/reservation/import-previews/{id}/confirm` | Confirm a mapping and queue the import |
| `DELETE` | `/api/reservation/import-previews/{id}` | Discard an upload and its file |

Two ways to start an import:

- **Direct upload** (`POST /api/reservation/import`): headers are matched by name through
  the synonym table, and row 1 is the header.
- **Column mapping** (016): upload a preview, let the admin choose the header row and map
  each column, then confirm. Use this for exports whose headers the system doesn't know.

### Upload

`multipart/form-data`. Don't set `Content-Type: application/json`.

| Field | Rules |
| --- | --- |
| `file` | **required**; `xlsx` / `xls` / `csv` / `txt`; max 5 MB |
| `date_order` | `day_first` (default) or `month_first`: how `12/09/2025` is read for the whole file |
| `decimal_mark` | `auto` (default), `dot` or `comma`: how amounts like `25,200` are read (see "Columns") |
| `dry_run` | boolean, default `false`. Runs every stage except writing |
| `hotel_id` | **required for a super admin**; ignored for everyone else |

```bash
curl -X POST http://your-domain.com/api/reservation/import \
  -H "Accept: application/json" -H "X-API-KEY: YOUR_API_KEY" \
  -H "Authorization: Bearer USER_LOGIN_TOKEN" \
  -F "file=@reservations.xlsx" -F "date_order=day_first" -F "dry_run=1"
```

**`202 Accepted`**: `body` is the import with `status: "queued"`. Poll
`GET /api/reservation/imports/{id}` until `status` is `completed` or `failed`.

Errors: `422` on `file`, `date_order` or `hotel_id`; `403` without the permission.
Missing columns aren't checked at upload: the import fails in the background and
`failure_reason` names them.

A hotel runs **one import at a time**. A newer upload waits as `queued` and runs after the
current one, in upload order. Different hotels run in parallel.

### The import record

```json
{
  "id": "9f1c…", "hotel_id": "3b2a…", "file_name": "reservations-sept-2025.xlsx",
  "uploaded_by": { "id": "…", "name": "Sara Ali" },
  "dry_run": false, "date_order": "day_first",
  "preview_id": "01a1…", "header_row": 4,
  "column_map": { "0": "reservation_reference", "1": "guest_full_name", "5": null },
  "decimal_mark": "dot",
  "status": "running", "stage": "import",
  "progress": { "processed_rows": 1200, "total_rows": 5000 },
  "totals": { "imported": 1100, "skipped": 0, "duplicate": 60, "invalid": 30, "unresolved": 10 },
  "created_rooms": ["214"], "created_room_types": ["Dbl Deluxe"],
  "unrecognized_columns": ["Fax"], "warnings_count": 42,
  "failure_reason": null,
  "created_at": "…", "started_at": "…", "finished_at": null
}
```

- `status`: `queued` → `running` → `completed` or `failed`. Both final states keep the
  totals reached. When a run fails, rows it never reached are `invalid` with "Not
  imported: the import stopped before this row was written", so they appear in the report. To retry, upload the file again; reservations already imported come back
  as `duplicate`.
- `totals` add up to `total_rows` once completed. Blank rows after the last data row aren't
  counted.
- On a dry run, `imported` means "would be imported", and `created_rooms` /
  `created_room_types` mean "would be created".
- `uploaded_by` is `null` if the uploader's account was deleted.
- `preview_id`, `header_row`, `column_map` and `decimal_mark` say how the file was read.
  For a direct upload, `preview_id` and `column_map` are `null` and `header_row` is `1`.
  `preview_id` also becomes `null` once its upload is discarded or expires; the import and
  its report stay.

### Rows

`GET /api/reservation/imports/{id}/rows?outcome=invalid&has_warnings=1&per_page=50`

```json
{ "row_number": 418, "external_reference": "BK-77812", "outcome": "unresolved", "stage": "map",
  "fields": ["guest_phone", "guest_email", "guest_external_id"],
  "reason": "The guest on row 418 matches more than one existing guest (Ahmed Saleh and Mona Saleh)…",
  "warnings": [], "reservation_id": null }
```

| Outcome | Meaning |
| --- | --- |
| `imported` | Written (on a dry run, would be). `reservation_id` is set |
| `skipped` | A data row with no values at all |
| `duplicate` | The reservation already exists at the hotel. `reservation_id` is the existing one |
| `invalid` | A rule is broken; `fields` and `reason` say which |
| `unresolved` | The guest couldn't be identified safely. Staff decide, fix the file and import again |

All rows of one reservation share its outcome. Warnings never change the outcome:

| Warning | Meaning |
| --- | --- |
| `no_phone` | A guest has no phone, so they can't be recognized on WhatsApp until staff add one |
| `phone_country_unknown` | A national number at a hotel with no country code; kept as written |
| `phone_unrecognized` | Not a valid number for the hotel's country (likely a typo); kept as written |
| `no_room_assigned` | A guest in house now has a line with no room; assign one at the desk |
| `room_clash` | The room is booked on overlapping nights; this line was imported without it |
| `status_corrected` | "Checked in" with a past departure was imported as checked out |
| `data_differs` | A duplicate whose stored data differs from the file; the stored reservation is unchanged |
| `restored` | A deleted reservation with the reference was restored with the file's data |
| `booking_unresolved` | A booking's activity name matched no activity (or two); the booking was left out |

### Report download

`GET /api/reservation/imports/{id}/report` returns `import-{id}-report.xlsx`, with one line
per non-imported row and one per warning (`row_number`, `external_reference`, `outcome`,
`fields`, `reason`, `warning`). It returns `409` while the import is still running. This is
the only response here that isn't an `apiResponse()` envelope.

### Columns

The first row is the header. Headers ignore case, spaces and punctuation, and go through a
synonym table (`config/reservation_import.php`), so `Res No.`, `Conf. No.`, `Booking Ref` and
`reservation_id` all mean `reservation_reference`. Common PMS abbreviations are included:
`Arr. Date`/`Dep. Date`, `Rms` (room count) and `Profile No.` (guest external id). Unknown columns are ignored and listed
once in `unrecognized_columns`.

**Terminology:** the file's `reservation_reference` (old column name `reservation_id`) is
stored as the reservation's code, which the reservation API calls `reservation_id`. Import
reports show it as `external_reference`.

| Column | Notes |
| --- | --- |
| `arrival_date`, `departure_date` | **Required columns.** Departure must be after arrival |
| `guest_phone`, `guest_email`, `guest_external_id` | **At least one of these columns is required.** Each guest needs at least one value |
| `reservation_reference` | Groups rows into one reservation, and is the idempotency key. Optional: without it, a `RES-XXXXXXXX` code is generated |
| `guest_first_name`, `guest_last_name`, `is_primary` | One guest per row. `is_primary`: `yes`/`no`, `1`/`0` or `true`/`false` |
| `guest_full_name` | The whole name in one column (headers `Guest Name`, `Name`, `Full Name`). `Smith, John` → John / Smith; otherwise the first word is the first name and the rest the last name (`Mohamed Abdel Rahman` → Mohamed / Abdel Rahman). Ignored on a row that also has `guest_first_name` or `guest_last_name` |
| `guests` | Still accepted: a JSON list of guests (`guest_id` or contact fields, plus `is_primary`) |
| `room_number`, `room_type`, `floor`, `room_count` | See "How rows become reservations" below |
| `status` | See "Statuses" below. Empty means `pending` |
| `adults`, `children`, `children_ages` | Whole numbers; ages as a comma list, each 0–17. With ages and no `children`, the count is the number of ages |
| `source`, `special_requests`, `reservation_value`, `currency` | `source` defaults to `import` and `currency` to the hotel's. Amounts are read by `decimal_mark` (below) |
| `activity_name`, `activity_date`, `activity_time`, `activity_pax` | One activity booking per row |

**Amounts** ignore currency text (`EGP 3,600` is `3,600`). Numeric cells are read as they
are. Text amounts follow the import's `decimal_mark`:

| Amount | `auto` (default) | `dot` | `comma` |
| --- | --- | --- | --- |
| `25,200` | invalid: 25200 or 25.2? | 25200 | invalid |
| `1,260.50` | 1260.50 | 1260.50 | invalid |
| `25.200` | invalid: 25200 or 25.2? | invalid | 25200 |
| `1.260,50` | 1260.50 | invalid | 1260.50 |
| `99,50` | 99.50 | invalid | 99.50 |

Under `dot` or `comma`, the other mark may only group thousands in threes. An amount that
doesn't fit makes the reservation invalid, naming the amount; it is never read some other
way.

**Dates** can be written as:

- `2025-09-12`;
- `12/09/2025`, `12-09-25` or `12.09.2025`, read using `date_order`;
- `12 Sep 2025`, `12-Sep-2025` or `Sep 12, 2025`;
- spreadsheet date cells.

A time part is ignored, and two-digit years are read as 20yy. Anything else, including
impossible dates like `31/02/2025`, makes the row invalid; nothing is guessed. Arabic-Indic
digits are read as digits.

**Phones** are stored as digits with the country code. Numbers written without one use
the hotel's `country_code`, so `0020 100 123 4567`, `+20 100 123 4567` and, at an Egyptian
hotel, `01001234567` are the same number. A guest stored earlier without the country code
(`01001234567`) is still matched.

**Statuses** map legacy labels (case and spacing are ignored):

| Status | Labels |
| --- | --- |
| `confirmed` | confirmed, conf, guaranteed, definite, booked, reserved |
| `pending` | pending, tentative, provisional, option, unconfirmed, waitlist |
| `cancelled` | cancelled, canceled, cxl, cancel, cnl |
| `checked_in` | checked in, in house, inhouse, arrived, ci, c/i, due out |
| `checked_out` | checked out, departed, co, c/o |
| `no_show` | no show, noshow, ns |

Statuses are checked against the dates, in the hotel's local time:

- `checked_out` needs the departure date to have been reached.
- `no_show` needs the arrival date to have been reached.
- `checked_in` with a future arrival is invalid.
- `checked_in` whose departure has passed is imported as `checked_out`, with
  `status_corrected`.

What the import records for these statuses:

- Guests in house now get their stays checked in, and their assigned rooms are marked
  occupied.
- A no-show holds no rooms, and its stays are no-show.
- Imported history creates no cleaning tasks, notifications, memory extraction or ledger
  rows.

### How rows become reservations

- **Grouping**: rows with the same reference (case-insensitive) are one reservation, and
  must agree on its dates, status, party, value and other reservation fields. An empty
  cell is not a disagreement; a real conflict makes the whole reservation invalid.
- **Rooms**:
  - Each distinct `room_number` is one line, on that room.
  - Rows with a `room_type` and no number add one line of that type the first time the
    type appears; later ones only add guests.
  - `room_count` sets the number of unnumbered lines of a type instead.
  - Rows with no room columns only add guests. A reservation with no room columns at all
    gets one line of the hotel's default type.
  - Missing rooms and room types are created and listed.
- **Guests**: every guest named on the rows is linked, once each. The primary is the one
  flagged `is_primary`, otherwise the first. More guests than `adults + children` is
  invalid. With no party columns, adults default to the number of guests.
- **Capacity and availability**: neither is checked, because legacy data is recorded as it
  is. But a physical room is never put on two live reservations for the same night: the
  later line is imported without its room, with `room_clash`.
- **Bookings** attach, with origin `import`, when `activity_name` matches exactly one of
  the hotel's activities. Their status follows the reservation:
  - `cancelled` for a cancelled or no-show reservation;
  - `realised` for a past date on a checked-in or checked-out reservation;
  - otherwise `confirmed`.

  They are charged to the folio when the activity has a price, otherwise included.

### Guest matching

A guest is reused only on a confident match at this hotel:

- the same phone digits;
- the same email (case-insensitive);
- the same old-system guest id (`guest_external_id`).

Names never match anyone. If the details point at two different guests, or at two
different people named earlier in the file, the reservation is `unresolved` and nothing
from it is written. A matched guest's stored details are never overwritten; empty ones are
filled in.

### Re-importing

Importing a file again never creates a reservation, guest or booking twice:

- A reference the hotel already has is a `duplicate`, and the stored reservation is left
  as it is. `data_differs` says when the file disagrees.
- A reservation without a reference that matches an existing one (same primary guest,
  dates and room types) is a `duplicate`.
- References are unique per hotel, and matched ignoring case (`RES-001` = `res-001`).

If an import crashes, uploading the file again finishes the rest.

### Dry run

`dry_run=1` runs every stage except writing and produces the same report. One limit: if a
booking made between a dry run and the real import takes a room, that clash only shows up
in the real import.

### Column mapping (preview)

> **Added 2026-10-24 (016, additive).** Nothing above changed.

For files whose headers the system doesn't know, or whose header isn't on row 1, the admin
maps the columns before importing. The file is uploaded once and read only by the server.

**1. Upload**: `POST /api/reservation/import-previews`, `multipart/form-data` with `file`
(same rules as the direct upload) and, for a super admin, `hotel_id`. **`201`** with the
preview below. An unreadable file is a `422` on `file`, and nothing is stored.

```json
{
  "id": "01a1…", "hotel_id": "3b2a…", "file_name": "reservations-sept-2025.xlsx",
  "uploaded_by": { "id": "…", "name": "Sara Ali" },
  "created_at": "…", "expires_at": "…",
  "suggested_header_row": 1, "header_row": 4, "data_rows": 438,
  "rows": [["Grand Harbor Hotel"], ["Reservations report"], ["01/09/2025 - 30/09/2025"],
           ["Conf. No.", "Guest Name", "Mob.", "Arr. Date", "Dep. Date", "Nationality"]],
  "columns": [
    { "position": 0, "letter": "A", "header": "Conf. No.", "samples": ["GHH-784108"], "suggested_field": "reservation_reference" },
    { "position": 2, "letter": "C", "header": "Mob.", "samples": ["+20 100 123 4567"], "suggested_field": null }
  ],
  "fields": [
    { "key": "arrival_date", "label": "Arrival date", "required": true, "guest_identifier": false },
    { "key": "guest_phone", "label": "Guest phone", "required": false, "guest_identifier": true }
  ],
  "imports": [{ "id": "…", "dry_run": true, "status": "completed", "created_at": "…" }]
}
```

- `rows`: the first 10 rows as shown in the spreadsheet (dates formatted), for choosing
  the header row.
- `columns`: built from `header_row`. A blank header shows as `Column F`. `samples` holds
  up to 5 non-empty values from the rows below the header. Columns are identified by
  `position`, so two columns with the same header are mapped separately.
- `suggested_field`: the hotel's last confirmed mapping first (including columns it left
  unmapped on purpose, which come back `null`), then the synonym table, then a header
  already named like a field. A field is suggested to only one column.
- `suggested_header_row`: the header row the hotel used last, or 1.
- `fields`: everything a column can be mapped to. Translate `label` by `key`. At least one
  `guest_identifier` field must be mapped.
- `imports`: the imports already started from this upload, newest first.

**2. Choose the header row**:
`GET /api/reservation/import-previews/{id}?header_row=4` rebuilds `columns`, `data_rows`
and the suggestions from row 4. It doesn't re-read the file. `header_row` is 1–10, and a
row past the end of the file is a `422`: "The file has only N rows."

**3. Confirm**: `POST /api/reservation/import-previews/{id}/confirm`, JSON:

```json
{
  "header_row": 4,
  "columns": { "0": "reservation_reference", "1": "guest_full_name", "2": "guest_phone",
               "3": "arrival_date", "4": "departure_date", "5": null },
  "date_order": "day_first", "decimal_mark": "dot", "dry_run": true
}
```

`columns` maps a sheet position to a field key, or `null` to ignore it. Positions left out
are ignored too. The mapping is the only thing that decides what a column means: the
synonym table isn't used, and every unmapped column with a header is listed in the
import's `unrecognized_columns`.

**`202`**: the import record, exactly as from a direct upload. Its rows start at the
file's own row numbers (row 5 above). The hotel's saved mapping becomes this one, so the
next file from the same system arrives mapped.

`422` on `columns`, one message per problem, all in one response. Nothing is created and
the saved mapping is unchanged:

- `Map a column to Arrival date.` / `Map a column to Departure date.`
- `Map a column to at least one of Guest phone, Guest email, Guest id (old system).`
- `Guest phone is mapped to more than one column.`
- `The file has no column at position 9.`
- `"fax" is not a field this import reads.`
- `Map either Guest full name or Guest first name / Guest last name, not both.`

There is also a `422` on `header_row`, `date_order` or `decimal_mark` for an invalid value.

The same upload can be confirmed again, for example a dry run and then the real run. Each
confirm creates its own import.

**4. List, reopen, discard**: `GET /api/reservation/import-previews` lists the hotel's
unexpired uploads, newest first, without `rows`, `columns` or `fields`. Reopen one with
`GET …/{id}`. `DELETE …/{id}` deletes it and its file at once ("Upload discarded.").
Imports already started from it are unaffected.

**Expiry**: an upload expires 24 hours after it is uploaded. Reading or confirming it then
returns **`410`** ("This upload has expired. Upload the file again."). An hourly job
deletes expired uploads and their files, after which they return `404`.

Another hotel's upload returns `404`. Every endpoint needs `reservations.import`.

### Audit

Each run writes one `hotel.reservations_imported` event on behalf of the uploader. It
records the import id, file name, dry-run flag, status and totals. No per-reservation
events are written.

## 7. Reservation Voucher (PDF)

> **Added 2026-10-27 (SPEC-013, `specs/018-reservation-voucher`).** Not a breaking change.

A voucher is a one-page PDF proving the reservation exists. It is **not an invoice**. It is
rendered from the saved reservation on every request and never stored. The hotel's voucher
templates (see the [voucher templates API](voucher-templates-api-documentation.md)) decide its
design; without one, a built-in layout is used.

**What it shows:** hotel name, address, phone and email; reference and status; primary guest
and other guests; arrival, departure and nights; one line per booked room with the **room type
only** (never a room number); adults, children and children's ages; special requests; the total
(only when asked); a staff note (only when given); the issue date.

**Language:** Arabic (right to left) when the primary guest's `preferred_language` starts with
`ar`, English otherwise. The primary guest is the one marked primary in the guest list. A template named in the request uses its own language instead.

**Statuses:** `pending`, `confirmed`, `checked_in` and `checked_out` get a voucher; `cancelled`
and `no_show` get `409`.

### Generation options

Shared by download and email.

| Field | Rule |
| --- | --- |
| `template_id` | Optional uuid of a template in the reservation's hotel. Default: the hotel's default template for the guest's language, else the built-in layout. Another hotel's or a deleted template is `404`. |
| `show_total` | Boolean, default `false`. Prints `reservation_value` and `currency`. |
| `staff_note` | Optional string, max 500. |
| `overrides` | Optional object. Changes what **this PDF** shows; the reservation is never changed. Allowed keys below; any other key is `422` on `overrides.{key}`. |

| Override key | Rule |
| --- | --- |
| `primary_guest_name` | string ≤ 200 |
| `other_guest_names` | array (≤ 50) of string ≤ 200 |
| `arrival_date`, `departure_date` | date; the displayed departure must be after the displayed arrival. Nights are recomputed. |
| `room_lines` | array (≤ 50) of string ≤ 200 |
| `adults`, `children` | integer 0–99 |
| `children_ages` | array of integer 0–17 |
| `special_requests` | string ≤ 2000 |
| `hotel_name`, `hotel_address`, `hotel_phone`, `hotel_email` | string ≤ 255 |

Overrides need no permission beyond the voucher permission. Every generation is audited
(`reservation.voucher_generated`) with the overrides and note, which is the control on them.

### 7.1 Download

`POST /api/reservation/{id}/voucher` · permission `reservations.voucher`

Body: the generation options. Query `?download=1` sets `Content-Disposition: attachment`
(default `inline`).

| Status | Meaning |
| --- | --- |
| `200` | `Content-Type: application/pdf`. Header `X-Voucher-Unplaced` lists data sent but not placed by the template, e.g. `voucher.staff_note`. Both it and `Content-Disposition` are exposed to the browser through CORS. |
| `404` | Reservation or `template_id` not in your hotel |
| `409` | Cancelled or no-show reservation |
| `422` | Invalid options |

Errors are the usual JSON `{ message, code, body }`.

### 7.2 Email to the guest

`POST /api/reservation/{id}/voucher/email` · permission `reservations.voucher_email`

Only ever sent when staff call this endpoint; nothing emails a voucher automatically.

| Field | Rule |
| --- | --- |
| *generation options* | as above |
| `request_key` | **Required** string ≤ 100. Generate one per click (a UUID). A retry with the same key never sends a second email. |
| `email` | Optional; defaults to the email of the guest marked primary (the guest the voucher is addressed to), falling back to the reservation's guest. The guest record is not changed. |
| `overrides` | As for download, **except** `hotel_name`, `hotel_address`, `hotel_phone` and `hotel_email`, which are `422` here: an email from the hotel's sending domain must carry the hotel's real contact details. Download the voucher to change them. |

| Status | Meaning |
| --- | --- |
| `202` | Queued. `body` is the email request (below). |
| `200` | This `request_key` was already used for this reservation: the existing request, nothing queued. |
| `404` / `409` | As for download |
| `422` | No recipient (the guest has no email and none was sent), invalid email or options |
| `429` | Five voucher emails were already requested for this reservation in the last 24 hours, or you have requested twenty across all reservations in the last hour (requests that ended `skipped` or `failed` don't count) |

```json
{
  "message": "Voucher email queued",
  "code": 202,
  "body": {
    "id": "uuid",
    "reservation_id": "uuid",
    "status": "queued",
    "status_reason": null,
    "recipient": "layla@example.com",
    "language": "en",
    "voucher_template_id": null,
    "show_total": false,
    "unplaced": [],
    "created_at": "2026-10-27T10:00:00.000000Z",
    "sent_at": null
  }
}
```

`status` moves `queued` → `sending` → `sent`, or ends `skipped` (`status_reason`
`reservation_cancelled` / `reservation_no_show` / `reservation_deleted`: the reservation
changed before the email went out) or `failed` (after the last retry, or
`uncertain_delivery`: the mailer may already have delivered it and cannot drop a duplicate, so
it is not sent again; send it again with a new `request_key` if needed). The email uses the language, template and
options chosen when it was requested, with the reservation's data at send time.

### 7.3 Email history

`GET /api/reservation/{id}/voucher/emails` · permission `reservations.voucher_email`

The reservation's email requests, newest first, paginated. The usual list parameters work:
`filter[status]=sent`, `search` (recipient), `sort=-sent_at`, `per_page` (max 100). Poll it after a `202` to show
"sent at …".

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
- Read the list from `body.data`; drive pagination UI from `body.meta.current_page` / `body.meta.last_page` / `body.meta.total` (the list is a paginated resource: `{ data, links, meta }`).
- `reservation_value` comes back as a **string** — parse before doing math with it.
- `hotel_id` can be set on create but **never** changed on update — don't put it in the edit form.
- `guest_id` must belong to the same hotel as `hotel_id`, or you'll get a `422` with a plain-language `message` (not a field-level `errors` entry).
- **Rooms are lines (since 2026-09-23).** Create with `rooms: [{ room_type_id, quantity?, room_id? }]` (1–50 rooms, room types must be active); read `rooms[]` and `room_summary`. There is no top-level `room_id`/`room` any more. On update, `rooms` is the full desired list of live lines (`{ id }` keeps a line, no `id` adds one, omitted lines are cancelled); leave it out to keep the lines. A checked-in reservation only allows room moves. See [Changing the Rooms](#changing-the-rooms).
- An over-capacity party is rejected unless staff send `capacity_override: true` (audited).
- A booking that would oversell a room type on any night gets `422` with `shortfalls[]` (type, nights, how many short). Staff with `reservations.overbook` can resend with `overbook_override: true` (audited); without it that flag returns `403`. Check `GET /api/availability` first to avoid it. See [Overbooking](#overbooking).
- `filter[room_type_id]` / `filter[room_id]` match any live line; they can't be used to sort.
- The API does **not** enforce `departure_date >= arrival_date` yet — validate that client-side.
- Treat `403` on `show`/`update`/`destroy` the same as `404` in the UI — it means "not yours."
- Don't use this document for the WhatsApp reservation-creation flow — that's `POST /api/whatsapp-reservation`, unauthenticated (API-key only), and out of scope here.
- Room status follows who is actually in the room: occupied while any checked-in reservation has it on a live line (every room of a multi-room reservation), freed back to `available` once none does, and `maintenance` left alone. A future booking never frees an occupied room. See [Status Values](#status-values).
- `reservation_id` is unique per hotel, not across the platform. A row in an import whose code the hotel already uses is skipped with `Reservation id … already exists.`
- `POST /api/reservation/import` queues an import of an `.xlsx`/`.xls`/`.csv`/`.txt` file (max 5 MB) and returns `202` with an import record. Poll `GET /api/reservation/imports/{id}` until it completes, then read `/rows` or download `/report`. Every row gets an outcome and a reason, re-imports are safe, and `dry_run=1` previews without writing. A super admin passes `hotel_id`. See [§6](#6-import-reservations-bulk-upload).
