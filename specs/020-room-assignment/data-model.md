# Data Model: Physical Room Assignment & Reassignment

**Feature**: `specs/020-room-assignment` · **Date**: 2026-10-30

One migration-backed change (a derived column, a constraint, triggers), one derived
attribute, no new tables.

## reservation_rooms (changed)

| Column | Type | Change | Notes |
| --- | --- | --- | --- |
| `held_nights` | `daterange` NULL | **new** | `[arrival_date, departure_date)` while the line holds its room; `NULL` otherwise. Written only by triggers (research R1); never in `$fillable`, never in API responses. |

**Constraint (new)**: `reservation_rooms_no_overlap`
`EXCLUDE USING gist (room_id WITH =, held_nights WITH &&) WHERE (held_nights IS NOT NULL)`.
Requires extension `btree_gist`.

**`held_nights` is non-null iff all hold**:

- `room_id IS NOT NULL`
- `status <> 'cancelled'` and `deleted_at IS NULL`
- the reservation has `deleted_at IS NULL` and `status IN ('pending','confirmed','checked_in')`
- the line has no stay, or its stay (not soft-deleted) has `status NOT IN ('departed','no_show')`

This is exactly `RoomAssignmentRules::holdingLines()` plus "has a room"; a test asserts the
two agree on a seeded mix of states.

**Triggers (new)**

| Table | Timing / events | Effect |
| --- | --- | --- |
| `reservation_rooms` | BEFORE INSERT OR UPDATE | sets `NEW.held_nights` from the rules above |
| `reservations` | AFTER UPDATE OF `arrival_date`, `departure_date`, `status`, `deleted_at` | re-touches its lines |
| `stays` | AFTER INSERT OR UPDATE OF `status`, `deleted_at`, `reservation_room_id`; AFTER DELETE | re-touches the affected line(s) |

Down migration drops triggers, function, constraint and column (extension left in place).

**Existing**: `reservation_rooms_reservation_room_unique` (same room twice in one
reservation) is kept; the new constraint subsumes it for holding lines but not for
non-holding statuses, and `applyPlan()` already orders writes around it.

## Derived: effective room type and upgrade

| Name | Definition | Used by |
| --- | --- | --- |
| effective type | `room.room_type_id` when the line has a room, else `line.room_type_id` | `AvailabilityService` counting, footprint, guard |
| `is_upgrade` | line has a room **and** `room.room_type_id <> line.room_type_id` | `ReservationRoomResource`; the `room_upgraded` audit event (`booked_type`, `room_type`) is recorded when an upgrade is placed |

No column. Reassigning to a room of the booked type or unassigning makes `is_upgrade`
false automatically (FR-017).

## Validation rules (from spec)

| Rule | Source | Error field / message |
| --- | --- | --- |
| Room exists in the line's hotel | FR-002a | `assignments.N.room_id` "The selected room is not available." |
| Type equals booked type, or `upgrade: true` | FR-002b, FR-015 | "Room 305 is not of the line's room type; send upgrade to place it anyway." |
| No overlapping holding line elsewhere | FR-002c, FR-009 | "Room 204 is booked for RES-0042 on overlapping nights." |
| Line live; reservation pending/confirmed/checked_in; stay not departed/no_show | FR-002d | "Room changes are closed for this reservation (checked out)." |
| Not in house (assign endpoint, general edit) | FR-006, R5 | "Room 204 is checked in; move the guest with the room-move action." |
| Effective-type availability | FR-016 | existing `InsufficientAvailabilityException` shape |
| Move: stay in house; reason 1–500 chars; target not out of order; target has no in-house stay; free on every night of the stay | FR-013 | `stay`, `reason`, `room_id` |
| Same room twice in one request | existing | unchanged |

## State effects

**Assign / reassign / unassign** (guest not checked in): `line.room_id` changes → triggers
recompute `held_nights` → availability counts effective type. No room status change
(nobody is in the room). Audit: `reservation_room.updated` with old/new `room_id`
(existing `RecordsEvents`).

**Move** (stay `in_house`), one transaction:

```text
line.room_id      A → B          (audited, existing event)
stay.room_id      A → B          (via syncStays)
room B.status     → occupied     (syncRoomOccupancy)
room A.status     occupied → available (if nobody else in it)
room A.housekeeping → dirty + cleaning task (CleaningReason::CHECK_OUT)
stay event        room_moved {from_room, to_room, reason}
```

Stay `check_in_at` unchanged; reservation dates, status and booked types unchanged.

## Entities touched (no schema change)

- **Stay**: gains the `room_moved` audit event; `room_id` follows the line (existing).
- **Room**: status/housekeeping changes only through existing services.
- **Task**: cleaning task (existing housekeeping service); room-change request tasks are
  read, not changed.
- **Permission enum**: `RESERVATIONS_ASSIGN_ROOM`, `RESERVATIONS_MOVE_ROOM`.
