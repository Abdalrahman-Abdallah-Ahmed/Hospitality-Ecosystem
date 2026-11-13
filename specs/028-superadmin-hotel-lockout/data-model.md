# Data Model: Super Admins Lose Direct Hotel Access

**Feature**: [spec.md](spec.md) · **Date**: 2026-11-09

**No schema change and no migration.** The block is decided by the token's user's `role` at request time. The new endpoints read and delete existing rows.

## Entities touched

### User (`users`)

- `role` (`UserRole`): `super_admin` on the user is what the block checks. Unchanged.
- `group_role`: `owner` makes the user an account owner (`User::isOwner()`). The new delete refuses an owner (409).
- Deleted for good (no `SoftDeletes`). The existing `deleted` hook recounts the account's seats and forgets password links.
- `accessibleHotelIds()` keeps returning `null` for a super admin; `permissions()` keeps returning every case for a super admin.

### Personal access token (`personal_access_tokens`)

- A super admin's own login token: blocked on hotel routes.
- An impersonation token (`impersonator_id` set, user = hotel user): allowed, as the hotel user.
- Deleting a user through the new endpoint deletes all of that user's tokens, impersonation tokens included, in the same transaction.

### StaffRole (`staff_roles`, `BelongsToHotel`, `SoftDeletes`)

- Read per hotel by `GET /api/superadmin/hotels/{hotel}/staff-roles`. Soft-deleted roles are not listed. `users_count` included, as on the tenant list.

### Team (`teams`, `BelongsToHotel`)

- Read per hotel by `GET /api/superadmin/hotels/{hotel}/teams`.

### EventLog (`event_logs`)

New platform event type, written by `EventLogger::recordPlatform()` (so `hotel_id = null`, actor = the super admin):

| `event_type` | Subject | `changes` | `context` |
| --- | --- | --- | --- |
| `platform.user.deleted` | the deleted user (type + id; the row no longer exists) | snapshot `{ name, email, role, hotel_id, hotel_group_id }` as `from` values | `{ tokens_revoked: <int> }` |

## Rules

| Rule | Where enforced | Result |
| --- | --- | --- |
| A super admin's own token on a hotel route (except `POST /logout`, `GET /user`) | `DenySuperAdmin`, before route-model binding | 403 `Super admins use /api/superadmin.` |
| Delete a super admin | `Superadmin\UserController::administrable()` | 404 |
| Delete an account owner | `UserAdministration::delete()` | 409 `Transfer ownership first: clear this user's owner role, then delete them.` |
| Read roles or teams of an unknown or deleted hotel | route binding on `{hotel}` | 404 |
| Any of the new endpoints with a non-super-admin or an impersonation token | `super_admin` guard on `routes/superadmin.php` | 403 |
