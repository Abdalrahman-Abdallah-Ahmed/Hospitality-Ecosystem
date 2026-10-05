# Data Model: Housekeeping and Maintenance

**Feature**: [spec.md](spec.md) · **Decisions**: [research.md](research.md)

Every table below is hotel-scoped through `BelongsToHotel`, or reached through a model that
is. Every key is a UUID.

## Enums

| Enum | Values | Notes |
| --- | --- | --- |
| `RoomStatusesEnum` (changed) | `available`, `occupied`, `out_of_order` | `maintenance` removed (R1). `outOfOrder()` → `[OUT_OF_ORDER]` |
| `HousekeepingStatusesEnum` (changed) | `dirty`, `cleaning`, `clean`, `inspected` | `blocked` removed (R1) |
| `HousekeepingKind` (new) | `cleaning`, `inspection` | On tasks that drive a room (R4) |
| `CleaningReason` (new) | `check_out`, `stay_over`, `re_clean`, `return_to_service`, `manual` | Only on cleaning tasks |
| `InspectionResult` (new) | `pass`, `fail` | Only on completed inspection tasks |
| `HousekeepingCause` (new) | `task`, `check_out`, `start_of_day`, `inspection`, `issue`, `return_to_service`, `manual`, `migration` | Audit only (R13) |
| `Permission` (changed) | + `rooms.update_housekeeping_status`, `rooms.set_out_of_order` | Not in `employeeDefaults()` |

## rooms (changed)

| Column | Type | Rule |
| --- | --- | --- |
| `status` | varchar + check | `available` / `occupied` / `out_of_order`. Cast to `RoomStatusesEnum`. Not writable through `POST`/`PUT /room` (R2) |
| `housekeeping_status` | varchar + check | `dirty` / `cleaning` / `clean` / `inspected`. Written only by `HousekeepingService` |
| `housekeeping_status_changed_at` | timestamptz, null | Set on every housekeeping change. Used for readiness (R6) |
| `building` | varchar(100), null | D6. Writable through the generic room edit, like `floor` |
| `out_of_order_reason` | text, null | Required while `status = out_of_order` |
| `out_of_order_since` | timestamptz, null | Set when taken out of order |
| `out_of_order_until` | date, null | Expected end date. Information only, never read by availability |
| `out_of_order_by_user_id` | uuid FK users, null on delete | Who took it out of order |
| `out_of_order_task_id` | uuid FK tasks, null on delete | The maintenance task tracking it (same hotel) |

**Invariants**

- `status = out_of_order` ⇔ `out_of_order_reason` and `out_of_order_since` are set.
  Returning to service clears all five `out_of_order_*` columns.
- `status = out_of_order` ⇒ no in-house stay in the room. This is enforced in
  `MaintenanceService` while the room row is locked.
- `status = occupied` ⇔ an in-house stay exists (unchanged; `syncRoomStatus`).
  `syncRoomStatus` never changes an `out_of_order` room.

**Room status transitions**

```text
available ──check-in──▶ occupied ──last check-out──▶ available
available ──take out of order (no in-house stay)──▶ out_of_order
out_of_order ──return to service──▶ available   (+ housekeeping dirty, cleaning task)
occupied ──take out of order──▶ ✗ rejected (FR-017)
```

**Housekeeping status transitions** (all through `HousekeepingService`)

```text
any ──check-out / start of day / return to service / failed inspection──▶ dirty
dirty ──cleaning task started──▶ cleaning
cleaning ──cleaning task pending again / cancelled / deleted──▶ dirty
cleaning ──cleaning task completed──▶ clean   (+ inspection task if inspection_required)
clean ──inspection passed──▶ inspected
clean ──inspection failed──▶ dirty   (+ re_clean cleaning task with the note)
any ──manual correction (reason required)──▶ any
```

**Ready** (computed, not stored): `inspected`, or `clean` when `inspection_required` is
false or `housekeeping_status_changed_at < inspection_required_since`.

## hotels (changed)

