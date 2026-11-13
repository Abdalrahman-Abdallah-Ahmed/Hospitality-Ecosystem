# Latest changes — 2026-11-10

## Super admins lose direct access to hotel endpoints (spec 028)

**Breaking.** A super admin's own token now gets `403` on every hotel endpoint. To see or change what a hotel's user sees, a super admin starts an [impersonation session](superadmin-impersonation-api-documentation.md) and uses its token. Spec: `specs/028-superadmin-hotel-lockout`.

### Changed

- **Every authenticated route in `routes/api.php` refuses a super admin's own token**, except `POST /api/logout` and `GET /api/user`:

  ```json
  { "message": "Super admins use /api/superadmin.", "code": 403, "body": null }
  ```

  The refusal comes before any record is looked up and before validation, so an unknown id or an invalid body gets this `403` too, not `404` or `422`. An impersonation token is unaffected, because it belongs to the hotel user.
- **Login and `GET /api/user` are unchanged.** A super admin still gets `role: "super_admin"` and every permission in `permissions`. That list is no longer a reliable guide to which screens work: **gate hotel screens on the role, not on permissions**, for a super admin.
- **The `hotel_id` input that let a super admin name a hotel is gone** from availability, stays (arrivals, departures, in-house, unassigned arrivals), conversations, the housekeeping board, the maintenance list, list exports, reservation imports and import previews, voucher templates, knowledge documents and articles, staff roles and AI activity. A hotel user who still sends it is not rejected; the value is ignored, as it always was for them.
- **A hotel-less caller's message changed.** `"You must belong to, or specify, a valid hotel."` is now `"You must belong to a hotel."` (still `403`). `GET /api/conversations` and `GET /api/ai-activity` now answer a caller with no hotel `403 You must belong to a hotel.` instead of `422 Choose a hotel (hotel_id)…`.
- **Super-admin-only detail on hotel endpoints is gone:** AI activity no longer carries `cost_usd` on run entries (read cost in `GET /api/superadmin/ai-cost`), and record history no longer has a "super admin caller sees the full actor" case.
- **An owner's role can't be changed on `PUT /api/users/{id}`, by anyone.** It changes through `PUT /api/superadmin/users/{user}/access`.

### New endpoints

- `GET /api/superadmin/hotels/{hotel}/staff-roles`: the hotel's staff roles, same item shape as `GET /api/staff-roles`.
- `GET /api/superadmin/hotels/{hotel}/teams`: the hotel's teams, same item shape as `GET /api/team`.
- `DELETE /api/superadmin/users/{user}`: deletes a user for good and revokes their tokens. `409` `Transfer ownership first: clear this user's owner role, then delete them.` for an account owner; `404` for a super admin.

Details: [superadmin-accounts-api-documentation.md](superadmin-accounts-api-documentation.md#a-hotels-staff-roles-and-teams) and [Deleting a user](superadmin-accounts-api-documentation.md#deleting-a-user).

### What the frontend needs to change

| Today, as a super admin | Change to |
| --- | --- |
| `GET /api/staff-roles?filter[hotel_id]=X` (Users → Manage access) | `GET /api/superadmin/hotels/X/staff-roles` |
| `GET /api/team?filter[hotel_id]=X` (Users → Manage access) | `GET /api/superadmin/hotels/X/teams` |
| `DELETE /api/users/{id}` (Users page) | `DELETE /api/superadmin/users/{id}`; show the `409` message for an owner |
| Hotel screens in the sidebar (Reservations, Guests, Rooms, Tasks, Teams, Knowledge Base, Reports, Notifications, Settings → Hotel, …) | Hide them for `role: "super_admin"`. Reach them through an impersonation session |
| Settings → Integrations (WhatsApp device: `/api/connect`, `/api/check-paired`, `/api/whatsapp-device`) | Hide it for a super admin: a device belongs to a hotel user's home hotel |
| `GET /api/users` (Team and Task modals), `GET /api/permissions` | Nothing: only reached from hotel screens, which a super admin opens through a session |

### Internal

- New `no_super_admin` middleware (`App\Http\Middleware\DenySuperAdmin`) on the tenant group, before route-model binding. `SuperAdminDeniedOnTenantRoutesTest` walks every hotel route as a super admin.
- Every super admin bypass on hotel paths is removed: 25 policy `before()` methods (two policies keep a `before()` for their own rules), and the branches in `resolveHotel()`, the tenant user, knowledge, usage and AI activity controllers, the AI tools, the hotel list request and the AI activity and event log resources. `resolveHotel()` takes the user only.
- `routes/web.php` (a session logout and the welcome page) is removed. The API has no web routes.
- Constitution amended to v3.0.0: a super admin reaches hotel data only through audited impersonation.
- No migration.
