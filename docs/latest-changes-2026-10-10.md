# Latest Changes: 2026-10-10

## Recommendation Approval and Proactive Concierge (Phase 10: SPEC-071, SPEC-073, SPEC-074)

### Summary

- **Approval.** Every AI-generated recommendation now starts **pending approval**. An admin,
  or an employee whose staff role grants the new `recommendations.approve` permission,
  approves or rejects it — one at a time or in bulk. Only approved recommendations can reach
  a guest. Every decision is audited.
- **The Concierge offers only approved recommendations**, through a tool that offers one
  activity chosen in code, and records the pitch only once WhatsApp accepted the reply. It no
  longer invents its own suggestions. Recommendations generated during a guest's
  conversation wait for approval like any other.
- **Decline retry.** After a guest declines an unprompted suggestion, the Concierge may offer
  one *different* activity within 24 hours of the first; then it stops for the rest of the
  stay. A declined activity is never offered again. (Before: one "no" stopped everything.)
- **Proactive messages.** Off by default. When a hotel switches it on, the Concierge can
  message an in-house guest first: the morning after check-in, the middle of a long stay, a
  booking reminder, or a newly approved recommendation. Only inside WhatsApp's 24-hour
  window, outside quiet hours, within a daily cap, once per event, from fixed wording (no AI).
- **Opt-out.** A guest sending `STOP` (or asking in their own words) gets no more proactive
  messages and no unsolicited offers. Their questions are still answered.

### Breaking changes

1. **Recommendation `status` values.** `pending` is gone. New values: `pending_approval`,
   `approved`, `rejected_by_admin`. Existing `pending` recommendations became
   `pending_approval` (they were never reviewed); every other status is unchanged.
   Delivery now moves `approved → sent`. Clients filtering `status=pending` must switch to
   `pending_approval` (awaiting review) or `approved` (may be offered).
2. **Editing a recommendation** (`PUT /api/recommendation/{id}`):
   - changing `activity_id`, `reason` or `reservation_id` on an approved recommendation sends
     it back to `pending_approval`;
   - changing them on an offered or closed one returns **422**.
3. **The Concierge's recommendations tool** returns only recommendations already offered to
   the guest (not awaiting approval, refused, or approved but not yet offered).
4. **Pitch decisions**: the gate `declined_this_stay` is replaced by `retry_used`,
   `retry_window_closed` and `opted_out`; new rows have `rules_version = 2.0`. Old rows are
   unchanged.

### New and changed endpoints

| Endpoint | Notes |
| --- | --- |
| `POST /api/recommendation/{id}/approve` | `recommendations.approve` |
| `POST /api/recommendation/{id}/reject` | `recommendations.approve`; optional `reason` (≤ 500) |
| `POST /api/recommendations/decide` | Bulk, ≤ 100 ids; per-id `decided` / `skipped` |
| `GET /api/recommendation` | New `status` (comma list), `source`, `guest_id`, `arrival_from`, `arrival_to`, `sort=arrival_date`. New fields `source`, `reviewed_by`, `reviewed_at`, `review_reason`, `offerable` |
| `PUT /api/hotel/{id}` | New `proactive_settings` (admin-only, merged) |
| `PUT /api/guest/{id}/contact-preference` | `guests.update`; guest gains `proactive_opted_out*` fields |
| `GET /api/proactive-messages`, `GET /api/proactive-messages/{id}` | Admin-only log |

New permission: `recommendations.approve` (not an employee default). `GET /api/permissions`
lists it automatically.

The Admin AI gains two tools: list recommendations for review, and approve or reject one
named recommendation after the admin confirms. It declines bulk approval.

Usage metering gains `proactive_messages_sent`.

### Rollout

1. Deploy and migrate. Existing recommendations awaiting an offer become
   `pending_approval`: review them in the approval queue before pitching resumes.
2. Pitching still needs `PITCHING_ENABLED=true` (unchanged; off by default).
3. To try proactive messages at a pilot hotel, set `proactive_settings.enabled = true` on
   that hotel. Check `GET /api/proactive-messages` for what was sent and why the rest was not.
4. The scheduler runs two new jobs: proactive triggers every 15 minutes, recommendation
   expiry hourly.

### Frontend (`ecosystem-frontend`)

- **Approval queue:** `GET /api/recommendation?status=pending_approval&sort=arrival_date`,
  with approve/reject per row, multi-select bulk actions (`/api/recommendations/decide`),
  and an optional reason on reject.
- **Recommendation lists:** status filters and labels for the new statuses; `offerable` badge.
- **Hotel settings:** a proactive messaging section (on/off, triggers, quiet hours, daily cap,
  milestone time, reminder timing).
- **Proactive log:** a table over `GET /api/proactive-messages` with trigger, status and
  reason filters.
- **Guest page:** an "opted out of offers" badge and a toggle calling
  `/contact-preference`.

### Docs

- [Recommendations](reservation-recommendations-api-documentation.md#6-approval)
- [Recommendation outcomes](recommendation-outcome-api-documentation.md#5-delivery)
- [Proactive messages](proactive-messages-api-documentation.md)
- [Hotel](hotel-api-documentation.md) · [Guest](guest-api-documentation.md#4a-contact-preference-opt-out)
- [Staff roles](staff-roles-api-documentation.md) · [AI advisor](ai-advisor-chat-api-documentation.md) · [Usage metering](usage-metering-api-documentation.md)
