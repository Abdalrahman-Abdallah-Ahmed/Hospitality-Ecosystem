# Latest changes — 2026-10-30

## Transactions switched off (SPEC-005, D11)

Finance is not part of the MVP, so the transactions ledger is now behind a platform
setting that is off by default. Code and tables are kept. Spec:
`specs/019-transactions-feature-flag`.

### Breaking

- **The four transaction endpoints answer `404`** for every role while the ledger is
  off: `GET /api/transaction`, `GET /api/transaction/{id}`,
  `POST /api/transaction/import`, `POST /api/transaction/{id}/reverse`. The message is
  "The transactions feature is not enabled."; a missing permission is still `403`. See
  `docs/transaction-api-documentation.md`.
- **`settlement_rate`, `settled_value` and `settled_value_by_currency` are absent** from
  `GET /api/analytics/conversion` while it is off.
- **`transactions` is absent** from `GET /api/booking/{id}` (and from any stay or guest
  response) while it is off.
- **`GET /api/permissions` leaves out the `transactions` group** while it is off. Roles
  that already hold those permissions keep them.
- The conversion **notes** no longer mention the settlement rate while it is off.

### Added

- **`transactions_enabled`** on the hotel response (read-only), so the frontend can
  hide the Transactions menu. See `docs/hotel-api-documentation.md`.
- **`FEATURE_TRANSACTIONS`** (default `false`). Turning it on is an environment change
  plus deploy; no endpoint, hotel setting or role can change it.

Frontend: `specs/019-transactions-feature-flag/frontend-changes.md`.

## Room assignment (SPEC-021)

Physical rooms can no longer be double-booked, and assigning, changing, upgrading and
moving rooms have their own actions. Spec: `specs/020-room-assignment`.

### Before deploying

Run `php artisan rooms:overlaps` before `php artisan migrate`. It lists any room already
held by two bookings on a shared night; resolve each (remove or change one side's room)
first. The migration refuses to run while any remain.

### Breaking

- **A room held by another booking on a shared night is refused** everywhere a room is
  set: `POST /api/reservation`, `PUT /api/reservation/{id}` (rooms or new dates), check-in
  with a named room and the Admin AI. `422` "Room 204 is booked for RES-0042 on
  overlapping nights." Before, these were saved.
- **Setting, changing or clearing `rooms[].room_id` on create or edit needs
  `reservations.assign_room`** as well (`403` without). Echoing the current room needs
  nothing more.
- **An in-house guest's room can no longer be changed through `PUT /api/reservation/{id}`**:
  `422` "Room 204 is checked in; move the guest with the room-move action." Use
  `POST /api/stays/{id}/move-room`, which needs a reason and `reservations.move_room`.
- **Un-cancelling** a reservation whose room another booking took meanwhile brings the
  line back without that room; the `message` says so.
- The type error now reads "Room 501 is not of the line's room type; send upgrade to place
  it anyway."
- The Admin AI's room tool now needs `reservations.assign_room` (instead of
  `reservations.update`), and naming a specific room when the advisor creates a
  reservation needs it too.

### Added

- `PUT /api/reservation/{id}/rooms` — assign, change, remove and upgrade rooms in one call.
- `GET /api/reservation/unassigned-arrivals` — arrivals that still need a room.
- `POST /api/stays/{id}/move-room` — move a guest in the house, with a reason.
- Permissions `reservations.assign_room`, `reservations.move_room` (not defaults).
- `rooms[].is_upgrade` on the reservation object.
- Availability counts an upgraded line against the room's type.
- Admin AI room move tool.

Frontend: `specs/020-room-assignment/frontend-changes.md`.
