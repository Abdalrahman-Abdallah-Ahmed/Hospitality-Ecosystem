# The Booking Entity

Phase 1, WP-3a. **This document describes a domain entity, not an API** — WP-3a
deliberately ships no HTTP endpoints (see [Deferred](#deferred-the-staff-api)).

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

## How bookings are created today

One path: `App\Ai\Tools\CreateBookingTool`, available to the WhatsApp concierge
agent. When a guest says "yes, book me on the sunset dive", the agent records
the commitment and gives the guest their reference code. It sets
`origin = recommendation` when the booking follows an offer the agent made,
`guest_request` otherwise.

## Deferred: the staff API

There is **no `BookingController`** yet — no list, create, or status endpoints.

The consequence, stated plainly rather than papered over: **nothing can mark a
booking `realised` or `no_show`.** Only the person at the outlet observes
whether the guest turned up — not the agent, not the ledger (an included
activity produces no transaction), not the importer.

So the WP-5 conversion report returns `realisation_rate: null` with a written
reason, never `0`. An honest gap beats a confident wrong number.

## Related Docs

- `docs/conversion-analytics-api-documentation.md` — the report built on these.
- `docs/transaction-api-documentation.md` — the ledger and the
  `booking_reference` import column.
