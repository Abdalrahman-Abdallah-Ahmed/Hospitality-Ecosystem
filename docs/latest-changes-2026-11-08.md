# Latest changes — 2026-11-08

## The signed-in user's own WhatsApp device

New `GET /api/whatsapp-device` returns `{ "paired": true, "device": { ... } }`, or `{ "paired": false, "device": null }`, always with `200`. New `DELETE /api/whatsapp-device` unpairs it, and voids any unused pairing code, also with `200`. A session gets `403`. It needs no phone number, so a settings screen can show the real status on load. API: [whatsapp-device-api-documentation.md](whatsapp-device-api-documentation.md#4-get-your-own-device).

## Super admin impersonation (spec 027)

A super admin can now act as a hotel user for up to 30 minutes to support them, with the audit trail naming the real super admin. Spec: `specs/027-superadmin-impersonation`. API: [superadmin-impersonation-api-documentation.md](superadmin-impersonation-api-documentation.md).

### Changed

- **`GET /api/history/{type}/{id}`: hotel readers see less about a super admin actor.** When the caller isn't a super admin and a row's actor is a super admin or a user who no longer exists, `actor` is only `{ "id", "name" }` (or `null`), and `context` has no `ip`. A super admin's own token reads the full row. Rows with a hotel user as actor don't change. Don't read `actor.email` or `actor.phone_number` from history without a fallback.

### New endpoints

- `POST /api/superadmin/users/{user}/impersonate` returns `token`, `token_id` and `expires_at`, 30 minutes ahead.
- `POST /api/superadmin/impersonation/end` takes `{ "token_id": 123 }`.
- `POST /api/superadmin/users/{user}/end-impersonation` ends all of that user's sessions, and returns `{ "ended": 2 }`.

### What the frontend needs while a session runs

- **Banner**: `GET /api/user` adds `impersonation: { impersonator: { id, name }, expires_at }`, only with an impersonation token. Show who is acting as whom and when it ends.
- **Keep the super admin's own token.** Send the session's token on hotel endpoints only. It gets `403` on every `api/superadmin` route, so end a session with `POST /api/logout` on the session's token, or the end endpoint on the super admin's own.
- **Handle the refusal.** These return `403` with the message `This action is not available while impersonating.`, so hide or disable them: `POST /api/connect`, every write to `/api/users` and `/api/staff-roles`, `POST /api/team/{id}/members`, and `POST /api/ai-advisor/chat`.
- **Handle `401` at 30 minutes.** Sessions can't be extended. Return to the super admin's own token, and offer to start a new session.
- **Send `X-Hotel-Id` throughout a session.** With a suspended default hotel and no header, reads and writes land in different hotels.
- **Suspended hotels**: `GET` and `HEAD` work and return the hotel's data. Other methods get the usual suspended `403`, or `404` for a record outside the hotel the request works in.

### Internal

- New nullable `personal_access_tokens.impersonator_id`.
- `sanctum:prune-expired --hours=1` runs hourly, removing every kind of token an hour after it expires.
- Nothing changes for a request without an impersonation token, except the history change above.
