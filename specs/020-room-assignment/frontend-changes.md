# Frontend changes: Room Assignment

For `ecosystem-frontend`. API details:
[reservations API § 8 Room assignment](../../docs/reservations-api-documentation.md#8-room-assignment-2026-10-30),
[stays API § Room move](../../docs/stays-api-documentation.md#room-move-2026-10-30) and
[latest changes 2026-10-30](../../docs/latest-changes-2026-10-30.md).

## 1. Assignment dialog (reservation detail)

- One dialog for all of a reservation's lines: pick a room per line, change it, or clear it,
  then send everything in one `PUT /api/reservation/{id}/rooms`. All or nothing; two lines
  can swap rooms in the same call.
- Offer rooms of the line's type first. Show other types under an **Upgrade** option; picking
  one sends `upgrade: true` for that line.
- Lines whose guest is in the house are read-only here; show a **Move guest** action instead
  (§3). Lines whose guest left or was a no-show are read-only.
- Show `body.warnings` (an out-of-order room is accepted with a warning).
- Errors arrive as `assignments.{i}.room_id`; show each next to its line. A clash message names
  the other reservation's code: link to it.
- An upgrade into a full type comes back as the usual overbooking shortfall; offer
  `overbook_override` only to users with `reservations.overbook`, as on create.
- Show the action only with `reservations.assign_room`.

## 2. Arrivals that still need a room

- New page or panel: `GET /api/reservation/unassigned-arrivals` (default today + 7 days, max
  31 days, sortable by `arrival_date`, `reservation_code`, `room_type`, paginated).
- Each row opens the assignment dialog for its reservation. Mark `is_late: true` rows as late.
- Show only with `reservations.assign_room`.

## 3. Move guest dialog (in-house stay)

- `POST /api/stays/{id}/move-room` with `room_id` and a required `reason` (max 500), plus
  `upgrade: true` for a room of another type.
- After success: the old room shows as dirty with a cleaning task (`cleaning_task_id`). If
  `open_room_change_requests` is not empty, prompt staff to complete that request once the
  guest knows. Remind staff the guest was **not** messaged.
- Show only with `reservations.move_room`.

## 4. Reservation create and edit forms

- Choosing, changing or clearing a line's room now needs `reservations.assign_room`; hide or
  disable the room pickers without it. Sending a line's current room back unchanged is fine.
- A room already booked for overlapping nights is refused: `rooms.{i}.room_id` (or `rooms` when
  dates move) "Room 204 is booked for RES-0042 on overlapping nights."
- Changing an in-house guest's room in the edit form is refused ("…move the guest with the
  room-move action."): route that to the move dialog instead.
- Un-cancelling may return the reservation without a room it had; the message says so. Show it.
- A `422` on `rooms` "A room on this booking was just taken by another reservation…" means
  someone else won a race: reload and pick another room.

## 5. Display

- `rooms[].is_upgrade` on the reservation: show an "Upgraded" badge with the room's type next
  to the booked type.
- Availability now counts an upgraded line against the room's type; no UI change needed.

## 6. Role editor

- Two new permissions, `reservations.assign_room` and `reservations.move_room`, come from
  `GET /api/permissions` as before; nothing to hard-code.
