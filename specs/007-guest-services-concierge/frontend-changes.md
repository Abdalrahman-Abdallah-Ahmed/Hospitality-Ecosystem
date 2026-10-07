# Frontend Changes: Guest Services and Concierge (Phase 7)

For `ecosystem-frontend`. Nothing here is required: no screens are added or
removed, and existing task views keep working. These are optional improvements
that make the new backend behaviour visible to staff.

API reference: [contracts/api-and-messaging.md](contracts/api-and-messaging.md)
and `docs/task-management-api-documentation.md`.

## Task list and board

- **New `guest_signal` labels.** Show `maintenance_request` as "Maintenance
  request" and `room_change_request` as "Room change request". Don't break on
  values you don't know: show the raw value.
- **Filter by kind**: `filter[guest_signal]=maintenance_request`,
  `room_change_request`, `service_request`, `escalation` or
  `cancellation_request`.
- **"Guest not reached" view**: `filter[guest_notice_status]=failed`, and
  optionally `skipped` with reason `no_contact`. These are guests staff may want
  to call.

## Task detail

Show a "Guest notified" line when `guest_notice_status` is set:

| `guest_notice_status` | Show |
| --- | --- |
| `sent` | "Guest notified by WhatsApp" / "by email" (from `guest_notice_channel`) and `guest_notice_at` |
| `skipped` + `cancelled` | "Guest not notified: request cancelled" |
| `skipped` + `no_contact` | "Guest not notified: no WhatsApp window and no email on file" |
| `failed` | "Could not reach the guest" |
| `pending` | "Notifying guest…" |
| `null` | nothing |

## Cancellation-request queue

Let staff know, next to the decline dialog's note field, that the note is sent
to the guest.

## Not changing

- No new permissions, so the role editor needs no change.
- No WhatsApp settings: unknown senders are ignored by the server.
