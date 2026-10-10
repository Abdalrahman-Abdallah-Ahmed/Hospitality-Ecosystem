# Data Model: Recommendation Approval and Proactive Concierge

Changes for [spec.md](spec.md), decided in [research.md](research.md). Three migrations
alter existing tables, one creates a table. Filenames follow the repo sequence
(`2026_10_10_00000N_…`).

## 1. `recommendations` (altered)

| Column | Type | Notes |
| --- | --- | --- |
| `status` | string (existing) | `App\Enums\RecommendationStatus`, see lifecycle below |
| `source` | string, nullable → backfilled | `App\Enums\RecommendationSource`: `staff_request`, `conversation`, `legacy` (R5) |
| `reviewed_by_user_id` | uuid FK → users, nullable, `nullOnDelete` | Who approved or rejected |
| `reviewed_at` | timestamp, nullable | When |
| `review_reason` | string(500), nullable | Rejection reason (optional) |

- Index: `(hotel_id, status, recommended_at)` for the queue.
- `reviewed_*` are **not fillable**; only `RecommendationApprovalService` writes them
  (same rule as `delivered_at`). They are added to `eventLoggedAttributes()`.
- Data migration (FR-011): `status = 'pending'` → `'pending_approval'`; `source = 'legacy'`
  for every existing row. Down: `pending_approval`/`approved` → `pending`,
  `rejected_by_admin` → `cancelled`.

### Lifecycle

```text
                    ┌──────────── reject ─────────────┐
                    │                                 ▼
 (created) → pending_approval ── approve ──→ approved ──reject──→ rejected_by_admin  (final)
                    ▲                          │
                    └── content edit (R4) ─────┤
                                               │ pitched + WhatsApp accepted / staff outcome
                                               ▼
                                             sent → accepted | rejected | ignored | purchased
 pending_approval | approved (never offered) ── stay over / reservation cancelled ──→ expired
```

| From | To | By | Guard |
| --- | --- | --- | --- |
| — | `pending_approval` | `CreateRecommendationTool` | always (FR-001) |
| `pending_approval` | `approved` | approval service | permission; not delivered/pitched |
| `pending_approval`, `approved` | `rejected_by_admin` | approval service | not delivered/pitched |
| `approved` | `pending_approval` | approval service (`resetForEdit`) | activity/reason/reservation changed |
| `approved` | `sent` | `RecommendationDeliveryService` | was `pending` → `sent` before |
| `pending_approval`, `approved` | `expired` | `ExpireRecommendationsJob` | never delivered/pitched; stay over |
| `sent` or `approved` | `accepted` / `rejected` / `ignored` | Concierge `UpdateRecommendationTool`, staff outcome | unchanged; Concierge only sees offered ones (R7) |

**Offerable** (pitch candidate) = `status = approved` ∧ `delivered_at IS NULL` ∧
`pitch_decision_id IS NULL` ∧ activity active ∧ activity not declined in this reservation.

## 2. `pitch_decisions` (altered)

| Column | Type | Notes |
| --- | --- | --- |
| `is_retry` | boolean, default false | The staged pitch is the one retry after a decline (R8) |
| `proactive_message_id` | uuid FK → proactive_messages, nullable, `nullOnDelete` | Set for proactive pitches |

- `opening` gains `proactive` (`App\Enums\PitchOpening::PROACTIVE`).
- `gates` JSON may now hold `retry_used`, `retry_window_closed`, `opted_out`; old rows keep
  `declined_this_stay`. `rules_version` = `2.0` for new rows.
- `candidates.excluded[].reason` may now be `declined_activity`.

### Derived: pitch flow (not stored)

For guest G and reservation R (all stays of R):
- *unsolicited pitches* = decisions with `result IN (null, pitched)` ∧ `explicit_request = false`
  ∧ a recommendation staged;
- *declined flow* = earliest unsolicited pitch whose recommendation is `rejected`; `t0` =
  the first unsolicited pitch at or before it within the same 24 h;
- closed ⇔ declined flow ∧ (∃ `is_retry` decision after it ∨ now ≥ `t0` + 24 h);
- cap count = unsolicited pitches − retries.

## 3. `proactive_messages` (new, hotel-scoped)

