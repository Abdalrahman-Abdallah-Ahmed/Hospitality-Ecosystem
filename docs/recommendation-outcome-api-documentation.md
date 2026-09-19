# Recommendation Outcome API Documentation

How the system records **what happened** to a recommendation — and **how it
knows** (Phase 1, WP-5).

## What counts as success

**A recommendation succeeds when it produces a booking.** Not when money
arrives.

Money may arrive later, in parts, or never — and "never" is often the correct
outcome, because an all-inclusive guest booking an included activity pays
nothing and the recommendation worked perfectly. A payment-based definition
reports that ideal case as a failure. See
`docs/booking-entity-documentation.md`.

## How we find out

Four different things can tell us, and they are not equally trustworthy.

| Method | What it is | Evidence |
| --- | --- | --- |
| `conversational` | The guest's own words in the exchange | **L1** |
| `direct` | A booking carries the `recommendation_id` | **L1** |
| `staff` | A person recorded it | **L1** |
| `inferred` | The nightly matching job decided it | **L2** |
| `none` | No link established (e.g. expired by elimination) | **L4** |

`attribution_method` is **required on every outcome, with no default** — an
unlabelled attribution is worthless, because nobody downstream can tell an
observation from a guess. `evidence_level` is always **derived** from it; the
API rejects any attempt to send both.

### Precedence

`direct` > `conversational` > `staff` > `inferred`.

A stronger method always overwrites a weaker one; a weaker one never overwrites
a stronger. This is what stops the nightly job replacing *"the guest told us
no"* with *"they booked it anyway, probably"*.

### Why capture exists at all

A guest who refuses produces **no booking and no payment** — no downstream
record of any kind. Inference can only ever see bookings, so it is structurally
blind to refusals and to offers that never reached the guest. Those are the two
most actionable signals the system can collect.

| Outcome | conversational / staff | inferred |
| --- | --- | --- |
| `not_delivered` | ✅ | ❌ |
| `delivered` | ✅ | ❌ |
| `declined` | ✅ | ❌ |
| `accepted` | ✅ | ❌ |
| `booked` | ✅ | ✅ |
| `expired` | ✅ | ✅ (by elimination only) |

## The outcome values

| Value | Meaning |
| --- | --- |
| `not_delivered` | Generated, never reached the guest — a **process** failure |
| `delivered` | Offer made, no decision yet |
| `declined` | Guest refused — a **commercial** signal |
| `accepted` | Guest agreed, no booking yet |
| `booked` | Commitment exists — **the conversion** |
| `expired` | Guest departed undecided |

Two distinctions carry the weight of the whole package:

- **`accepted` vs `booked`** — saying yes and a commitment existing are
  different events. The distance between them is the *fulfilment gap*: a desk
  that was closed, or a price in the chat that didn't match the counter.
- **`not_delivered` vs `declined`** — process failure vs commercial signal.
  Different teams, different fixes.

## 1. Record an Outcome (staff capture)

`POST /api/recommendation/{id}/outcome`

> The spec writes this plural (`/api/recommendations/...`). This API uses
> singular resource paths everywhere else, so the implemented path is singular.

### Headers

```http
X-API-KEY: {your_api_key}
Authorization: Bearer {login_token}
Accept: application/json
Content-Type: application/json
```

### Who can call it

**`admin` or `employee`**, and the recommendation must belong to the caller's
own hotel. Deliberately wider than every other write endpoint: the employees at
the desk are the ones who hear "no", and a refusal-capture instrument only
admins can use will not capture refusals.

Since 2026-09-15 this is the `recommendations.record_outcome` permission, held
by default by employees without a
[staff role](/D:/Hospitality%20Ecosystem/docs/staff-roles-api-documentation.md).
A role that leaves it out removes it.

### Request

```json
{
  "outcome": "declined",
  "channel": "face_to_face",
  "decline_reason": "price",
  "evidence_quote": "maybe another time, it's a bit expensive for us",
  "occurred_at": "2026-08-14T10:12:00Z"
}
```

