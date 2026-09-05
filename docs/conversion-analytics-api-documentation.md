# Conversion Analytics API Documentation

The question Phase 1 was built to answer: *of the recommendations sent last
month, how many produced a booking — and what happened to those bookings?*

## Endpoint

`GET /api/analytics/conversion?from=2026-08-01&to=2026-08-31`

Both parameters optional — `from` defaults to the start of the current month,
`to` to now. The period filters on the **recommendation's** `recommended_at`,
so a booking made in September still counts against an August offer.

Headers: `X-API-KEY`, `Authorization: Bearer …`, `Accept: application/json`.
Caller's `role` must be `admin` (super admin bypasses). Every figure is scoped
to the caller's own hotel(s).

## Response

```json
{
  "from": "2026-08-01",
  "to": "2026-08-31",

  "recommendations_made": 412,
  "delivered": 380,
  "not_delivered": 32,
  "accepted": 118,
  "declined": 61,
  "booked": 96,
  "expired": 201,

  "acceptance_rate": 0.3105,
  "booking_rate": 0.2526,
  "realisation_rate": 0.8438,
  "settlement_rate": 0.6129,
  "fulfilment_gap": 22,

  "expected_value": 14820.00,
  "settled_value": 9310.00,
  "currency": "EUR",
  "expected_value_by_currency": null,
  "settled_value_by_currency": null,
  "included_bookings": 34,

  "attribution": {
    "conversational": 71,
    "direct": 12,
    "staff": 3,
    "inferred": 10,
    "direct_share": 0.8958
  },

  "evidence_level": "L2",
  "notes": "10% of attributed bookings are inferred (L2), not observed. 34 bookings are all-inclusive and will never settle; excluded from the settlement rate. 22 accepted guests produced no booking. Attribution window: 72h. Delivery is assumed unless a recommendation was explicitly recorded as not_delivered."
}
```

## The four numbers

Each answers a different question. Reporting one without the others is how a
metric becomes misleading.

| Rate | Question | Source |
| --- | --- | --- |
| `acceptance_rate` | Is the offer any good? | The conversation |
| `booking_rate` | Did acceptance become commitment? — **the agent's score** | Bookings |
| `realisation_rate` | Did the guest actually attend? | Bookings |
| `settlement_rate` | Did money arrive, where money was due? | The ledger |

### `fulfilment_gap` — the diagnostic

`accepted − booked`. Guests who agreed and never ended up with a booking.

If 118 accept and 96 bookings exist, those 22 are not noise. They are a desk
that was closed, a booking that never reached the outlet, or a price quoted in
chat that didn't match the price at the counter. **Nobody at the property can
see that today**, and it is likely costing more than any targeting improvement
would gain.

> `accepted` is **cumulative** — it counts recommendations that reached *at
> least* acceptance, so a booked one is included. That is what makes the two
> rates comparable and their difference meaningful. It also means
> `declined + expired + accepted = delivered`.

### `realisation_rate` and what a `0` means

Attendance is recorded by staff through
`POST /api/booking/{id}/status` (see `docs/booking-entity-documentation.md`), so
this is a real measurement rather than an artefact.

It is `null` only when there is nothing to divide by — no booked
recommendations in the period.

When it comes back **`0` with bookings present**, that is genuinely ambiguous:
either no guest turned up, or the outlets are not marking attendance yet.
`notes` says so explicitly rather than letting the number speak for itself. A
rate that stays at `0` while bookings accumulate is a process signal, not a
guest-behaviour signal.

### `delivered` is an assumption

`delivered = recommendations_made − not_delivered`. Anything not explicitly
recorded as undelivered is assumed to have reached the guest. `notes` says so
every time.

## Money: two fields, never merged

- **`expected_value`** — what was *committed*, from the bookings.
- **`settled_value`** — what actually *arrived*, from the ledger.

They are different facts about different processes and are never combined into
one figure. The response field is never named `revenue_generated`: field names
become claims once they reach a slide.

### `included_bookings`

All-inclusive bookings will **never** settle. They are excluded from
`settlement_rate` entirely and reported separately. Counting them as failures
produces a fictitious loss — a 40% "failure rate" that does not exist.

### Currency is never summed across

Phase 1 does no FX conversion. With exactly one currency you get
`expected_value` / `settled_value` / `currency` inline. With more than one,
those go `null`, the `*_by_currency` maps are populated, and `notes` says so.

## Attribution and evidence

`attribution` shows how each booked outcome was established.
`direct_share` = the three observed methods ÷ all attributed.

If that ever reads `{ inferred: 96 }` with a `direct_share` near zero, the
report is announcing on its own face that nobody is capturing outcomes —
everything is estimated, and because inference is blind to refusals, `declined`
is undercounted by an unknown amount.

`evidence_level` is **the weakest level present, never an average**. One
inferred row makes the whole figure `L2`; averaging would let a pile of guesses
hide behind a handful of observations. `null` when the period has no outcomes.

## A match is not a cause

A booking following a recommendation is a sequence, not proof. The guest may
have booked anyway — a friend mentioned it, they saw the poster, they decided
on the flight over.

The honest headline is *"96 recommended guests booked within 72 hours"*, **not**
*"96 bookings were generated."* Proving causation needs a control group of
comparable guests who received no recommendation over the same period. That
experiment is Phase 3.

## Related Docs

- `docs/recommendation-outcome-api-documentation.md` — how outcomes get recorded.
- `docs/booking-entity-documentation.md` — what a booking is and why it, not a
  payment, is the conversion.
