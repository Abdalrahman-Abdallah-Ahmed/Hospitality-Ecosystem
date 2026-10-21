# Contract: Reservation Guests and Party Composition API

**Feature**: [../spec.md](../spec.md) · **Model**: [../data-model.md](../data-model.md)

Successful and business-rule responses keep the existing `{ message, code, body }` envelope. Validation failures keep Laravel's standard `errors` map. API-key, Sanctum, throttle and tenant middleware, and reservation permissions are unchanged.

## Reservation representation

The existing `guest` and `guest_id` remain the primary guest. Add these fields to detail and list responses:

```json
{
  "guest_id": "primary-guest-id",
  "guest": { "id": "primary-guest-id", "first_name": "Rami" },
  "guests": [
    { "id": "primary-guest-id", "first_name": "Rami", "is_primary": true },
    { "id": "additional-guest-id", "first_name": "Maya", "is_primary": false }
  ],
  "guest_count": 2,
  "adults": 2,
  "children": 2,
  "children_ages": [5, 8]
}
```

The list response may omit expanded additional profiles when it returns `guest_count`, but a reservation detail must return the complete marked guest list and ages.

## Create reservation

`POST /api/reservation` accepts the legacy primary guest fields and the new `guests` array.

```json
{
  "guest_id": "primary-guest-id",
  "guests": [
    { "guest_id": "primary-guest-id", "is_primary": true },
    { "first_name": "Maya", "last_name": "Haddad", "phone_number": "+201001234568" }
  ],
  "adults": 2,
  "children": 2,
  "children_ages": [5, 8],
  "rooms": [{ "room_type_id": "room-type-id", "quantity": 1 }]
}
```

- A guest item either identifies an existing same-hotel guest with `guest_id`, or contains contact information. Phone matching takes precedence. Without a phone match, an email reuses a profile only when it identifies exactly one live guest at that hotel; a shared email or phone/email conflict is rejected rather than guessed.
- A single named guest without `is_primary` is primary for backward compatibility.
- With multiple named guests, exactly one must be marked primary. Its resolved id must equal the top-level `guest_id` when that legacy field is supplied.
- The `guests` list cannot contain the same resolved guest twice or exceed `adults + children`.
- `children_ages` is required whenever a new request sets `children > 0`; it must have the same length as `children`, with values 0 through 17.

## Update reservation

`PUT` or `PATCH /api/reservation/{id}` accepts `guests` as the complete desired live guest list. When omitted, the guest list is unchanged.

```json
{
  "guests": [
    { "guest_id": "new-primary-id", "is_primary": true },
    { "guest_id": "former-primary-id", "is_primary": false }
  ],
  "children": 3,
  "children_ages": [2, 5, 8]
}
```

- A primary switch demotes the former primary in the same request.
- Removing the primary without another primary is invalid.
- If `children` or `children_ages` is present, both values after the update must satisfy the age invariant. A legacy reservation may otherwise update unrelated fields with its empty ages list retained.
- Updates to a cancelled or checked-out reservation remain allowed for guest-list and age corrections, subject to the standard update permission and audit trail.

## Errors

Validation failures return HTTP 422 with the normal Laravel `errors` map.

| Condition | Error field |
| --- | --- |
| No or multiple primary guests | `guests` |
| Duplicate or foreign-hotel guest | `guests.{index}.guest_id` |
| Contact data conflicts with the supplied guest id | `guests.{index}` |
| More named guests than party total | `guests` |
| Ages count differs from children total | `children_ages` |
| Age below 0, above 17, or not whole | `children_ages.{index}` |
| Ages supplied for zero children | `children_ages` |

## AI and import contract

- `CreateReservationTool` and `UpdateReservationTool` accept the same party data in the vocabulary they already use for guest lookup and creation, then invoke the shared reservation command.
- `GetReservationTool`, `GetReservationsTool` and `GetOwnReservationTool` expose the primary marker, guest list and ages. The own-reservation tool exposes only the primary guest's reservation.
- The reservation import maps every source party member to the same guest-list operation. It preserves incomplete legacy ages only when none are supplied; a supplied partial list is invalid.
