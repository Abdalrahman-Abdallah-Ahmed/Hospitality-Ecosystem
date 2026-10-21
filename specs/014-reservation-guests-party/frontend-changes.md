# Frontend Changes (`ecosystem-frontend`): SPEC-011 Reservation Guests and Party

This is for the frontend team. All backend changes are additive: `guest_id` and `guest`
still identify the primary guest, and single-guest requests keep working. Field-level
detail is in `docs/reservations-api-documentation.md`.

## 1. Reservation form (create and edit)

- **Guest list**: replace the single guest picker with a list.
  - Each row is either an existing guest (search by name, phone or email) or a new guest
    (first name, last name, phone, email; phone or email required).
  - One radio per row marks the **primary guest**. Exactly one must be selected. The first
    row starts as primary.
  - Keep top-level `guest_id` equal to the primary row's guest. For a new primary guest,
    create the guest first or leave `guest_id` to match the resolved primary.
  - "Add guest" is disabled once the list reaches `adults + children`.
  - Removing the primary row is not allowed until another row is primary.
- **Payload**: send `guests` as the complete list on create and on any edit of the list:
  `[{ "guest_id": "…", "is_primary": true }, { "phone_number": "…", "first_name": "…", "is_primary": false }]`.
  Omit `guests` on edits that don't touch the list.
- **Children's ages**: when `children` > 0, show one age input (0–17) per child; adding or
  removing a child adds or removes an input. Send `children_ages` in the same order.
  - On create, ages are required for every child.
  - On edit, send `children` and `children_ages` together whenever either changes. An
    older reservation with children but no ages can be edited without ages as long as the
    children count is left alone; show the age inputs empty, with a hint.
- **Lowering the party**: if the user lowers adults or children below the number of named
  guests, the API rejects it. Prompt them to remove guests first.

## 2. Reservation detail

- Show the guest list from `guests`, primary first, with a "Primary" badge.
- Show `children_ages` next to the children count, for example "2 children (5, 8)".
- Guests whose profile was deleted are not in `guests`; `guest_count` matches the list.

## 3. Reservation list

- Keep the primary guest's name in the guest column, and add "+N" from `guest_count - 1`
  when there are other guests.

## 4. Validation messages to surface

Show these `422` errors on the matching fields:

| Field | When |
| --- | --- |
| `guests` | no primary or two primaries, the list exceeds the party, a phone/email conflict, an email shared by several guests, or `guest_id` doesn't match the primary |
| `guests.{n}.guest_id` | that guest isn't available at this hotel, or appears twice |
| `guests.{n}` | contact details don't match the selected guest |
| `children_ages` | the number of ages doesn't equal `children`, or an age is outside 0–17 |

## 5. Import

The reservation import accepts two optional columns: `children_ages` (comma-separated, e.g.
`5,8`) and `guests` (a JSON array in the same shape as the API). Mention them on the import
screen's column help. Rows that give children without ages are still imported.
