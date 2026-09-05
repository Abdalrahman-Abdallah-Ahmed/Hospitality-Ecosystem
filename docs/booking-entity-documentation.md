# Bookings — Entity and API Documentation

Phase 1, WP-3a.

## What a booking is

A **commitment**: a slot is held and the guest is expected. It is deliberately
separate from the two events either side of it.

| Event | Meaning | Where it lives |
| --- | --- | --- |
| Acceptance | The guest said yes | The conversation (WP-5) |
| **Booking** | **A commitment exists** | `bookings` — this entity |
| Settlement | Money moved, whenever it moved | `transactions` (WP-3) |

**The booking is the conversion.** It is the moment a recommendation has done
its job. Whether money follows, when, and whether at all, is a separate fact
about a separate process.

### Why this is separate from payment

Consider what actually happens in a resort:

- A guest accepts a dinner recommendation. They eat Thursday. They pay at
  checkout on Sunday — or, on all-inclusive, never.
- A guest books a beach activity included in their package. **No money will
  ever move.** The recommendation worked perfectly.
- A guest books an excursion at the desk. Payment settles days later.

A payment-based definition of success reports every one of those as a failure.
The all-inclusive case is the clearest: revenue zero, outcome ideal.

## The three enums

### `BookingStatus`

| Value | Meaning |
| --- | --- |
| `pending` | Guest accepted, slot not yet confirmed |
| `confirmed` | Slot held, guest expected |
| `realised` | Guest attended / consumed |
| `no_show` | Confirmed, guest never came |
| `cancelled` | Cancelled before the date |

`no_show` and `cancelled` are **not** interchangeable: one is a guest who broke
a commitment, the other one who withdrew it in time. Different operational
responses, different signals.

Bookings are **never deleted** — they are cancelled, with a reason. The history
is the point, same rule as the ledger.

### `ChargeModel`

| Value | Meaning |
| --- | --- |
| `included` | All-inclusive: **no money will ever move** |
| `pay_on_site` | Settles at the outlet |
| `folio` | Posts to the room, settles at checkout |
| `prepaid` | Already paid before the stay |

**`included` is the case this whole entity exists for.** Such a booking will
never produce a transaction, and must never be reported as unrealised,
unsettled, or failed. `ChargeModel::settles()` returns `false` for it — call
that rather than re-deriving the rule, or you will eventually publish a
fictitious 40% failure rate.

### `BookingOrigin`

| Value | Meaning |
| --- | --- |
| `recommendation` | Followed an agent recommendation |
| `guest_request` | Guest asked unprompted |
| `staff` | Staff booked it directly |
| `import` | Loaded from another system |

`origin` is what lets you compare recommended bookings against organic ones.
Without it you cannot tell whether the AI creates demand or merely records
demand that already existed — the first question any manager asks.

## The booking reference

Every booking carries an 8-character code, e.g. `DCB-4K2P`.

Outlets (restaurant, dive centre, spa) run their own tills, which have never
heard of our UUIDs. The guest quotes this code at the desk, the seller records
it on the sale, and it comes back in the day's sales file as the
`booking_reference` column — which is the **only** way a payment can ever be
matched to the booking that produced it.

The alphabet drops every character that sounds or looks like another (`0/O`,
`1/I/L`, `5/S`), because the code is read aloud across a noisy lobby, often not
in the reader's first language.

## Status is never derived from settlement

In either direction. `App\Services\BookingService` is the only writer, and no
method in it looks for money to decide a status:

- A booking can be `realised` with **no transaction** (included).
- A transaction can exist with **no booking** (a walk-up sale).

`linkSettlement()` attaches a payment to a booking by **direct reference only**.
There is no booking↔transaction inference in Phase 1 — the same temptation the
WP-3 stay-window rule exists to resist.

## How bookings are created

Two paths.

**The concierge.** `App\Ai\Tools\CreateBookingTool`, available to the WhatsApp
agent. When a guest says "yes, book me on the sunset dive", the agent records
the commitment and gives them their reference code. It sets
`origin = recommendation` when the booking follows an offer the agent made,
`guest_request` otherwise.

**Staff, through the API below.** The walk-up at the desk, and — more
importantly — everything that happens afterwards.

## The API

```
GET  /api/booking                    list
GET  /api/booking/{id}               one booking
POST /api/booking                    take a booking
POST /api/booking/{id}/status        move it through its lifecycle
```

Headers as everywhere else: `X-API-KEY`, `Authorization: Bearer …`,
`Accept: application/json`.

**There is no update and no delete.** A booking is cancelled, with a reason.

### Who can call it

`App\Policies\BookingPolicy`: **`admin` or `employee`**, own hotel only (super
admin bypasses). Deliberately wider than most write endpoints here — the person
at the dive centre is the one who knows whether the guest turned up, and an
attendance instrument only admins can reach will not record attendance.

### `POST /api/booking`

```json
{
  "guest_id": "01a00c93-…",
  "activity_id": "01a00c93-…",
  "recommendation_id": null,
  "charge_model": "pay_on_site",
  "scheduled_for": "2026-09-10T18:00:00Z",
  "pax": 2,
  "channel": "desk"
}
```

| Field | Rules |
| --- | --- |
| `guest_id` | **required**, must belong to your hotel. |
| `activity_id` | optional, must belong to your hotel. |
| `item_name` | **required when there is no `activity_id`** — a guest can book something not in the catalogue yet. |
| `charge_model` | **required** — `included`, `pay_on_site`, `folio`, `prepaid`. |
| `recommendation_id` | optional. When present the booking is credited to that recommendation immediately, as a directly observed (L1) conversion. |
| `origin` | optional — `staff` (default) or `guest_request`. **`recommendation` is not accepted**: it is derived from `recommendation_id`, so a booking can never claim a credit the link does not support. |
| `scheduled_for`, `pax`, `expected_value`, `currency`, `channel` | optional. `expected_value`/`currency` default to the activity's price. |

`201` with the booking, including its `reference` — give that code to the guest.

### `POST /api/booking/{id}/status`

```json
{ "status": "realised", "realised_at": "2026-09-10T18:40:00Z" }
```

| Field | Rules |
| --- | --- |
| `status` | **required** — `confirmed`, `realised`, `no_show`, `cancelled`. `pending` is not accepted: a booking starts there and only moves forward. |
| `reason` | **required when `cancelled`.** |
| `realised_at` | optional; defaults to now. |

**This is the route the conversion report depends on.** Nothing else in the
system can observe attendance — not the agent, not the ledger (an included
activity produces no transaction), not the importer. Without it,
`realisation_rate` has nothing to measure.

A cancelled booking cannot be reopened (`422`) — letting a stale process
resurrect a commitment the guest withdrew is worse than making someone create
a new one.

## Related Docs

- `docs/conversion-analytics-api-documentation.md` — the report built on these.
- `docs/transaction-api-documentation.md` — the ledger and the
  `booking_reference` import column.
