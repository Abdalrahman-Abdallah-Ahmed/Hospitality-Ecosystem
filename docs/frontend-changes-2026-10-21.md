# Frontend Changes: 2026-10-21 — Reservation Guests and Children's Ages (SPEC-011)

For `ecosystem-frontend`. The backend adds **no new endpoints and no new permissions**.
Responses only gain fields, but **two request rules now reject what the current
reservation form sends** (§1). Ship §1 first.

Backend spec: `specs/014-reservation-guests-party/`.

Related backend docs:

- `docs/reservations-api-documentation.md`: "Reservation guests and children's ages"
  section, request field tables, import columns
- `docs/latest-changes-2026-10-14.md`: release notes
- `specs/014-reservation-guests-party/contracts/reservation-guests-api.md`: full contract

---

## 1. Required now: these break the current form

### 1.1 Creating a reservation with children

`POST /api/reservation` with `children > 0` must send `children_ages`: an ordered array of
whole numbers from 0 to 17, **one per child**. Without it the API returns `422` on
`children_ages`.

```json
{ "adults": 2, "children": 2, "children_ages": [5, 8] }
```

Sending `children_ages` with `children: 0` is also a `422`.

### 1.2 Editing a reservation that has children

On `PUT /api/reservation/{id}`, **if the payload contains `children` or `children_ages`**,
the number of ages must equal `children`.

Older reservations have children but an empty `children_ages: []`. If the edit form sends
the whole party on every save, editing one of them (even just its dates) now fails.

Do one of the following:

- Send `children` and `children_ages` **only when the party actually changed**. Unrelated
  edits then keep working for older reservations.
- Or show the age inputs on edit and require them before saving.

### 1.3 Lowering the party below the named guests

An update that lowers `adults` or `children` below the number of named guests returns
`422` on `guests`. Tell the user to remove guests first. (This only matters once §2 ships,
because every existing reservation has one guest.)

---

## 2. New: guest list with a primary guest

### 2.1 Response fields (reservation detail, list, create and update responses)

| Field | Type | Meaning |
| --- | --- | --- |
| `guests` | array | Everyone on the reservation, **primary first**. Each item is a guest object plus `is_primary`. |
| `guest_count` | integer | Number of items in `guests`. |
| `children_ages` | integer[] | One age per child, in the order entered. `[]` for older reservations. |

Unchanged: `guest_id` and `guest` still identify the **primary guest**, so existing
screens keep working.

Guests whose profile was deleted are left out of `guests` and `guest_count`; `guest_id`
keeps pointing at the original primary.

### 2.2 Request: `guests` on create and update

Send `guests` as the **complete list**. Each item is either an existing guest or a new
guest by contact details:

```json
{
  "guest_id": "019f9b37-c266-7099-9793-ad875bf378d1",
  "guests": [
    { "guest_id": "019f9b37-c266-7099-9793-ad875bf378d1", "is_primary": true },
    { "first_name": "Maya", "last_name": "Kamal", "phone_number": "+201001234568", "is_primary": false }
  ]
}
```

Rules:

- Exactly **one** item has `is_primary: true`. A single item may omit it and becomes
  primary.
- Top-level `guest_id` is still required on create and **must equal the primary item's
  guest**. So on create the primary must be an existing guest; only additional guests can
  be new contacts. On update you may name a new-contact primary: leave `guest_id` out and
  the backend sets it to the resolved primary.
- A new-guest item needs a `phone_number` or an `email` (either one is enough).
  - The backend reuses an existing guest of the same hotel: phone first, then email, but
    only when exactly one guest has that email.
  - It never creates a duplicate profile.
- A guest can appear once, must belong to the same hotel, and the list can't be longer than
  `adults + children`.
- On update, omit `guests` to leave the list alone. Including it **replaces** the whole
  list. Removing someone doesn't delete their guest profile.
- Staff with the reservation create/update permission can add new guests here; they don't
  also need the guest-create permission.

### 2.3 UI

**Reservation form (create and edit)**

- Replace the single guest picker with a list of rows.
  - Each row is either an existing guest (search by name, phone or email) or a new guest
    (first name, last name, phone, email).
  - A radio on each row marks the primary guest. The first row starts as primary.
  - Keep top-level `guest_id` in sync with the primary row.
- Disable "Add guest" once the list reaches `adults + children`.
- Don't allow removing the primary row until another row is primary.
- When `children > 0`, show one age input (0–17) per child. Changing the children count
  adds or removes inputs.

**Reservation detail**

- Show the guest list, primary first, with a "Primary" badge.
- Show the ages next to the children count, for example "2 children (5, 8)".
- For older reservations with no ages, show the count only.

**Reservation list**

- Keep the primary guest's name in the guest column.
- Add "+N" (`guest_count - 1`) when there are other guests.

---

## 3. Validation errors to show

All are `422`, with the error on the matching field.

| Field | When |
| --- | --- |
| `guests` | No primary or two primaries; list longer than the party; phone and email point to different guests; email shared by several guests; top-level `guest_id` doesn't match the primary; party lowered below the named guests |
| `guests.{n}.guest_id` | Guest not available at this hotel, or listed twice |
| `guests.{n}` | Contact details don't match the selected existing guest |
| `children_ages` | Count doesn't equal `children`, an age is outside 0–17, or ages sent with no children |

---

## 4. Reservation import screen

`POST /api/reservation/import` accepts two new optional columns. Add them to the column
help:

| Column | Format |
| --- | --- |
| `children_ages` | Comma-separated ages, e.g. `5,8`. If present, the count must equal `children`. |
| `guests` | JSON array in the same shape as §2.2. |

A row with children but no `children_ages` column is still imported (older data). A row
with a wrong number of ages or an age outside 0–17 is skipped and listed in `skipped` with
its reason.

---

## 5. WhatsApp / Concierge

No frontend change. Only the primary guest gets reservation details on WhatsApp.
Additional guests are treated as their own guest profile.