| Field | Rules |
| --- | --- |
| `outcome` | **required** — `not_delivered`, `delivered`, `declined`, `accepted`, `booked`. `expired` is not accepted: it means "departed undecided", which the nightly job concludes by elimination rather than something a person observes. |
| `channel` | optional — `whatsapp`, `face_to_face`, `phone`, `email`. |
| `decline_reason` | **required when `declined`.** A refusal with no reason tells you nothing you can act on. |
| `booking_id` | **required when `booked`** — the conversion is the commitment, so claiming one requires it. Must belong to the same hotel. |
| `evidence_quote` | optional — the guest's own words. |
| `occurred_at` | optional date. |

**Never send `attribution_method` or `evidence_level`.** This endpoint always
writes `staff`, and the evidence level is derived (`L1`). Supplying an evidence
level is rejected — two fields that can disagree eventually will.

### Response

`201 Created` with the outcome row:

```json
{
  "id": "01a06400-...",
  "recommendation_id": "01a01134-...",
  "booking_id": null,
  "outcome": "declined",
  "attribution_method": "staff",
  "evidence_level": "L1",
  "channel": "face_to_face",
  "recorded_by_user_id": "01a00abc-...",
  "expected_value": "60.00",
  "currency": "USD",
  "minutes_to_outcome": 540,
  "decline_reason": "price",
  "evidence_quote": "maybe another time, it's a bit expensive for us",
  "confidence": null,
  "context": null,
  "occurred_at": "2026-08-14T10:12:00.000000Z"
}
```

- `expected_value` is what was **committed**, never what was settled. Money
  lives on the transactions linked to the booking.
