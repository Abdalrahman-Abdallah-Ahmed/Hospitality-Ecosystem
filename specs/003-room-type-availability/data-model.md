# Data Model: Room-Type Availability

**Feature**: [spec.md](spec.md) · **Research**: [research.md](research.md)

This feature adds no tables. Availability is worked out from existing data each time it is
requested. The only schema change is one index.

## Existing entities read

| Entity | Fields used | Notes |
| --- | --- | --- |
| `RoomType` | `id`, `hotel_id`, `name`, `description`, `max_occupancy`, `adult_capacity`, `child_capacity`, `is_active`, `deleted_at` | The rows each grid row is built from. Active only by default; named types are returned even if inactive; soft-deleted types are never returned. Rows are locked `FOR UPDATE` during reservation writes (R7). |
| `Room` | `hotel_id`, `room_type_id`, `status`, `deleted_at` | `total` = rooms that are not deleted. `out_of_order` = rooms whose `status` is in `RoomStatusesEnum::outOfOrder()` (today `maintenance`, R4). |
| `Reservation` | `hotel_id`, `status`, `arrival_date`, `departure_date`, `deleted_at` | Holds inventory while `status ∈ ReservationStatus::holdingInventory()` = `pending`, `confirmed`, `checked_in` (R3). |
| `ReservationRoom` (line) | `hotel_id`, `reservation_id`, `room_type_id`, `status`, `deleted_at` | Holds one unit of `room_type_id` per night while `status <> cancelled` and its reservation holds inventory. `room_id` is ignored here (SPEC-021). |
| `Hotel` | `timezone` | Sets "today" (R5). |

## Derived: availability cell (not stored)

One cell per `(room_type, night)`, for each night in `[arrival_date, departure_date)`:

| Field | Definition |
| --- | --- |
| `date` | The night (a date) |
| `total` | Rooms of the type that are not deleted |
| `out_of_order` | Of those, rooms that are out of order now (counted on every night looked up) |
| `booked` | Holding lines of the type whose nights include `date` |
| `sellable` | `max(0, total − out_of_order − booked)` |
| `overbooked` | `max(0, booked − (total − out_of_order))` |

Per room type: `bookable_for_stay = min(sellable)` over all nights in the range.

**Nights of a holding line**: from `arrival_date` up to, but not including,
`effective_departure`. For a `checked_in` reservation whose `departure_date` is before
the hotel's today, `effective_departure = hotel_today + 1` (it still holds tonight); in
every other case it is `departure_date`. A guest due out today does not hold tonight.

## Derived: footprint (not stored)

`footprint(reservation)` = a map of `(room_type_id, date) → count` of this reservation's own
holding lines. The overbooking guard compares it before and after a write. Only cells whose
count went up are checked (R6).

## Derived: shortfall (returned, audited on override)

```text
{ room_type_id, room_type_name, nights: [ { date, short } ] }
```

`short` = how many rooms are missing for that night after the change. Returned in the 422
`shortfalls` array. When an override is used, it is written to the
`reservation.overbooking_overridden` audit entry with the actor.

## Enum and permission changes

| Where | Change |
| --- | --- |
| `ReservationStatus` | `holdingInventory(): array` → `[PENDING, CONFIRMED, CHECKED_IN]` |
| `RoomStatusesEnum` | `outOfOrder(): array` → `[MAINTENANCE]` (SPEC-003 changes it to `[OUT_OF_ORDER]`) |
| `Permission` | `AVAILABILITY_VIEW = 'availability.view'` (added to `employeeDefaults()`), `RESERVATIONS_OVERBOOK = 'reservations.overbook'` |

## Validation rules

| Input | Rule |
| --- | --- |
| `arrival_date` | Required date, not before the hotel's today |
| `departure_date` | Required date, after `arrival_date`, at most 90 nights later |
| `room_type_ids[]` | Optional UUIDs; each must be a live room type of the hotel (else a 422 that does not say why) |
| `hotel_id` | Required for super admins, ignored for everyone else (`resolveHotel()`) |
| `overbook_override` (reservation create/update) | Optional boolean. When `true`, the caller needs `reservations.overbook` (403 otherwise). Ignored for an AI actor. |

## Schema change

| Migration | Change |
| --- | --- |
| `2026_09_23_000007_add_date_index_to_reservations_table.php` | Index `reservations (hotel_id, arrival_date, departure_date)` |

Use the next free sequence number when this is implemented.
