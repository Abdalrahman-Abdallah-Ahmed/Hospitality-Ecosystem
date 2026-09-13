# Latest Backend Changes — 2026-09-13

Security fixes for four critical issues found in an audit. Three of them change behaviour a client can observe; each is listed below with what to do.

## 1. Breaking: `GET /api/check-paired` Now Requires Login

It used to need only the API key and would return the name and role of **any** user for **any** phone number. It now sits behind `auth:sanctum` and only looks at the caller's own hotel(s).

**What changed for the frontend:**

- Send `Authorization: Bearer {login_token}` when polling. Without it you get `401 Unauthenticated.`
- A number belonging to another hotel now reports `paired: false` with `user_name` / `user_role` both `null`.
- The response shapes and status codes (`200` / `202` / `201`) are otherwise unchanged.

**Full doc:** [WhatsApp Device API § 3](/D:/Hospitality%20Ecosystem/docs/whatsapp-device-api-documentation.md#3-check-pairing-status)

## 2. Breaking: WhatsApp Pairing Codes Expire and Work Once

The token from `POST /api/connect` used to be a full, non-expiring API token that stayed valid after pairing.

**What changed:**

- The code expires **15 minutes** after issue. An expired code gets `Invalid token.` from `/api/pair`, or the "isn't valid or has expired" reply over WhatsApp. Offer a "generate a new code" action.
- The code is deleted once a device is paired with it.
- The code is no longer accepted as a bearer token on any API route (`401`).
- `/api/pair` no longer accepts a normal login token in place of a pairing code.

**Full doc:** [WhatsApp Device API § 1–2](/D:/Hospitality%20Ecosystem/docs/whatsapp-device-api-documentation.md#1-generate-pairing-token)

## 3. Breaking: Only Super Admins Can Grant Super Admin or Account Membership

On `POST /api/users` and `PUT /api/users/{id}`:

- A regular admin sending `role: "super_admin"` now gets `403 Only a super admin can assign the super admin role.` Previously any admin could promote anyone, including themselves.
- `hotel_group_id` and `group_role` are silently ignored unless a super admin sends them. Previously an admin could attach themselves to another account and read its data.
- A regular admin now gets `403` on `show` / `update` / `destroy` for a super admin, even one attached to their hotel.
- An admin with no hotel of their own now gets `403` on `show` / `update` / `destroy` for every user, including their own record. Use `GET /api/user` for the caller's profile.

**Full doc:** [User Management API](/D:/Hospitality%20Ecosystem/docs/user-management-api-documentation.md)

## 4. Ops: The API Key Is Enforced in Production

`X-API-KEY` was read with `env()`, which returns null once `php artisan optimize` caches config. The deploy workflow runs that command, so production was accepting requests with no key. The check now reads `config('app.api_key')` and compares in constant time.

- **Before deploying, make sure `API_KEY` is set in the production `.env`.** With no key configured, every request outside `local` / `testing` is now rejected with `401`.
- Clients already sending the correct header see no change.

## 5. Breaking: Booking Status Only Moves Forward

`POST /api/booking/{id}/status` now enforces a transition table. A realised booking can no longer be marked `confirmed`, `no_show` or `cancelled`, and a `no_show` can only become `realised` (a late arrival). A disallowed move returns `422`, e.g. `A realised booking cannot be marked confirmed.` Repeating the status a booking already has succeeds and keeps the original timestamp.

**Full doc:** [Booking Entity § BookingStatus](/D:/Hospitality%20Ecosystem/docs/booking-entity-documentation.md#bookingstatus)

## 6. `reservation_id` Is Unique Per Hotel

Two hotels can now use the same reservation code (migration `2026_09_13_000000`). A duplicate within the same hotel still returns `422` on `reservation_id` with the same message as before. Imports skip such a row with `Reservation id … already exists.` instead of a raw database error.

## 7. Room Status Follows Every Stay in the Room

Booking a room for a future date no longer frees it while another guest is checked in. Moving a checked-in reservation to another room frees the old room and occupies the new one. A room in `maintenance` is no longer flipped to `available` by a reservation. Edits to a reservation's room, dates, party or value now reach its stay.

## 8. Fix: Reversing the Same Transaction Twice at Once

A second concurrent reversal now returns `422 This transaction has already been reversed.` instead of a `500`. The ledger itself was never at risk: the database already refused the duplicate row.

## 9. Fix: Phone Number Format for WhatsApp Pairing

`GET /api/check-paired` rejected any number longer than 12 characters, so `+20 115 179 3758` typed into the Connect WhatsApp modal failed. Numbers were also compared as exact strings, so a device paired as `+20…` never matched the digits Meta's webhook sends.

**The format is now settled: send any common format; the server stores and compares digits only.**

- `check-paired` and `pair` accept `+`, spaces, dashes and brackets. What's left must be 7–15 digits including the country code, otherwise `422` on `phone_number`.
- A number without its country code (`01151793758`) is a different number — ask for the full international number in the modal.
- `device.phone_number` in responses is now digits only (`201151793758`). Migration `2026_09_13_000001` converts devices already stored with formatting.

**Full doc:** [WhatsApp Device API § 3](/D:/Hospitality%20Ecosystem/docs/whatsapp-device-api-documentation.md#3-check-pairing-status)

## Checklist

- [ ] Confirm `API_KEY` is set on the production server before this deploys.
- [ ] Send the login bearer token on `GET /api/check-paired`.
- [ ] Handle an expired pairing code: show a "generate a new code" action.
- [ ] Connect WhatsApp modal: send the number as typed (URL-encoded), ask for it with the country code, and show the `422` `phone_number` message inline.
- [ ] Remove "Super admin" from a regular admin's role picker, and don't send `hotel_group_id` / `group_role` from admin screens.
- [ ] Booking status UI: only offer the moves in the transition table, and surface a `422` message if a stale screen tries another.
- [ ] Run `php artisan migrate` (included in the deploy workflow) — it changes the `reservations` unique index.
