# Data Model: Stay Lifecycle and Check-in/out

**Feature**: [spec.md](spec.md) · **Decisions**: [research.md](research.md)

## Schema changes

### `stays` (changed)

| Column | Change | Notes |
| --- | --- | --- |
| `reservation_room_id` | **ADD** uuid, nullable, FK → `reservation_rooms.id`, `nullOnDelete` | One stay per line (R1). Null only for legacy stays without a reservation |
| `reservation_id` | keep | Denormalised; must equal the line's reservation |
| — | **DROP** `unique(reservation_id)` | D3 |
| — | **ADD** unique `(reservation_room_id) WHERE deleted_at IS NULL AND reservation_room_id IS NOT NULL` | FR-001 |
| — | **ADD** unique `(room_id) WHERE status = 'in_house' AND deleted_at IS NULL` | One guest per room at a time (R6) |

All other columns are unchanged. `adults` / `children` / `room_revenue` now hold the
line's share (R4).

### `tasks` (changed)

| Column | Change | Notes |
| --- | --- | --- |
| `stay_id` | **ADD** uuid, nullable, FK → `stays.id`, `nullOnDelete`, indexed | FR-018. Added to `$fillable` and `eventLoggedAttributes()` |

### `hotels` (changed)

| Column | Change | Notes |
| --- | --- | --- |
| `housekeeping_team_id` | **ADD** uuid, nullable, FK → `teams.id`, `nullOnDelete` | Default team for cleaning tasks (R10). Must be a team of the same hotel and active |
| `cleaning_task_category_id` | **ADD** uuid, nullable, FK → `task_categories.id`, `nullOnDelete` | Default category; must belong to the same hotel and to `housekeeping_team_id` when both are set |

Set through `PUT /api/hotel/{id}` (admin-only). SPEC-004 fills both for every hotel.

### No other tables

`reservations`, `reservation_rooms`, `rooms` keep their columns. Room status and
housekeeping status use the existing enums (bridge in R8).

### Migrations (in order)

1. `2026_09_24_000001_add_reservation_room_id_to_stays_table.php` — column + FK (nullable).
2. `2026_09_24_000002_backfill_stays_per_reservation_room.php` — drop `unique(reservation_id)`,
   then R22 steps 1–3 (`down()` restores the index).
3. `2026_09_24_000003_replace_stay_uniqueness.php` — R22 step 4 conflict check, then both
   partial unique indexes.
4. `2026_09_24_000004_add_stay_id_to_tasks_table.php`.
5. `2026_09_24_000005_add_housekeeping_defaults_to_hotels_table.php`.

## Entities

### Stay

- **Belongs to**: hotel, guest (the reservation's primary guest), reservation, reservation
  line (`reservationRoom()` — new), room (the line's room).
- **Has many**: tasks (new), transactions, bookings, pitch decisions, outcomes (existing).
- **Planned side** (follows the reservation while `expected`; R4): `planned_arrival_date`,
  `planned_departure_date`, `guest_id`, `room_id`, `adults`, `children`, `room_revenue`,
  `currency`, `source_channel`.
- **Actual side** (written only by `StayLifecycleService` or the import): `status`,
  `checked_in_at`, `checked_out_at`, `nights`.

**State machine** (`StayStatus`, unchanged cases):

```text
                 check-in (FR-007/008)            check-out (FR-011/012)
   expected ───────────────────────────► in_house ───────────────────────► departed
     │  ▲                                                                  (terminal)
     │  │ line / reservation reinstated
     ▼  │
   cancelled  ◄── line or reservation cancelled (only while expected; FR-021)

   no_show — reserved for SPEC-012; not reachable in this feature
```

| Transition | Trigger | Guards | Side effects |
| --- | --- | --- | --- |
| — → expected | line created | — | planned side copied |
| expected → cancelled | line cancelled / reservation cancelled | stay is expected | — |
| cancelled → expected | line reinstated / reservation reactivated | — | planned side re-copied |
| expected → in_house | check-in | R7 table | room → occupied; reservation → checked_in if not already; optional room assignment (R9); audit `stay.checked_in` |
| in_house → in_house | check-in again | — | none (idempotent) |
| in_house → departed | check-out | FR-012a (no expected sibling left when this is the last in-house line) | room → available (unless out of order) + dirty (unless blocked); cleaning task; reservation → checked_out when no line is in-house; audit `stay.checked_out` |
| departed → departed | check-out again | — | none (idempotent) |
| (import) any → in_house / departed | `ReservationsImport` | none | none (history; R14) |

**Validation rules**

- `reservation_room_id` unique among live stays; its line's `reservation_id` must equal
  `stays.reservation_id`.
- At most one `in_house` stay per `room_id`.
- `checked_in_at` / `checked_out_at` supplied by a request: on the current hotel day,
  not in the future; `checked_out_at ≥ checked_in_at` (R17).
- `nights = max(0, localDate(checked_out_at) − localDate(checked_in_at))`.

### Reservation (behavior change only)

- New `stays()` HasMany; `stay()` = primary stay (R3).
- Status `checked_in`: set by the service when its **first** line checks in.
  Status `checked_out`: set when its **last** in-house line checks out and no line is
  expected (FR-012a). Neither is set by the general edit any more (R12).
- Cannot be cancelled or deleted while any stay is `in_house` (R13).

### Reservation line (`ReservationRoom`, behavior change only)

- New `stay()` HasOne.
- Cannot be cancelled or removed while its stay is `in_house`.
- `room_id` may now also be set by check-in (R9), audited as `reservation_room.updated`.

### Room (behavior change only)

- New `isOutOfOrder()`: `status ∈ RoomStatusesEnum::outOfOrder()` or
  `housekeeping_status = blocked` (R8).
- Check-in → `status = occupied`. Check-out → `status = available` if it was `occupied`;
  `housekeeping_status = dirty` unless `blocked`.
- New `stays()` HasMany (for "who is in this room").

### Hotel (changed)

- New `housekeepingTeam()` / `cleaningTaskCategory()` BelongsTo, both fillable, read by
  `HousekeepingDefaults::for()` (an inactive team or a deleted choice resolves to null).

### Task (changed)

- New `stay()` BelongsTo. When `stay_id` is set with `room_id` / `reservation_id`, those
  must equal the stay's (422 otherwise; FR-018). `guest_id` is derived from the stay
  when the stay is given.
- Cleaning task attributes: R11.

### Audit events (existing mechanism)

| Event | Subject | Written by | Notable `changes` |
| --- | --- | --- | --- |
| `stay.checked_in` | Stay | `RecordsEvents` via `eventVerbFor()` | `status`, `checked_in_at`, `room_id`; `entered_at` when a time was given |
| `stay.checked_out` | Stay | same | `status`, `checked_out_at`, `nights`; `entered_at` when given |
| `reservation.updated` | Reservation | `RecordsEvents` | `status` → checked_in / checked_out |
| `room.updated` | Room | `RecordsEvents` (Room gains the trait — it has none today) | `status`, `housekeeping_status` |
| `reservation_room.updated` | Line | `RecordsEvents` | `room_id` (assignment at check-in) |
| `task.created` | Task | `RecordsEvents` | cleaning task |

`actor_kind = ai_agent` inside `EventLogger::asAiAgent()`, with `actor_id` = the admin the
AI acts for.