| Column | Type | Notes |
| --- | --- | --- |
| `id` | uuid PK | |
| `hotel_id` | uuid FK → hotels, cascade | `BelongsToHotel` |
| `guest_id` | uuid FK → guests, cascade | |
| `reservation_id` | uuid FK → reservations, nullOnDelete | |
| `stay_id` | uuid FK → stays, nullable, nullOnDelete | Stay used for eligibility |
| `trigger` | string | `App\Enums\ProactiveTrigger`: `first_morning`, `mid_stay`, `upcoming_activity`, `recommendation_approved` |
| `event_key` | string(100) | `first_morning:{reservation}`, `booking:{booking}`, `recommendation:{rec}` … |
| `booking_id` | uuid FK → bookings, nullable, nullOnDelete | Reminder target |
| `recommendation_id` | uuid FK → recommendations, nullable, nullOnDelete | Pitch target |
| `status` | string | `App\Enums\ProactiveMessageStatus`: `scheduled`, `sending`, `sent`, `skipped`, `failed` |
| `reason` | string, nullable | `App\Enums\ProactiveSkipReason` (below), or last deferral reason |
| `due_at` | timestamp | Next time to try; moved on deferral |
| `valid_until` | timestamp | After this the message is skipped as `expired` |
| `attempts` | smallint, default 0 | Send attempts (FR: at most one retry after a failure) |
| `locale` | string(5), nullable | `en` / `ar`, set when rendered |
| `body` | text, nullable | The text actually sent |
| `conversation_id` | string(36), nullable | `agent_conversations.id`, no FK (package table) |
| `sent_at` | timestamp, nullable | WhatsApp accepted the send |
| timestamps | | No soft deletes: a record of decisions |

- **Unique**: `(hotel_id, guest_id, trigger, event_key)` — FR-029.
- Indexes: `(status, due_at)` for the sender sweep; `(hotel_id, guest_id, sent_at)` for the
  daily cap.
- `Filterable`, listed by the admin log endpoint.

**Skip / defer reasons** (`ProactiveSkipReason`): terminal — `disabled`, `trigger_disabled`,
`opted_out`, `already_pitched`, `outside_window`, `no_whatsapp`, `not_in_house`, `departing`, `escalated`,
`open_complaint`, `pitching_disabled`, `pitch_cap`, `retry_used`, `retry_window_closed`,
`no_candidate`, `booking_not_confirmed`, `recommendation_not_offerable`, `missing_data`,
`expired`, `send_failed`; deferring — `quiet_hours`, `daily_cap`, `guest_active`.

### States

```text
scheduled ──claim──→ sending ──WhatsApp ok──→ sent
    ▲                  │ ├─ guardrail defers ──→ scheduled (due_at moved, reason set)
    │                  │ ├─ guardrail blocks / past valid_until ──→ skipped (reason)
    └── stale claim ───┘ └─ send throws: attempts < 2 → scheduled; else failed (send_failed)
```

## 4. `guests` (altered)

| Column | Type | Notes |
| --- | --- | --- |
| `proactive_opted_out_at` | timestamp, nullable | Set = opted out (R14) |
| `proactive_opt_out_source` | string, nullable | `guest_message` \| `staff` |

Not fillable; written by `GuestContactPreferenceService`, which records `opted_out` /
`opted_in` events.

## 5. `hotels` (altered)

| Column | Type | Notes |
| --- | --- | --- |
| `proactive_settings` | json, nullable | Cast to `ProactiveSettings`; null = all defaults |

`ProactiveSettings` shape and defaults:

```json
{
  "enabled": false,
  "triggers": { "first_morning": true, "mid_stay": true, "upcoming_activity": true, "recommendation_approved": true },
  "quiet_hours": { "start": "21:00", "end": "09:00" },
  "daily_cap": 1,
  "milestone_time": "10:00",
  "reminder": { "morning_cutoff": "12:00", "evening_before_at": "18:00", "hours_before": 4 }
}
```

Validation: times `H:i`; `daily_cap` 1–3; `hours_before` 1–24; unknown keys rejected.

## Enums

| Enum | Change |
| --- | --- |
| `RecommendationStatus` | + `PENDING_APPROVAL`, `APPROVED`, `REJECTED_BY_ADMIN`; − `PENDING` |
| `RecommendationSource` | new |
| `PitchGate` | − `DECLINED_THIS_STAY`; + `RETRY_USED`, `RETRY_WINDOW_CLOSED`, `OPTED_OUT` |
| `PitchOpening` | + `PROACTIVE` (never produced by the classifier) |
| `ProactiveTrigger`, `ProactiveMessageStatus`, `ProactiveSkipReason` | new |
| `MeterFeature` | + `PROACTIVE_MESSAGES_SENT` |
| `Permission` | + `RECOMMENDATIONS_APPROVE` |

## Audit events

| Subject | Event | Payload |
| --- | --- | --- |
| Recommendation | `approved`, `rejected_by_admin` | status from/to, reason; AI context when via Admin AI |
| Recommendation | `approval_reset` | changed fields, status `approved → pending_approval` |
| Recommendation | `expired` | status from/to |
| Guest | `opted_out`, `opted_in` | source |
| ProactiveMessage | none per row | the row itself is the record (FR-033); sending a pitch records the existing `delivered` event |
