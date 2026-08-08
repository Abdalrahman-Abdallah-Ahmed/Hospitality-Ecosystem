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

Any authenticated user, **as long as they belong to a hotel.** A user with no `hotel_id` (e.g. a super admin, who typically has no owned hotel) gets a `403` — see below. There is no hotel-scoping query param; the response is always scoped to the caller's own hotel, with no way for a super admin to request another hotel's dashboard through this endpoint.

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
    "occupancy_percentage": 62.5,
    "revenue_today": "1250.75"
  }
}
```

### Field notes for the UI

| Field | Meaning |
| --- | --- |
| `pending_tasks` | Count of the caller's hotel's tasks with `status: pending`. |
| `in_progress_tasks` | Count of the caller's hotel's tasks with `status: in_progress`. |
| `today_arrivals_count` | Count of reservations in the caller's hotel with `arrival_date` equal to today (server date). |
| `revenue_today` | Sum of `reservation_value` across all reservations in the caller's hotel **created today** (`created_at`, not `arrival_date`). This is a raw SQL `SUM()`, not a hydrated model field, so its type is inconsistent: when there's at least one matching reservation it comes back as a **numeric string** (e.g. `"1250.75"`, same as `reservation_value` on the [Reservation object](/D:/Hospitality%20Ecosystem/docs/reservations-api-documentation.md#the-reservation-object)) — but when there are **no** matching reservations it comes back as the **plain number `0`**, not `"0.00"` or `null`. Handle both shapes (`Number(revenue_today)` works for either in JS). This total is also **not** filtered by `status` — a reservation created today and immediately cancelled still counts toward it. |
| `today_arrivals` | The **full reservation objects** for the same set — not just ids. Each has `room` eager-loaded (see [Room Object](#room_number-caveat) below), but **not** `guest` — resolve guest names from data you already have if needed. |
| `today_departures_count` | Same as `today_arrivals_count`, but for `departure_date`. |
| `today_departures` | Same shape as `today_arrivals`, filtered by `departure_date` instead of `arrival_date`. |
| `occupancy_percentage` | `occupied rooms / total rooms * 100` for the caller's hotel, rounded to 2 decimals. `0` if the hotel has no rooms at all (not `null`, not an error). |

#### `room_number` caveat

`today_arrivals[].room` / `today_departures[].room` can be **`null`** — a reservation isn't required to have a room assigned. Always null-check before reading `room.room_number` in the UI (e.g. an arrivals-board widget).

#### Reservation counts are **not** further filtered by status

`today_arrivals` / `today_departures` include reservations of **any** `status` (`pending`, `confirmed`, `checked_in`, `checked_out`, `cancelled`) whose date falls on today — including already-cancelled ones. If your "today's arrivals" widget should exclude cancelled reservations, filter `status !== 'cancelled'` client-side; the backend does not do this for you.

#### `occupancy_percentage` reflects real-time room `status`, not reservation dates

This is **not** computed from today's check-ins/check-outs — it's a live snapshot of how many `rooms` rows currently have `status: occupied` right now, out of the hotel's total room count. See [Reservations API Documentation § Status Values](/D:/Hospitality%20Ecosystem/docs/reservations-api-documentation.md#status-values) for how a room's `status` gets set to `occupied` (confirming a reservation with a `room_id` does this automatically) — and note it is **not** currently freed back to `available` automatically on cancel/checkout, so this percentage can run high until rooms are manually reset. Flag this to product/backend if it causes a visibly wrong occupancy number in testing.

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

- Single `GET /api/dashboard` call, no query params, no filters — always returns the caller's own hotel's summary.
- Open to **any authenticated hotel user**, not just admins — unlike most of this API, there's no role gate here.
- `today_arrivals`/`today_departures` are full reservation objects (with `room`, not `guest`), not just counts — use the paired `*_count` fields for KPI tiles and the arrays for a table/list widget.
- These arrival/departure lists are **not** status-filtered — cancelled reservations for today still show up; filter client-side if that matters for the widget.
- `room` on a reservation can be `null` — null-check before reading `room.room_number`.
- `occupancy_percentage` is a live room-status snapshot, not a same-day check-in/out calculation, and currently only trends upward (nothing auto-frees a room) — see the caveat above.
- `revenue_today` sums `reservation_value` for reservations **created** today (not arriving today), includes cancelled ones, and switches type between a numeric string and plain `0` depending on whether any rows matched — coerce with `Number(...)` rather than assuming one type.
- A user with no hotel (most commonly a super admin) gets a `403`, not an empty dashboard.

## Related Docs

- [Reservations API Documentation](/D:/Hospitality%20Ecosystem/docs/reservations-api-documentation.md)
- [Room API Documentation](/D:/Hospitality%20Ecosystem/docs/room-api-documentation.md)
- [Task Management API Documentation](/D:/Hospitality%20Ecosystem/docs/task-management-api-documentation.md)
