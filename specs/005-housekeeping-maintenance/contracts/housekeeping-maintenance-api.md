# Contract: Housekeeping and Maintenance API

**Feature**: [../spec.md](../spec.md) · **Decisions**: [../research.md](../research.md) ·
**Model**: [../data-model.md](../data-model.md)

All routes are in the tenant group of `routes/api.php`
(`api.key → auth:sanctum → throttle:api → tenant`). Every response has the shape
`apiResponse(message, code, body)` → `{ "message", "code", "body" }`. Single-record
endpoints resolve the hotel from the record. A super admin must pass `hotel_id` on the
board and on the maintenance list. A record from another hotel returns `404`, because
route-model binding runs under the hotel scope.

## Changed resources

### `RoomResource` (+ fields)

```json
{
  "id": "uuid",
  "room_number": "204",
  "floor": "2",
  "building": "Main",
  "status": "available | occupied | out_of_order",
  "housekeeping_status": "dirty | cleaning | clean | inspected",
  "housekeeping_status_changed_at": "2026-10-04T10:12:00+03:00",
  "ready": true,
  "out_of_order": null
}
```

While the room is out of order, `out_of_order` holds:

```json
{
  "reason": "AC broken",
  "since": "2026-10-04T09:00:00+03:00",
  "expected_end_date": "2026-10-06",
  "overdue": false,
  "by_user": { "id": "uuid", "name": "…" },
  "task_id": "uuid"
}
```

### `TaskResource` (+ fields)

The resource gains `housekeeping_kind` (`cleaning | inspection | null`), `cleaning_reason`,
`inspection_result`, `inspection_note` and `source_task_id`. None of them is writable
through `POST`/`PUT /task`.

### `HotelResource` (+ fields)

The resource gains `inspection_task_category_id`, `maintenance_team_id`,
`maintenance_task_category_id` and `inspection_required`. They are writable through
`PUT /hotel/{id}`, which is admin-only and follows the validation in research R8.

## Breaking changes to existing endpoints

| Endpoint | Change |
| --- | --- |
| `POST /room`, `PUT /room/{id}` | `status` and `housekeeping_status` are rejected with `422`. The message names the actions below. New rooms start `available` + `clean`. `building` is accepted |
| `GET /room` | `filter[status]` and `filter[housekeeping_status]` take the new values. Old values return an empty result, because the column only holds new values |
| `PUT /task/{id}` | `status=completed` on an inspection task → `422` "Use the inspection action". A second open cleaning or inspection task for the same room → `422` naming the existing task |
| `POST /task`, `PUT /task/{id}` | If `task_category_id` is given without a team, the team now comes from the category. This used to return `403` |
| `PUT /task/{id}` response | Adds `room_ready_to_return: true` when a completed maintenance task tracks an out-of-order room (FR-019) |
| `POST /reservation/{id}/check-in`, `POST /stays/{id}/check-in` | The warning now says the room is "not ready" (R6) |
| `POST /reservation/{id}/check-out`, `POST /stays/{id}/check-out` | `cleaning_tasks` may return an existing stay-over task, now with `cleaning_reason: check_out`, instead of creating a new one |

## Housekeeping status — permission `rooms.update_housekeeping_status`

### `PUT /api/room/{room}/housekeeping-status`

```json
{ "housekeeping_status": "clean", "reason": "Cleaned without a task" }
```

The reason is required, with at most 500 characters.

| Result | Response |
| --- | --- |
| Status changed | `200 { room: RoomResource, open_housekeeping_tasks: TaskResource[] }`. The tasks are listed, not changed (FR-009) |
| Same status as now | `200`, with no audit entry |
| Missing permission | `403` |
| Bad value or missing reason | `422` |

## Out of order — permission `rooms.set_out_of_order`

### `POST /api/room/{room}/out-of-order`

```json
{ "reason": "AC broken", "expected_end_date": "2026-10-06", "task_id": "uuid" }
```

| Result | Response |
| --- | --- |
| Taken out of order | `200 { room, changed: true, affected_lines: [...] }` |
| Already out of order | `200 { room, changed: false }` |
| Room has an in-house stay | `422 { "message": "Room 204 has an in-house guest (stay …). Move the guest first." }` |
| `task_id` from another hotel | `403` (`invalidRelation`) |
| `expected_end_date` before the hotel's today | `422` |

`affected_lines` lists live reservation lines assigned to this room that depart after the
hotel's today. Each item has the shape
`{ reservation_room_id, reservation_id, reservation_code, arrival_date, departure_date }`.

