# Dashboard API Documentation

This document describes the dashboard summary endpoint, defined by `App\Http\Controllers\DashboardController`.

There is **no policy/authorization check beyond being an authenticated user with a hotel** — unlike most other resources in this app, there's no `admin`-only gate here. Any authenticated user (including `employee`) who belongs to a hotel can call this.

## Base URL

`GET /api/dashboard`

## Required Headers

Every request needs all three:

```http
X-API-KEY: {your_api_key}
Authorization: Bearer {login_token}
Accept: application/json
```

- `X-API-KEY` is checked by the `api.key` middleware.
- `Authorization: Bearer {login_token}` is required — the route is inside `auth:sanctum`.
- Without a valid bearer token, the API returns HTTP `401 Unauthenticated.` before any controller logic runs.

## Who Can Call This

Any authenticated user, **as long as they belong to a hotel.** A user with no `hotel_id` (e.g. a super admin, who typically has no owned hotel) gets a `403` — see below. There is no hotel-scoping query param; the response is always scoped to the caller's own hotel, with no way for a super admin to request another hotel's dashboard through this endpoint. (There is an optional `?date=` param — see [Occupancy Object](#occupancy-object) — but it only changes which date the occupancy figures describe, not which hotel.)

## Response Format

```json
{
  "message": "Some message",
  "code": 200,
  "body": {}
}
```

## Success Response

HTTP `200 OK`:

```json
{
  "message": "Dashboard data fetched successfully.",
  "code": 200,
  "body": {
    "pending_tasks": 4,
    "in_progress_tasks": 2,
    "today_arrivals_count": 3,
    "today_arrivals": [
      {
        "id": "019fc000-3333-7000-9000-abcdef123456",
        "hotel_id": "019f9b37-c265-726d-a6fe-f7eaa7852636",
        "room_id": "019f9b37-c268-738c-bc46-53281c1763cf",
        "arrival_date": "2026-08-08",
        "departure_date": "2026-08-11",
        "status": "confirmed",
        "room": {
          "id": "019f9b37-c268-738c-bc46-53281c1763cf",
          "room_number": "101",
          "room_type": "double",
          "floor": "1",
          "status": "occupied"
        }
      }
    ],
    "today_departures_count": 1,
    "today_departures": [
      { "...": "same shape as today_arrivals, filtered by departure_date instead" }
    ],
    "occupancy": {
      "date": "2026-08-26",
      "occupied_rooms": 5,
      "total_rooms": 8,
      "percentage": 62.5,
      "basis": "room_status_snapshot",
      "as_of": "2026-08-26T14:03:11+00:00",
      "historical_supported": true
    },
    "booking_value_today": "1250.75",
    "room_revenue_today": "450.00"
  }
}
```

### Field notes for the UI

| Field | Meaning |
| --- | --- |
| `pending_tasks` | Count of the caller's hotel's tasks with `status: pending`. |
| `in_progress_tasks` | Count of the caller's hotel's tasks with `status: in_progress`. |
| `today_arrivals_count` | Count of reservations in the caller's hotel with `arrival_date` equal to today (server date). |
| `today_arrivals` | The **full reservation objects** for the same set — not just ids. Each has `room` eager-loaded (see [Room Object](#room_number-caveat) below), but **not** `guest` — resolve guest names from data you already have if needed. |
| `today_departures_count` | Same as `today_arrivals_count`, but for `departure_date`. |
| `today_departures` | Same shape as `today_arrivals`, filtered by `departure_date` instead of `arrival_date`. |
| `occupancy` | See [Occupancy Object](#occupancy-object) below. **Replaces** the old flat `occupancy_percentage` field. |
| `booking_value_today` | Sum of `reservation_value` across all reservations in the caller's hotel **created today** (`created_at`, not `arrival_date`). **Renamed from `revenue_today`** — the old name was misleading: this is the value of bookings *made* today, not revenue for stays happening today (see `room_revenue_today` below). This is a raw SQL `SUM()`, not a hydrated model field, so its type is inconsistent: when there's at least one matching reservation it comes back as a **numeric string** (e.g. `"1250.75"`) — but when there are **no** matching reservations it comes back as the **plain number `0`**, not `"0.00"` or `null`. Handle both shapes (`Number(booking_value_today)` works for either in JS). This total is also **not** filtered by `status` — a reservation created today and immediately cancelled still counts toward it. |
| `room_revenue_today` | **Now a real number** (Phase 1 WP-2 shipped) — the sum of `room_revenue` across every `stay` currently `in_house` at the caller's hotel. A stay's `room_revenue` is set from its reservation's `reservation_value` when the stay is created (Phase 1 has no per-night rate breakdown yet, so the whole reservation value stands in for room revenue). Same numeric-string-or-`0` typing caveat as `booking_value_today`. |

#### Occupancy Object

```json
{
  "date": "2026-08-26",
  "occupied_rooms": 5,
  "total_rooms": 8,
  "percentage": 62.5,
  "basis": "room_status_snapshot",
  "as_of": "2026-08-26T14:03:11+00:00",
  "historical_supported": true
}
```

- `GET /api/dashboard` accepts an optional `?date=YYYY-MM-DD` query parameter.
- **When `date` is omitted, or equals today:** `occupied_rooms` and `percentage` reflect the live `rooms.status` snapshot — `basis: "room_status_snapshot"`. `percentage` is `occupied rooms / total rooms * 100`, rounded to 2 decimals, or `0` if the hotel has no rooms at all (not `null`, not an error). This stays a real-time snapshot on purpose (e.g. a room marked under maintenance shows up immediately, with no dependency on stay data).
- **When `date` is any other value (past or future):** occupancy is now computed from `stays` — `basis: "stay_events"`. A room counts as occupied on that date when a stay's status is `in_house` or `departed` and the date falls within its *planned* arrival/departure window (`arrival_date <= date < departure_date` — departure day itself doesn't count, since a guest leaving on the 5th didn't sleep there that night). `occupied_rooms`/`percentage` are real numbers now, not `null` — **breaking change** from the WP-0/WP-1 behavior, which returned `null` for any non-today date because there was no historical data source yet.
- `date` always echoes back the date the occupancy figures apply to (today's date if the param was omitted).
- `historical_supported` is now always `true`.

#### `room_number` caveat

`today_arrivals[].room` / `today_departures[].room` can be **`null`** — a reservation isn't required to have a room assigned. Always null-check before reading `room.room_number` in the UI (e.g. an arrivals-board widget).

#### Reservation counts are **not** further filtered by status

`today_arrivals` / `today_departures` include reservations of **any** `status` (`pending`, `confirmed`, `checked_in`, `checked_out`, `cancelled`) whose date falls on today — including already-cancelled ones. If your "today's arrivals" widget should exclude cancelled reservations, filter `status !== 'cancelled'` client-side; the backend does not do this for you.

#### `occupancy.percentage` reflects real-time room `status`, not reservation dates

For a today (or omitted) `date`, this is a live snapshot of how many `rooms` rows currently have `status: occupied` right now, out of the hotel's total room count. See [Reservations API Documentation § Status Values](/D:/Hospitality%20Ecosystem/docs/reservations-api-documentation.md#status-values) for how a room's `status` gets kept in sync with its stay — `checked_in` occupies the room, `checked_out`/`cancelled` frees it back to `available` automatically (fixed as of Phase 1 WP-2; it used to only ever occupy, never free).

## Error: No Associated Hotel

Custom `message/code/body` wrapper:

HTTP `403`:

```json
{
  "message": "You do not belong to any hotel.",
  "code": 403,
  "body": null
}
```

Treat this the same as other "no hotel" errors elsewhere in this API (e.g. [AI Insights](/D:/Hospitality%20Ecosystem/docs/ai-insights-api-documentation.md#error-no-associated-hotel), [User Management create](/D:/Hospitality%20Ecosystem/docs/user-management-api-documentation.md#error-no-associated-hotel)) — most likely means a super admin or a user whose `hotel_id` hasn't been backfilled; don't build a "your hotel has no data yet" empty state around it.

## Example cURL Request

```bash
curl -X GET http://your-domain.com/api/dashboard \
  -H "Accept: application/json" \
  -H "X-API-KEY: YOUR_API_KEY" \
  -H "Authorization: Bearer USER_LOGIN_TOKEN"
```

## Summary for the Frontend

- `GET /api/dashboard` — always returns the caller's own hotel's summary — plus an optional `?date=YYYY-MM-DD` that currently only affects the `occupancy` object (see below).
- Open to **any authenticated hotel user**, not just admins — unlike most of this API, there's no role gate here.
- `today_arrivals`/`today_departures` are full reservation objects (with `room`, not `guest`), not just counts — use the paired `*_count` fields for KPI tiles and the arrays for a table/list widget.
- These arrival/departure lists are **not** status-filtered — cancelled reservations for today still show up; filter client-side if that matters for the widget.
- `room` on a reservation can be `null` — null-check before reading `room.room_number`.
- **Breaking change:** the old flat `occupancy_percentage` and `revenue_today` fields are gone. `occupancy_percentage` is now `occupancy.percentage`. `revenue_today` is renamed `booking_value_today` (same value, honest name) — the old name implied stay revenue, which is what `room_revenue_today` is for.
- `booking_value_today` sums `reservation_value` for reservations **created** today (not arriving today), includes cancelled ones, and switches type between a numeric string and plain `0` depending on whether any rows matched — coerce with `Number(...)` rather than assuming one type.
- **`occupancy` and `room_revenue_today` now work for any date** (Phase 1 WP-2 shipped): `occupancy.occupied_rooms`/`percentage` are real numbers for a past/future `?date=` too (computed from `stays`, `basis: "stay_events"`), not `null` like they briefly were. `room_revenue_today` is a real sum of in-house stays' room revenue, not always `null` anymore.
- A user with no hotel (most commonly a super admin) gets a `403`, not an empty dashboard.

## Related Docs

- [Reservations API Documentation](/D:/Hospitality%20Ecosystem/docs/reservations-api-documentation.md)
- [Room API Documentation](/D:/Hospitality%20Ecosystem/docs/room-api-documentation.md)
- [Task Management API Documentation](/D:/Hospitality%20Ecosystem/docs/task-management-api-documentation.md)
