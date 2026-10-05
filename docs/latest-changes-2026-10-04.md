# Latest Changes: 2026-10-04

## Housekeeping and Maintenance (SPEC-030, SPEC-033, SPEC-035, with SPEC-003 and SPEC-004, Phase 5)

### Summary

Rooms now follow the work done on them. Cleaning tasks move a room dirty → cleaning →
clean (→ inspected, when the hotel inspects). Stay-over rooms get a cleaning task every
morning. Housekeepers report faults from their task, which creates a maintenance task and
can take the room off sale. Out of order has a reason, an owner and an explicit way back.
Every hotel now has Housekeeping and Maintenance teams.

**This changes the room API** (see Breaking changes).

### Breaking changes

1. **Room status values.** `status` is `available` / `occupied` / `out_of_order`
   (`maintenance` is gone). `housekeeping_status` is `dirty` / `cleaning` / `clean` /
   `inspected` (`blocked` is gone). Existing `maintenance` or `blocked` rooms were moved to
   `out_of_order` + `dirty`, with the reason "Migrated from previous status". A room that
   also had a guest in it became `occupied` + `dirty`, and a
   `room.status_migration_conflict` audit entry lists it for staff to handle.
2. **`POST /api/room` and `PUT /api/room/{id}` refuse `status` and `housekeeping_status`**
   with a `422` naming the actions to use instead:
   - out of order: `POST /api/room/{id}/out-of-order`, `PATCH /api/room/{id}/out-of-order`,
     `POST /api/room/{id}/return-to-service`;
   - cleanliness: cleaning and inspection tasks, or `PUT /api/room/{id}/housekeeping-status`
     for a correction with a reason.
   New rooms start `available` + `clean`.
3. **Completing an inspection task through `PUT /api/task/{id}`** is refused (`422`); use
   `POST /api/task/{id}/inspection`.
4. **A second open cleaning (or inspection) task for the same room** is refused (`422`,
   naming the open one).

### What's new

- `GET /api/housekeeping/board` — every room by housekeeping status, with counts,
  readiness, the in-house guest's departure and the open housekeeping task (`rooms.view`).
- `GET /api/maintenance/tasks` — the maintenance list: open first, most urgent, oldest,
  with room, reporter, age and out-of-order details (`tasks.view`).
- `PUT /api/room/{id}/housekeeping-status` — manual correction with a required reason
  (`rooms.update_housekeeping_status`).
- `POST` / `PATCH /api/room/{id}/out-of-order`, `POST /api/room/{id}/return-to-service`
  (`rooms.set_out_of_order`). Out of order is open-ended; `expected_end_date` is shown to
  staff only. Returning to service makes the room `available` + `dirty` with a cleaning
  task. Completing the linked maintenance task never reopens the room — the task update
  response carries `room_ready_to_return: true`.
- `POST /api/task/{id}/inspection` — `pass` → `inspected`; `fail` (note required) →
  `dirty` + a re-clean task (`tasks.update`).
- `POST /api/task/{id}/issues` — a room issue on a housekeeping task becomes a maintenance
  task for the Maintenance team; `room_unsellable: true` also takes the room out of order
  when the reporter may and nobody is in it (`tasks.update`).
- New fields: rooms `building`, `housekeeping_status_changed_at`, `ready`, `out_of_order`;
  tasks `housekeeping_kind`, `cleaning_reason`, `inspection_result`, `inspection_note`,
  `source_task_id`, `completed_at`; hotels `inspection_task_category_id`,
  `maintenance_team_id`, `maintenance_task_category_id`, `inspection_required`.
- New permissions (never employee defaults): `rooms.update_housekeeping_status`,
  `rooms.set_out_of_order`.

### Behavior changes

- **Default teams.** Every hotel — new and existing — has a *Housekeeping* team (*Cleaning*,
  *Inspection*) and a *Maintenance* team (*Maintenance*), set in its hotel settings. A team
  of that name that already existed was reused.
- **Team from category.** A task created or updated with `task_category_id` and no team
  goes to the category's team (this used to be a `403`).
- **Start of day replaces the overnight job.** `MakeRoomDirtyOvernightJob` (00:01 server
  time) is replaced by `StartHousekeepingDayJob`, hourly, once per hotel per local date:
  stay-over rooms become `dirty` and get one `stay_over` cleaning task. Out-of-order rooms
  and rooms departing that day are skipped.
- **Check-out** reuses an open stay-over task as the check-out clean, and dirties the room
  even if it is out of order.
- **Check-in warning** now says "Room 204 is not ready (dirty)." and also fires for a clean
  room awaiting inspection.
- **Notifications.** New and reassigned tasks email the assignee, or every member of the
  Housekeeping or Maintenance team when only that team is assigned. One email per person
  per assignment. Admins are emailed (once per day) when an automatic task has no team or
  an empty team. Other teams are not fanned out to.
- **Readiness on nested rooms.** `ready` is computed on the room endpoints and the board;
  it is `null` for a room nested in a stay, task or reservation.

### Frontend checklist (`ecosystem-frontend`, D13)

- [ ] Room form: drop the `status` and `housekeeping_status` inputs; add `building`.
- [ ] Room badges: new status values, `ready`, out-of-order reason and overdue flag.
- [ ] Out of order dialog (reason, expected end date, maintenance task) with the affected
      lines list; return-to-service action.
- [ ] Housekeeping board from `GET /api/housekeeping/board` with its filters.
- [ ] Task actions: start / complete cleaning, inspection pass / fail with note, "report
      issue" with the "room cannot be sold" switch.
- [ ] Maintenance list from `GET /api/maintenance/tasks`.
- [ ] Hotel settings: inspection required, and the five default team/category pickers.
- [ ] Role editor picks up the two new permissions from `GET /api/permissions`.

### Docs

[room-api-documentation.md](room-api-documentation.md),
[task-management-api-documentation.md](task-management-api-documentation.md),
[hotel-api-documentation.md](hotel-api-documentation.md),
[stays-api-documentation.md](stays-api-documentation.md),
[staff-roles-api-documentation.md](staff-roles-api-documentation.md).
