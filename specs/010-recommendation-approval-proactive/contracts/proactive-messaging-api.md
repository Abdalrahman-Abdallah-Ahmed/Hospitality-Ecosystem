# Contract: Proactive Messaging API

Same middleware stack and `{ message, code, body }` envelope as every tenant route.

## Hotel proactive settings — `PUT /api/hotel/{hotel}` (extended)

Admin-only, like every hotel setting (no permission case). New optional field:

```json
{
  "proactive_settings": {
    "enabled": true,
    "triggers": { "first_morning": true, "mid_stay": false, "upcoming_activity": true, "recommendation_approved": true },
    "quiet_hours": { "start": "21:00", "end": "09:00" },
    "daily_cap": 1,
    "milestone_time": "10:00",
    "reminder": { "morning_cutoff": "12:00", "evening_before_at": "18:00", "hours_before": 4 }
  }
}
```

- Partial objects merge over the stored settings; omitted keys keep their value.
- Validation: times `H:i`; `daily_cap` integer 1–3; `hours_before` 1–24; unknown keys → 422.
- `HotelResource` returns `proactive_settings` with defaults filled in.

## Proactive message log — `GET /api/proactive-messages`

Hotel admin and super admin only. `GenericIndexRequest`: pagination, sort (`-due_at`
default), filters `filter[trigger]`, `filter[status]`, `filter[reason]`,
`filter[guest_id]`, `filter[reservation_id]` (the repo's generic filter syntax), plus
top-level `sent_from` / `sent_to` (hotel-local dates).

Item (`ProactiveMessageResource`):

```json
{
  "id": "uuid",
  "trigger": "upcoming_activity",
  "status": "skipped",
  "reason": "outside_window",
  "guest": { "id": "uuid", "name": "Sara Khan" },
  "reservation_id": "uuid",
  "booking_id": "uuid",
  "recommendation_id": null,
  "due_at": "2026-10-11T18:00:00+03:00",
  "valid_until": "2026-10-12T07:00:00+03:00",
  "sent_at": null,
  "locale": null,
  "body": null,
  "attempts": 0,
  "created_at": "…"
}
```

`GET /api/proactive-messages/{id}` returns one item (`403` for another hotel's). No create, update or delete routes.

## Guest contact preference — `PUT /api/guest/{guest}/contact-preference`

Permission: `guests.update` (+ same hotel). Records a change the guest asked for.

```json
{ "proactive_opted_out": false }
```

| Case | Code |
| --- | --- |
| Changed | 200 `Contact preference updated successfully.` |
| Already in that state | 200, no event written |
| No permission / other hotel | 403 / 404 |

`GuestResource` gains:

```json
{ "proactive_opted_out": true, "proactive_opted_out_at": "…", "proactive_opt_out_source": "guest_message" }
```

## Guest-facing behaviour (WhatsApp, no HTTP surface)

| Guest sends | Result |
| --- | --- |
| Whole message is an opt-out keyword (`STOP`, `UNSUBSCRIBE`, `توقف`, `إيقاف`, …) | Opted out; fixed confirmation in the guest's language; no AI call |
| Whole message is a resume keyword (`START`, `ابدأ`, …) | Opted back in; fixed confirmation |
| "Please stop sending me offers" (free text) | Concierge calls `SetContactPreferenceTool(opt_out)` and confirms once |

Keyword lists live in `config/proactive.php`.
