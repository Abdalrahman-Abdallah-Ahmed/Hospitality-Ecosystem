# Frontend Changes: 2026-10-06 — Guest Services and Concierge (Phase 7)

For `ecosystem-frontend`. The backend adds **no new endpoints and no new permissions**.
Everything below is additive, except one new error on task update (§4).

Backend branch: `007-guest-services-concierge`.

Related backend docs:

- `docs/task-management-api-documentation.md`: task object, `guest_notice_*` fields,
  filters, update behaviour
- `docs/booking-entity-documentation.md`: cancellation approve and decline notify the
  guest
- `docs/latest-changes-2026-10-06.md`: release notes
- `specs/007-guest-services-concierge/contracts/api-and-messaging.md`: full contract

---

## 1. Task object: four new read-only fields

Returned by `GET /api/task`, `GET /api/task/{id}`, and the `POST` and `PUT` responses.
**Never send them**; the server ignores them on create and update.

| Field | Values | Meaning |
| --- | --- | --- |
| `guest_notice_status` | `null`, `pending`, `sent`, `skipped`, `failed` | Whether the guest was told how their request ended. `null` until the request closes. |
| `guest_notice_channel` | `null`, `whatsapp`, `email` | Set when `sent` |
| `guest_notice_reason` | `null`, `cancelled`, `no_contact`, `send_failed` | Set when not sent |
| `guest_notice_at` | ISO datetime or `null` | When the outcome was recorded |

### Suggested "Guest notified" line on the task detail

| State | Show |
| --- | --- |
| `sent` + `whatsapp` | "Guest notified by WhatsApp" and `guest_notice_at` |
| `sent` + `email` | "Guest notified by email" and `guest_notice_at` |
| `skipped` + `cancelled` | "Guest not notified: request cancelled" |
| `skipped` + `no_contact` | "Guest not notified: no WhatsApp window and no email on file" |
| `skipped` + no reason | Nothing. These are requests closed before this release. |
| `failed` | "Could not reach the guest". Staff may want to call them. |
| `pending` | "Notifying guest…" |
| `null` | Nothing |

Only these tasks ever get a notice:

- service, maintenance and room-change requests, when completed;
- booking cancellation requests, when approved or declined.

Escalations, booking follow-ups and staff-created tasks always keep `null`.

## 2. `guest_signal`: two new values

`guest_signal` is a read-only task field that says why a guest task exists. The full set
is now:

| Value | Label | New |
| --- | --- | --- |
| `service_request` | Service request | |
| `maintenance_request` | Maintenance request | ✅ |
| `room_change_request` | Room change request | ✅ |
| `cancellation_request` | Cancellation request | |
| `escalation` | Guest asked for a person | |
| `booking_follow_up` | Booking follow-up | |
| `null` | (not a guest task) | |

**Do not break on unknown values.** Fall back to showing the raw string.

- **`maintenance_request`** always goes to the hotel's Maintenance team and category.
- **`room_change_request`** has no team and no category (`assigned_to_team_id` and
  `task_category_id` are `null`), so everyone with `tasks.view` sees it.

## 3. Task list filters

These use the existing generic syntax, with no new parameters:

- `GET /api/task?filter[guest_signal]=maintenance_request`, or `room_change_request`,
  `escalation`, `service_request`, `cancellation_request`
- `GET /api/task?filter[guest_notice_status]=failed`: guests the hotel could not reach.
  A good candidate for a small "Not reached" view or badge.

## 4. New error: reopening a guest request (`PUT /api/task/{id}`)

Setting `status` back to `pending` or `in_progress` on an **escalation** or a
**room-change request** now returns **`422`** (it used to be a `500`) when another one
is already open:

- the guest already has another open escalation, or
- the stay already has another open room-change request.

```json
{
  "message": "This guest already has another open escalation. Close it, or add to it, before reopening this one.",
  "code": 422,
  "body": null
}
```

The room-change message is: "This stay already has another open room-change request.
Close it before reopening this one."

**UI:** show `message` as-is instead of a generic error toast.

## 5. Room-change requests

No new screen. A room-change request is a task: the guest's reason and any preference
are in `description`, and it's linked to `stay_id` and `room_id`, or only to
`reservation_id` before arrival. Staff decide and move the guest through the **existing
room assignment UI**; the request itself changes nothing.

Optional: on a `room_change_request` task, add a link to its reservation or stay so staff
can reassign the room from there.

## 6. Booking cancellation queue

- `POST /api/booking/{id}/cancellation-request/decline`: the **`note` is now sent to the
  guest** on WhatsApp, or by email outside the 24-hour WhatsApp window. Add helper text
  under the note field: *"This note is sent to the guest."*
- `POST /api/booking/{id}/cancellation-request/approve`: the guest is told the booking
  is cancelled.
- Request and response shapes are unchanged. The request task's `guest_notice_*` fields
  show whether the guest was reached.

## 7. No UI change needed

- **Unknown WhatsApp numbers** are now ignored by the server (no reply). There are no
  settings to expose.
- **The AI Concierge's tools** (bookings, open requests, maintenance, room change) are
  server-side only.
- **The role editor**: no new permissions, and `GET /api/permissions` is unchanged.

## Checklist

- [ ] Task detail: "Guest notified" line (§1)
- [ ] Labels for `maintenance_request` and `room_change_request`, with a fallback for
  unknown values (§2)
- [ ] Optional: task list filters by kind and "Not reached" (§3)
- [ ] Show the 422 `message` when reopening a task fails (§4)
- [ ] Optional: link from a room-change task to its reservation or stay (§5)
- [ ] Decline dialog helper text: "This note is sent to the guest." (§6)
