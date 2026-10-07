# Contract: Staff API Changes, Guest Notices and WhatsApp Inbound

**Feature**: [spec.md](../spec.md) · **Research**: [research.md](../research.md)

This feature adds no new endpoints and no new permissions (R18).

## 1. Task API (`/api/task`), additive

### `TaskResource`: new fields

```json
{
  "guest_signal": "maintenance_request",
  "guest_notice_status": "sent",
  "guest_notice_channel": "email",
  "guest_notice_reason": null,
  "guest_notice_at": "2026-10-07T16:20:11+00:00"
}
```

| Field | Values | Notes |
| --- | --- | --- |
| `guest_signal` | adds `maintenance_request` and `room_change_request` | Existing field. The frontend must not assume the old four values |
| `guest_notice_status` | `null`, `pending`, `sent`, `skipped`, `failed` | Read-only. Ignored on create and update |
| `guest_notice_channel` | `null`, `whatsapp`, `email` | Read-only |
| `guest_notice_reason` | `null`, `cancelled`, `no_contact`, `send_failed` | Read-only |
| `guest_notice_at` | ISO-8601 or `null` | Read-only |

### `GET /api/task`: filters

These use the existing `GenericQuery` syntax, with no new parameters:

- `filter[guest_signal]=maintenance_request` (also `room_change_request`, `escalation`, …)
- `filter[guest_notice_status]=failed` (guests who could not be told)

Permissions are unchanged: `tasks.view`, `tasks.update` and so on, plus the same-hotel
check.

### Behavior changes on update

- Setting `status` to `completed` on a service, maintenance or room-change request
  queues the guest notice. The response does not wait for it, and its status is not
  affected (FR-013).
- Setting `status` to `cancelled` on those kinds records
  `guest_notice_status = skipped` and `guest_notice_reason = cancelled`. Nothing is sent.
- Completing an escalation, a booking follow-up or a staff-created task sends nothing,
  and its notice fields stay `null`.

## 2. Booking cancellation requests (Phase 6 endpoints), behavior only

| Endpoint | New side effect |
| --- | --- |
| `POST /api/booking/{id}/cancellation-request/approve` | The guest is told the booking is cancelled |
| `POST /api/booking/{id}/cancellation-request/decline` | The guest is told the booking stands, with the `note` |
| Any staff cancel of a booking with an open request | Same as approve (Phase 6 closes the request as approved) |

Request and response bodies are unchanged. The task's `guest_notice_*` fields show the
outcome.

## 3. Guest notice messages

The messages are fixed; the AI never writes them. They are in English, or Arabic when
`preferred_language = ar`. Placeholders are in braces. `{kind}` is a fixed, translated label:
`housekeeping`, `service`, `maintenance` or `room change`. Staff names, team names, task
titles, task descriptions and internal notes never appear (FR-008).

| Key | English text |
| --- | --- |
| `completed` | Hello {first_name}, your {kind} request at {hotel} has been taken care of. Reply here if anything else is needed. |
| `cancellation_approved` | Hello {first_name}, your booking for {item} on {date} (ref. {reference}) at {hotel} has been cancelled as you asked. |
| `cancellation_declined` | Hello {first_name}, your booking for {item} on {date} (ref. {reference}) at {hotel} is still in place. {note} |

- **Channel rule**: email is used only when WhatsApp rules forbid the message
  (constitution v2.1.0).
- **WhatsApp**: sent as a free-form text from the shared number, only when the window is
  open (R9).
- **Email**: subject `{hotel}: {short summary}`, the same body text, and the hotel's
  name as the sender name. It goes to the guest's `email` only.

## 4. WhatsApp inbound (`POST /api/whatsapp`)

The response to Meta is unchanged (always `200`, empty body).

Handling per message, in order:

1. **wamid de-duplication** (unchanged)
2. **Per-sender rate limit** → `throttled` (unchanged)
3. **Pairing token** → `pairing` (unchanged)
4. **Sender recognition**:
   - admin or guest → queued turn (unchanged)
   - **unknown** → status **`ignored`**. No job runs and no reply is sent. **This
     replaces** the fixed reply "Sorry, we couldn't recognize this number…"

**Breaking change** (announce in `docs/latest-changes-<date>.md`): unrecognised numbers
no longer get any reply.