| Column | Type | Rule |
| --- | --- | --- |
| `housekeeping_team_id` | existing | Filled by `HotelOperationalDefaults` when empty |
| `cleaning_task_category_id` | existing | Must belong to the housekeeping team |
| `inspection_task_category_id` | uuid FK task_categories, null on delete | Must belong to the housekeeping team |
| `maintenance_team_id` | uuid FK teams, null on delete | Active team of this hotel |
| `maintenance_task_category_id` | uuid FK task_categories, null on delete | Must belong to the maintenance team |
| `inspection_required` | boolean, default false | Admin-only setting |
| `inspection_required_since` | timestamptz, null | Set each time `inspection_required` turns on |

## tasks (changed)

| Column | Type | Rule |
| --- | --- | --- |
| `housekeeping_kind` | varchar + check, null | Set by `HousekeepingService` from the category on create and on category change (R4). Not client-writable |
| `cleaning_reason` | varchar + check, null | Only when `housekeeping_kind = cleaning`. A staff-created cleaning task gets `manual` |
| `inspection_result` | varchar + check, null | Only on a completed inspection task. Set only by the inspection action |
| `inspection_note` | text, null | Required when `inspection_result = fail` |
| `source_task_id` | uuid FK tasks, null on delete | On maintenance tasks created from an issue report (same hotel) |

**Indexes**

- `tasks_one_open_housekeeping_kind_per_room`: unique `(room_id, housekeeping_kind)` where
  `housekeeping_kind is not null and status in ('pending','in_progress') and deleted_at is
  null` (FR-007).
- `(source_task_id)` for the idempotency lookup of issue reports (R11).

**Rules**

- If a category is given and the team is missing, the team comes from the category (R9).
- An inspection task cannot be set to `completed` through `PUT /task`. It is completed
  through the inspection action only.
- A maintenance task is any task whose category belongs to the hotel's maintenance team, or
  whose team is the maintenance team. It is derived, not stored.

## task_notification_receipts (new)

| Column | Type | Rule |
| --- | --- | --- |
| `id` | uuid | |
| `task_id` | uuid FK tasks, cascade on delete | |
| `user_id` | uuid FK users, cascade on delete | |
| `notified_at` | timestamptz | |

Unique `(task_id, user_id)`. A row exists while that person has been told about the task's
current assignment. It is deleted when the assignment moves away from them (R12). The table
is not tenant-scoped itself; it is reached only through a scoped task.

## housekeeping_day_runs (new)

| Column | Type | Rule |
| --- | --- | --- |
| `id` | uuid | |
| `hotel_id` | uuid FK hotels, cascade on delete | `BelongsToHotel` |
| `day` | date | The hotel's local date |
| `rooms_dirtied` | int | Summary |
| `tasks_created` | int | Summary |
| `created_at` | timestamptz | |

Unique `(hotel_id, day)`: the start-of-day process runs once per hotel per day (FR-013).

## Teams and task categories (unchanged schema)

There are no new columns. `HotelOperationalDefaults::ensure()` creates the following,
unless the matching hotel setting is already filled:

- Housekeeping team, with Cleaning and Inspection categories.
- Maintenance team, with a Maintenance category.

Team members are `users.team_id`. The "active members" of a team are the users with that
`team_id` in the same hotel, and only while the team itself is active.

## Audit events (event_log)

| Event | Subject | `changes` carries |
| --- | --- | --- |
| `room.housekeeping_changed` | Room | `housekeeping_status {from,to}`, `cause`, `task_id?`, `reason?` |
| `room.taken_out_of_order` | Room | `status`, `out_of_order_reason`, `out_of_order_until`, `out_of_order_task_id` |
| `room.out_of_order_updated` | Room | changed `out_of_order_*` fields |
| `room.returned_to_service` | Room | `status {from,to}`, `note?` |
| `room.status_migration_conflict` | Room | what the migration could not map (R19) |
| `task.created` / `task.updated` | Task | + `housekeeping_kind`, `cleaning_reason`, `inspection_result`, `inspection_note`, `source_task_id` |
