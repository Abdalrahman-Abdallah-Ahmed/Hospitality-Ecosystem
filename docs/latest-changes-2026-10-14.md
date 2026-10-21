# Latest changes — 2026-10-14

## Reservation guests and children ages

Reservation responses now provide the complete `guests` list with `is_primary`, `guest_count`, and `children_ages`. `guest_id` and `guest` continue to identify the primary guest. Create and update requests may send a complete guest list with exactly one primary member. Additional guests are hotel-scoped guest profiles; they do not gain WhatsApp access to the primary guest's reservation. New reservations with children and updates that change child totals or ages must provide exactly one age (0–17) per child. Existing reservations with no age data remain editable when party data is unchanged.

Admin AI create/update and read tools use the same guest-list and age rules. Imports accept optional `children_ages` as comma-separated whole-number ages and optional `guests` as a JSON array using the same guest-list structure as the API; malformed or incomplete supplied age lists skip the row without leaving partial data.
