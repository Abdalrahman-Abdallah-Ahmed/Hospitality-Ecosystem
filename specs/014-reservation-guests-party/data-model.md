# Data Model: Reservation Guests and Party Composition

**Feature**: [spec.md](spec.md) · **Research**: [research.md](research.md)

## New: `reservation_guests`

| Field | Rules |
| --- | --- |
| `id` | UUID primary key. |
| `hotel_id` | Required hotel scope; equals the reservation and guest hotel. |
| `reservation_id` | Required reference to one reservation; deleted with the reservation. |
| `guest_id` | Required reference to one guest. A guest can occur once per reservation. |
| `is_primary` | Required boolean. Exactly one live row per reservation is primary. |
| timestamps, soft delete | Preserve membership history and support audit conventions. |

**Indexes and constraints**

- Unique live guest membership for `(reservation_id, guest_id)`.
- One live primary membership per reservation, enforced with a PostgreSQL partial unique index.
- Lookup indexes for `(hotel_id, reservation_id)` and `(hotel_id, guest_id)`.

## Changed: `reservations`

| Field | Change | Rules |
| --- | --- | --- |
| `guest_id` | Retained | Always equals the guest in the sole primary `reservation_guests` row. |
| `children_ages` | New JSON integer array, default `[]` | Values are whole numbers 0 through 17; ordered as supplied. |
| `adults`, `children` | Existing totals | Apply across all reservation room lines. A named guest list may be shorter, but never longer, than `adults + children`. |

Relations:

- `Reservation::guest()` remains the primary guest relation for existing callers.
- `Reservation::reservationGuests()` returns all linked party members, including the primary marker, with their guest profiles.
- `Reservation::guests()` is the convenience relationship when only profiles are needed.

## Changed: `guests`

No schema change. A guest profile can be linked to multiple reservations at its hotel. A person may be an additional guest on a reservation and remains independent: their own messages, memories and contact history are never copied from the primary guest.

## Changed: `stays`

Before a stay has departed, changing a reservation's primary guest updates that stay's `guest_id`. Departed stays retain the guest who actually stayed, preserving historical operations and audit meaning.

## Write invariants

1. Each reservation has at least one live linked guest and exactly one primary.
2. The primary link's `guest_id` equals `reservations.guest_id` after every successful write.
3. Every linked guest belongs to the same hotel as the reservation.
4. A guest can occur only once in a reservation's live list.
5. A named guest list has no more entries than `adults + children`.
6. New reservations, and updates changing `children` or `children_ages`, require exactly one age for each child. With zero children, the ages list is empty.
7. A legacy reservation that has children and an empty age list remains editable when its children total and ages are untouched.

## Migration sequence

1. Create `reservation_guests` and its foreign keys, indexes and partial uniqueness rules.
2. Add nullable-or-empty `children_ages` with a database default of `[]`.
3. Backfill each reservation, including soft-deleted rows, with its current `guest_id` as the only primary membership; do not alter other fields. The backfill only inserts where no membership exists, so re-execution cannot duplicate links.
4. Normalize existing `children_ages` values to `[]`; do not fabricate age data.

The migration writes directly through the database layer, mirroring the existing reservation-room backfill, so it does not emit a large volume of application audit events.