### `PATCH /api/room/{room}/out-of-order`

```json
{ "reason": "AC compressor on order", "expected_end_date": "2026-10-09" }
```

Both fields are optional; at least one is required. → `200 { room }`. A room that is not
out of order gets `422`.

### `POST /api/room/{room}/return-to-service`

```json
{ "note": "Compressor replaced" }
```

→ `200 { room, cleaning_task: TaskResource }`. The room becomes `available` + `dirty`, and
the cleaning task has `cleaning_reason: return_to_service`. An existing open cleaning task
is reused. A room that is not out of order gets `422`.

## Inspection — `tasks.update` on the task

### `POST /api/task/{task}/inspection`

```json
{ "result": "fail", "note": "Hair in the shower, bin not emptied" }
```

`note` is required when `result` is `fail`.

| Result | Response |
| --- | --- |
| `pass` | `200 { task, room }`. The task is completed and the room becomes `inspected` |
| `fail` | `200 { task, room, cleaning_task }`. The task is completed, the room becomes `dirty`, and a new `re_clean` task carries the note |
| Task is not an inspection task, or is not open | `422` |
| Task is completed again with the same result | `200`, with no change (idempotent) |

## Room issue — `tasks.update` on the source task

### `POST /api/task/{task}/issues`

```json
{ "description": "Shower leaking at the base", "priority": "high", "room_unsellable": true }
```

`priority` defaults to `normal`. The source task must meet all of these (R11):

- it is a housekeeping task (it has a `housekeeping_kind`, or a Housekeeping-team
  category);
- it has a room;
- it is open, or was completed on the hotel's today.

Otherwise the request gets `422`.

Response:

```json
{
  "maintenance_task": "TaskResource",
  "created": true,
  "out_of_order": { "requested": true, "applied": false, "reason": "You do not have permission to take rooms out of order." }
}
```

- `201` when a maintenance task is created. `200` with `created: false` when the same open
  report already exists (FR-025).
- When `out_of_order.applied` is false, `reason` is one of: no `rooms.set_out_of_order`
  permission; the room has an in-house guest; the room is already out of order.

## Board — permission `rooms.view`

### `GET /api/housekeeping/board`

Query parameters: `housekeeping_status`, `status`, `floor`, `building`, `team_id`, and
`hotel_id` for a super admin.

```json
{
  "date": "2026-10-04",
  "inspection_required": false,
  "counts": { "dirty": 12, "cleaning": 3, "clean": 40, "inspected": 0, "out_of_order": 2 },
  "rooms": [
    {
      "room": "RoomResource",
      "departure_date": "2026-10-06",
      "open_task": { "id": "uuid", "housekeeping_kind": "cleaning", "cleaning_reason": "stay_over", "status": "pending", "assigned_to_team_id": "uuid", "assigned_to_user_id": null }
    }
  ]
}
```

`departure_date` is the in-house stay's departure date, or `null`. Rooms are sorted by
building, then floor, then room number. The list is not paginated, because it is a single
day's board.

## Maintenance list — permission `tasks.view`

### `GET /api/maintenance/tasks`

This is a generic index (`GenericIndexRequest`). It accepts:

- filters: `filter[status]`, `filter[priority]`, `filter[room_id]`,
  `filter[room_out_of_order]=true|false`;
- `search` (title, description);
- `sort`, `page`, `per_page`.

The default sort is open tasks first, then priority (urgent first), then `created_at`
(oldest first). Each row is a `TaskResource` with these extra fields:

- `room { id, room_number, status, out_of_order }`
- `reporter { id, name }`
- `source_task_id`
- `age_hours`

## Notifications (no endpoint)

| Trigger | Recipients |
| --- | --- |
| Task created, or its assignee changed | The assigned user; or, when only a Housekeeping or Maintenance team is assigned, that team's members. One notice per person per assignment (R12) |
| Automatic task with no team, or a team without members | The hotel's admins, at most once per hotel per day per cause |

## Permission reference additions

| Permission | Endpoints |
| --- | --- |
| `rooms.update_housekeeping_status` | `PUT /room/{room}/housekeeping-status` |
| `rooms.set_out_of_order` | `POST`/`PATCH /room/{room}/out-of-order`, `POST /room/{room}/return-to-service`, and the out-of-order step of `POST /task/{task}/issues` |
| `rooms.view` (existing) | `GET /housekeeping/board` |
| `tasks.view` (existing) | `GET /maintenance/tasks` |
| `tasks.update` (existing) | `POST /task/{task}/inspection`, `POST /task/{task}/issues` |
