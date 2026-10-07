# Latest Changes: 2026-10-06

## Guest Services and Concierge on the New Domain (SPEC-044, SPEC-052, SPEC-053, SPEC-054, Phase 7)

### Summary

A guest's request now goes through the whole loop: the WhatsApp concierge files
it with the right team, staff handle it, and the guest is told when it is done.
Maintenance problems always go to the Maintenance team. Guests can ask to change
rooms, and staff decide. A guest who asks for a person twice gets one
escalation, not two. The concierge can tell guests about their own bookings and
open requests. Numbers that are not a guest or a staff member get no reply.

The concierge still cannot cancel bookings, move guests, change room or booking
statuses, or check anyone in or out. This is enforced in its tools, not only in
its instructions.

### Breaking changes

1. **Unknown WhatsApp numbers get no reply.** A number that matches no guest
   and no staff member used to get "Sorry, we couldn't recognize this
   number…". It now gets nothing, and its message is stored with status
   `ignored`. The one-time "please wait a minute" rate-limit notice also goes
   to known senders only. Pairing tokens are still accepted from any number.
2. **`guest_signal` has two new values**: `maintenance_request` and
   `room_change_request`. Anything that switches on `guest_signal` must handle
   unknown values.

### What's new

- **Guest notices.** When staff complete a guest's service, maintenance or
  room-change request, the guest is told. When staff approve or decline a
  booking cancellation request, the guest is told the outcome, and a decline
  includes the staff note. The message goes on WhatsApp if the guest wrote
  within the last 24 hours, and by email to their address otherwise. Cancelled
  requests, escalations and staff-created tasks send nothing. Each request
  notifies at most once.
- **New read-only task fields**: `guest_notice_status` (`pending`, `sent`,
  `skipped`, `failed`), `guest_notice_channel` (`whatsapp`, `email`),
  `guest_notice_reason` (`cancelled`, `no_contact`, `send_failed`) and
  `guest_notice_at`. All are filterable, e.g.
  `GET /api/task?filter[guest_notice_status]=failed` lists guests who could not
  be reached.
- **Maintenance requests** (`guest_signal = maintenance_request`) are always
  filed under the hotel's Maintenance category and team. Filing one never
  changes the room's status.
- **Room-change requests** (`guest_signal = room_change_request`) have no team,
  are visible to everyone who can see the hotel's tasks, and email the hotel's
  admins. At most one is open per stay (or per reservation before arrival).
  Staff move the guest through room assignment, as before.
- **One open escalation per guest.** Asking for a person again appends the new
  reason to the open escalation. Admins are not emailed again.
- **The concierge reads**:
  - the guest's own reservation, with each room's number and stay status;
  - their bookings, with price and currency;
  - their open requests, without staff names, notes or teams.
- **Guests with only past stays** can still get information and ask for a
  person. Service, maintenance and room-change requests and bookings need a
  current or upcoming reservation.
- **Activity suggestions pause** while a maintenance or room-change request is
  open, as they already did for service requests.
- A booking can only be credited to a recommendation made for the guest's own
  reservation, or to a hotel-wide one.

### Permissions

No new permissions and no new endpoints. Staff handle guest requests through the
existing task permissions (`tasks.*`), and decide cancellation requests with
`bookings.update_status`.

### Database

One migration, `2026_10_06_000001_add_guest_request_fields_to_tasks_table`:

- **Columns**: the four `guest_notice_*` columns, with check constraints.
- **Indexes**: partial unique indexes allowing one open escalation per guest
  and hotel, and one open room-change request per stay or reservation.
- **Existing data**:
  - Duplicate open escalations are merged into the newest one.
  - Guest tasks already closed are marked `skipped`, so nothing finished before
    this release is ever announced to a guest.

### Governance

The constitution was amended to v2.1.0: WhatsApp stays the guest's primary
contact channel, and email may be used for a transactional notice when WhatsApp
rules forbid the message.
