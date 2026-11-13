# API Contract: Super Admins Lose Direct Hotel Access

**Feature**: [../spec.md](../spec.md) · **Date**: 2026-11-09

Every response uses the project envelope `{ message, code, body }`. All requests need `X-API-KEY` and `Authorization: Bearer <token>`.

## 1. Changed: every hotel endpoint in `routes/api.php` behind `auth:sanctum`

Applies to the super admin's **own** token. An impersonation token is unaffected.

| Request | Before | After |
| --- | --- | --- |
| Any method, any hotel route except the two below | Allowed in every hotel (policy bypass), `?hotel_id=` to name a hotel | `403` |
| Same, with an unknown record id | `404` | `403` |
| Same, with an invalid body | `422` | `403` |
| `GET /api/user` | `200` | `200`, unchanged (role and full `permissions` list) |
| `POST /api/logout` | `200` | `200`, unchanged |

```json
{ "message": "Super admins use /api/superadmin.", "code": 403, "body": null }
```

Also changed for **every caller**: the `hotel_id` query or body field that let a super admin name the hotel is removed from hotel endpoints (availability, stays, conversations, housekeeping board, exports, reservation imports and previews, voucher templates, knowledge documents and articles, AI activity, staff roles, teams…). Hotel users never had it applied; a request that still sends it is not rejected, and the value is ignored.

Hotel endpoints also stop showing super-admin-only detail: AI activity run cost, and full actor details in record history for a super admin caller.

## 2. New: `GET /api/superadmin/hotels/{hotel}/staff-roles`

A hotel's staff roles, for assigning one through `PUT /api/superadmin/users/{user}/access`.

- **Query**: the generic index parameters: `filter[<column>]`, `search`, `sort` (`-name` for descending), `page`, `per_page` (1–100, default 15).
- **200**: `body` is the paginated list, each item the same shape as `GET /api/staff-roles` (the `StaffRoleResource`, including `permissions` and `users_count`). Only roles with `hotel_id = {hotel}`.
- **404**: unknown or deleted hotel.
- **403**: caller is not a super admin, or is an impersonation token.

## 3. New: `GET /api/superadmin/hotels/{hotel}/teams`

A hotel's teams, for the same purpose.

- **Query**: as above.
- **200**: paginated list, each item the same shape as `GET /api/team` (the `TeamResource`). Only teams with `hotel_id = {hotel}`.
- **404** / **403**: as above.

## 4. New: `DELETE /api/superadmin/users/{user}`

Deletes a user for good. Replaces `DELETE /api/users/{id}` for super admins.

- **200**: `{ "message": "User deleted successfully.", "code": 200, "body": null }`. All the user's tokens are revoked, including any impersonation session for them. A `platform.user.deleted` event is recorded.
- **404**: unknown user, or the target is a super admin.
- **409**: the target is an account owner.

  ```json
  { "message": "Transfer ownership first: clear this user's owner role, then delete them.", "code": 409, "body": null }
  ```

  To delete an owner, first `PUT /api/superadmin/users/{user}/access` with `{ "group_role": null }`, optionally make another user owner the same way, then delete.
- **403**: caller is not a super admin, or is an impersonation token.

## Frontend call map (for the changes doc)

| Frontend call as a super admin | Replacement |
| --- | --- |
| `GET /api/staff-roles?filter[hotel_id]=X` (`UserAccessModal`) | `GET /api/superadmin/hotels/X/staff-roles` |
| `GET /api/team?filter[hotel_id]=X` (`UserAccessModal`) | `GET /api/superadmin/hotels/X/teams` |
| `DELETE /api/users/{id}` (Users page) | `DELETE /api/superadmin/users/{id}`; handle `409` for an owner |
| `GET /api/users` (`TeamModal`, `TaskModal`), `GET /api/permissions`, WhatsApp device routes, every hotel screen | None: reached through an impersonation session, or not needed. Hide them for a super admin (`permissions()` still lists every permission, so gate on role, not permission) |