- `minutes_to_outcome` is measured from when the guest was offered it
  (the recommendation's `delivered_at`), or from `recommended_at` when no
  delivery was recorded before this outcome. A recommendation made days before
  the guest saw it would otherwise look like days of hesitation. `null` when it
  cannot be computed — never zero, which would read as "acted instantly".
- `context.superseded` keeps the evidence of every earlier outcome this row
  replaced, oldest first. See [Superseded evidence](#superseded-evidence).
- One live row per recommendation, upserted as the state advances. The change
  history is in the audit trail: `GET /api/history/recommendation/{id}`.
- Recording any outcome except `not_delivered` also marks the recommendation
  as delivered (see [Delivery](#5-delivery)), with `occurred_at` as the time
  and `channel` as the channel (`face_to_face` when omitted).

### Superseded evidence

When a stronger or equal outcome replaces an earlier one, the earlier row's
evidence is kept in `context.superseded` instead of being overwritten:

```json
"context": {
  "superseded": [
    {
      "outcome": "accepted",
      "attribution_method": "conversational",
      "evidence_quote": "Yes, book the boat for us both",
      "confidence": "0.90",
      "decline_reason": null,
      "occurred_at": "2026-09-18T17:02:11+00:00"
    }
  ]
}
```

The new row carries only its own evidence. A guest who said *"no, too
expensive"* and later booked anyway keeps that quote next to the `declined`
classification it supported, not on the `booked` row. Recording the same
outcome again with the same method, quote and reason appends nothing. Other
keys in `context` (for example the matcher's window) sit next to
`superseded`.

### Errors

| Status | When |
| --- | --- |
| `422` (Laravel shape) | Missing `outcome`; `declined` with no `decline_reason`; `booked` with no `booking_id`; unknown `channel`. |
| `422` (wrapper) | Booking belongs to another hotel; an integrity rule was broken. |
| `403` | Not an admin/employee, or another hotel's recommendation. |
| `404` | Unknown recommendation. |

## 2. Conversational capture (the primary path)

The concierge agent classifies the guest's response at the end of an exchange
and writes the outcome itself, through `App\Ai\Tools\UpdateRecommendationTool`.
It records:

- the outcome (`accepted` / `declined` / `delivered`),
- `decline_reason` when refused,
- `evidence_quote` — **the guest's own words**, which is what settles a later
  dispute about whether the classification was right,
- `confidence` — how sure the agent is of its reading.

**Below the configured confidence threshold (default `0.7`), the outcome is
recorded as `delivered`, not as a decision.** An ambiguous "we'll see" is not
an acceptance. Politeness reads as agreement far more readily in several guest
languages than in English, and rounding ambiguity up inflates everything
downstream. Configure with `RECOMMENDATION_CONFIDENCE_THRESHOLD`.

> The agent is scoring its own work. Sample-audit a share of classifications
> against the stored quotes each month and log disagreements as corrections —
> self-reported success that nobody audits drifts upward.

## 3. Booking as the conversion

When a booking is created carrying a `recommendation_id`, the outcome becomes
`booked` / `direct` (L1) immediately. The strongest link in the system, needing
no inference at all. See `App\Services\BookingService::create()`.

## 4. Inference (the fallback)

`App\Jobs\MatchRecommendationOutcomesJob`, nightly at 03:30. Runs only where
nothing observed a link. A booking matches a recommendation when **all** of:

1. Same guest **or** same stay
2. Same activity
3. Booking created after the recommendation
4. Inside the attribution window (default **72 hours**,
   `RECOMMENDATION_ATTRIBUTION_WINDOW_HOURS`)
5. Neither side already matched

The nearest preceding recommendation wins. When the guest leaves with nothing
recorded, the job writes `expired`, or `not_delivered` for a recommendation
that never reached them (see [Delivery](#5-delivery)). Every inferred row
records the rule that produced it:

```json
{ "attribution_window_hours": 72, "matcher_version": "1.0" }
```

Someone will compare 24h against 72h; without this you get a table where half
the rows follow one rule and half another, with no way to separate them.

**A match is not a cause.** A booking following a recommendation is a sequence,
not proof — the guest may have booked anyway. Proving causation needs a control
group of comparable guests who received no recommendation. That is Phase 3.

## 5. Delivery

*Added 2026-09-19.* A recommendation can exist without ever reaching the guest.
Each recommendation now records when, and how, it was actually offered:

| Field | Meaning |
| --- | --- |
| `delivered_at` | When the guest was offered it. `null` = never delivered. |
| `delivery_channel` | `whatsapp`, `face_to_face`, `phone` or `email`. |

Both are read-only. `PUT /api/recommendation/{id}` ignores them. Delivery is
stamped **once**. A later stamp changes nothing, so the first time the guest
was offered it stands.

**Reading a recommendation is not delivering it.** Delivery is stamped only
when:

| What happened | Channel |
| --- | --- |
| The guest reacted to it in the WhatsApp chat (the concierge recorded their reaction) | `whatsapp` |
| Staff recorded `delivered`, `declined`, `accepted` or `booked` through [endpoint 1](#1-record-an-outcome-staff-capture) | the request's `channel`, default `face_to_face` |
| A booking was created carrying the `recommendation_id` | the booking's `channel` if it is one of the four, otherwise `face_to_face` |

The nightly matcher never stamps delivery. An inferred booking says nothing
about whether our offer reached the guest.

Stamping delivery also moves `status` from `pending` to `sent`, and counts one
`recommendations_delivered` in usage metering.

`delivered_at` means that WhatsApp **accepted** the reply. It does not mean the
message reached the phone. Meta's delivery and read receipts are not tracked.

### `not_delivered` written automatically

When a guest leaves with no outcome recorded, the nightly job decides by
delivery:

| `delivered_at` | Outcome written |
| --- | --- |
| set | `expired`: offered, and the guest left undecided |
| `null` | `not_delivered`: generated, and never reached the guest |

Both use attribution `none` (L4): they are reached by elimination.

**Staff must record face-to-face offers.** A recommendation that staff offered
in person without recording it here becomes `not_delivered` when the guest
leaves.

## Related Docs

- `docs/conversion-analytics-api-documentation.md` — the report these feed.
- `docs/booking-entity-documentation.md` — what a booking is and why.
