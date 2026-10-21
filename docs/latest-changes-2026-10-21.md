# Latest changes — 2026-10-21

## Reservation lifecycle and no-shows (SPEC-012)

**New status `no_show`**: the guest never arrived. A no-show holds no rooms, isn't on the
arrivals list, gets no pitches or proactive messages, and expires its pending
recommendations. No message goes to the guest.

**New endpoints** (permission `reservations.no_show`, not a default; optional `reason`):

- `POST /api/reservation/{id}/no-show` and `POST /api/reservation/{id}/undo-no-show`
- `POST /api/stays/{id}/no-show` and `POST /api/stays/{id}/undo-no-show`

A no-show can be marked from the arrival day. A whole reservation needs no room checked in
yet; otherwise mark the missing rooms one at a time, and they no longer block the last
check-out. An undo works at any time (a room's undo only until the reservation is checked
out) and re-checks availability, with `overbook_override` as on create and update.

**Status changes now follow one lifecycle.** See the table in
`docs/reservations-api-documentation.md`. Behaviour changes for existing clients:

- `PUT /api/reservation/{id}` rejects status changes outside the table with `422` on
  `status`, e.g. `checked_out → pending`. These were silently accepted before.
- `status: no_show` on create or edit → `422`, pointing to the no-show endpoint.
- While a reservation is `no_show`, only `guests` and `special_requests` can be edited;
  other changed fields → `422` "Undo the no-show first."

**Additive**: each reservation room line gains `stay_id` and `stay_status`; the Admin AI gets a no-show
tool (it asks the admin to confirm before marking); `reservations.no_show` appears in
`GET /api/permissions`.
