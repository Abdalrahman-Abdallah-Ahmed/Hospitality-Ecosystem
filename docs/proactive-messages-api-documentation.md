# Proactive Messages API Documentation

*Added 2026-10-10 (SPEC-073).* The WhatsApp Concierge can message an in-house guest first at
a few moments of the stay. This page covers how that works and the log of what it sent, or
decided not to send.

## Base URL and Headers

Same as every tenant endpoint: `X-API-KEY`, `Authorization: Bearer <token>`, `Accept: application/json`.
Responses use `{ message, code, body }`.

## How It Works

Off by default. A hotel admin switches it on in the hotel's `proactive_settings` (see
[Hotel API](hotel-api-documentation.md#the-hotel-object)).

| Trigger | When | What the guest gets |
| --- | --- | --- |
| `first_morning` | The morning after check-in, at `milestone_time` (default 10:00), for 3 hours | An approved recommendation; skipped if none can be offered |
| `mid_stay` | The middle day of a stay of 4+ nights, same timing | An approved recommendation; skipped if none |
| `upcoming_activity` | Confirmed booking: the evening before a morning activity (default 18:00), otherwise 4 hours ahead; never later than 1 hour before it starts | A reminder with the activity, date and time. Not a pitch. |
| `recommendation_approved` | As soon as a recommendation is approved for an in-house guest who has had no unsolicited pitch this reservation | That recommendation |

- One message per guest, reservation and event, however often the checks run. A guest with
  several rooms gets one message.
- Messages go to the reservation's primary guest, on the shared WhatsApp number.
- The text is fixed wording (English or Arabic, from the guest's language) filled from the
  hotel's records. **No AI writes it.** It is added to the guest's conversation, so a reply
  continues with the Concierge.
- A sent offer counts as an unsolicited pitch: it uses the stay's pitch allowance, and a
  "no" to it starts the decline-retry rule (one different activity within 24 hours, then
  closed).

### Rules checked just before sending

In this order. The first one that applies decides.

| Order | Rule | Result (`reason`) |
| --- | --- | --- |
| 1 | Proactive messaging or this trigger is off | skipped (`disabled`, `trigger_disabled`) |
| 2 | Past its last useful moment | skipped (`expired`) |
| 3 | The guest opted out | skipped (`opted_out`) |
| 4 | No WhatsApp number | skipped (`no_whatsapp`) |
| 5 | The guest's last message was more than 24 hours ago | skipped (`outside_window`) — never sent by template or email, never revived |
| 6 | Reminder: booking no longer confirmed / guest not at the hotel | skipped (`booking_not_confirmed`, `not_in_house`) |
| 7 | Offer: every pitching rule (pitching on, in house, not departing today, no escalation, no open complaint, pitch allowance, decline-retry rule, not already pitched), then something approved to offer | skipped (`pitching_disabled`, `not_in_house`, `departing`, `escalated`, `open_complaint`, `pitch_cap`, `retry_used`, `retry_window_closed`, `already_pitched`, `no_candidate`, `recommendation_not_offerable`) |
| 8 | Quiet hours | waits until they end (`quiet_hours`) |
| 9 | The guest wrote in the last 30 minutes | waits 30 minutes (`guest_active`) |
| 10 | The day's cap is used, or a booking reminder is due today (it goes first) | waits until tomorrow (`daily_cap`) |

A message that would only be allowed after its last useful moment is skipped with the reason
that held it back. A failed send is retried once after 5 minutes, then recorded as `failed`
(`send_failed`).

### Opting out

A guest who sends `STOP` (also `UNSUBSCRIBE`, `توقف`, `إيقاف` …) as the whole message, or asks
the Concierge in their own words, gets no more proactive messages **and no unsolicited
offers in chat**. Their questions are still answered, and notices about their own requests
still reach them. `START` opts back in. Staff can record it with
[`PUT /api/guest/{id}/contact-preference`](guest-api-documentation.md#4a-contact-preference-opt-out).

## Who Can Call These Endpoints

Hotel admins and super admins only, like the hotel settings that switch this on. No staff
role grants it. Read-only: rows are written by the system.

## 1. List Proactive Messages

`GET /api/proactive-messages`

| Param | Example | Behavior |
| --- | --- | --- |
| `filter[trigger]` | `filter[trigger]=upcoming_activity` | `first_morning`, `mid_stay`, `upcoming_activity`, `recommendation_approved` |
| `filter[status]` | `filter[status]=skipped` | `scheduled`, `sending`, `sent`, `skipped`, `failed` |
| `filter[reason]` | `filter[reason]=outside_window` | See the table above |
| `filter[guest_id]`, `filter[reservation_id]` | | Exact match |
| `sent_from`, `sent_to` | `sent_from=2026-10-10` | Hotel-local dates on `sent_at` |
| `sort` | `sort=-sent_at` | Any column; default `-due_at` |
| `page`, `per_page` | | Pagination (default 15, max 100) |

`200`; `body.data` holds proactive message objects.

## 2. Get One

`GET /api/proactive-messages/{id}` — `200`, or `403` for another hotel's message.

## The Proactive Message Object

```json
{
  "id": "uuid",
  "trigger": "upcoming_activity",
  "status": "sent",
  "reason": null,
  "guest": { "id": "uuid", "name": "Sara Khan" },
  "reservation_id": "uuid",
  "stay_id": "uuid",
  "booking_id": "uuid",
  "recommendation_id": null,
  "due_at": "2026-10-10T15:00:00.000000Z",
  "valid_until": "2026-10-11T04:00:00.000000Z",
  "sent_at": "2026-10-10T15:00:12.000000Z",
  "locale": "en",
  "body": "Hello Sara, a reminder from Grand Harbor Hotel: your Desert safari is on Sunday 11 October at 08:00. Reply here if you have any questions.",
  "attempts": 1,
  "created_at": "...",
  "updated_at": "..."
}
```

- `reason` is why it was skipped, or why a `scheduled` message is waiting.
- `recommendation_id` is the recommendation an offer named (for milestones, chosen when sent).
- `body` and `locale` are set only once sent.

## Usage

Each sent message counts once as `proactive_messages_sent` in
[usage metering](usage-metering-api-documentation.md). Sending makes no AI call.

## Related Docs

- [Recommendations](reservation-recommendations-api-documentation.md#6-approval) — only approved recommendations are offered.
- [Hotel](hotel-api-documentation.md) — `proactive_settings`.
- [Guest](guest-api-documentation.md#4a-contact-preference-opt-out) — opt-out.
