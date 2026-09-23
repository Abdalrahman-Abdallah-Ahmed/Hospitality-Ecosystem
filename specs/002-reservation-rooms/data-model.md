# Data Model: Reservation Rooms

**Feature**: [spec.md](spec.md) · **Research**: [research.md](research.md)

## New: `reservation_rooms`

| Column | Type | Rules |
| --- | --- | --- |
| `id` | uuid PK | `HasUuids` |
| `hotel_id` | uuid FK → hotels, cascade | Stamped by `BelongsToHotel`; always equals the reservation's hotel |
| `reservation_id` | uuid FK → reservations, cascade | Required |
| `room_type_id` | uuid FK → room_types, restrict | Required. New/changed lines: type must be active, not soft-deleted, same hotel |
| `room_id` | uuid FK → rooms, nullOnDelete, nullable | Same hotel; `rooms.room_type_id` must equal `room_type_id` |
| `status` | string (`ReservationRoomStatus`) | `reserved` \| `cancelled`, default `reserved` |
| `created_at`, `updated_at`, `deleted_at` | timestamps | `SoftDeletes` |

**Indexes**

- `(reservation_id, status)`: loading a reservation's lines.
- `(hotel_id, room_id)`: occupancy and filter by room.
- `(hotel_id, room_type_id)`: filter by type, room-type deletion guard.
- Partial unique `(reservation_id, room_id) WHERE room_id IS NOT NULL AND status <> 'cancelled' AND deleted_at IS NULL`: FR-005.

**Model** `App\Models\ReservationRoom`: `BelongsToHotel`, `HasUuids`, `SoftDeletes`,
`RecordsEvents`, explicit `$fillable` and `$casts` (`status` → enum).
`eventLoggedAttributes()`: `room_type_id`, `room_id`, `status`.
Relations: `reservation()`, `roomType()` (with trashed), `room()` (with trashed).
Scopes: `active()` (status ≠ cancelled). Static `inHouseRoomIds()` → subquery of room ids
on active lines whose reservation's stay is `IN_HOUSE`.

## New: `App\Enums\ReservationRoomStatus`

| Case | Meaning |
| --- | --- |
| `reserved` | Booked unit; may or may not have a physical room |
| `cancelled` | Removed from the reservation, or the reservation was cancelled. Kept for history; holds no room |

Later specs add cases; this spec has no other transitions.

## Changed: `reservations`

- **Drop** `room_id` (after backfill). Remove it from `$fillable`, `eventLoggedAttributes()`
  and the `room()` relation.
- **Add relation** `reservationRooms(): HasMany` (ordered by `created_at`, `id`).
- **Add** `primaryRoomId(): ?string`: room of the first active line that has one.

Invariants enforced by `ReservationCreator` (not the DB, because legacy rows and
mid-transaction states exist):

- At least one active line (FR-001).
- At most 50 active lines (FR-006).
- `adults + children ≤ Σ max_occupancy` and `adults ≤ Σ adult_capacity` over active lines,
  unless overridden by staff (FR-009).

## Line state rules by reservation status (status before the update)

| Reservation status | Add line | Cancel line | Change type | Set/change/clear `room_id` |
| --- | --- | --- | --- | --- |
| `pending`, `confirmed` | ✅ | ✅ (not the last) | ❌ (cancel and add) | ✅ same type |
| `checked_in` | ❌ | ❌ | ❌ | ✅ change only (room move), same type, not clear |
| `checked_out`, `cancelled` | ❌ | ❌ | ❌ | ❌ |

When the reservation changes to `cancelled`, all active lines become `cancelled` in the
same transaction.

## Changed: `stays` (behavior only)

`StayService::syncFromReservation()` sets `stays.room_id = $reservation->primaryRoomId()`.
Still one stay per reservation (FR-016).

## Changed: `room_types` (behavior only)

- Deletion blocked while an active line on a non-cancelled reservation with
  `departure_date >= today` uses it (FR-028).
- Backfill may create one inactive type per hotel named **"Unspecified (migrated)"**
  (max_occupancy 2, adult 2, child 0, price 0) for reservations with no room.

## Derived: room occupancy

A room is `occupied` iff its id is in `ReservationRoom::inHouseRoomIds()`; an occupied
room with no such line is released to `available`. Rooms in other statuses are only
changed when a guest is in-house in them (unchanged rule). Recomputed for every room id
the operation touched (old and new room of a move, rooms of cancelled lines).

## Migrations (sequence)

1. `YYYY_MM_DD_000000_create_reservation_rooms_table.php`
2. `YYYY_MM_DD_000001_backfill_reservation_rooms.php`: idempotent (insert only for
   reservations with no line); includes soft-deleted reservations and trashed rooms;
   creates the placeholder type per hotel on demand; logs counts per hotel.
3. `YYYY_MM_DD_000002_drop_room_id_from_reservations_table.php`: down() re-adds the
   nullable column and fills it from the primary line.
