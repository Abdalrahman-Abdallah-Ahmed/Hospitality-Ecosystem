# Staff Roles API Documentation

A **staff role** is a named set of permissions an admin creates for their hotel (for example "Front Desk" or "Housekeeping") and assigns to employees. It controls what each employee can view, create, update and delete.

Defined by `App\Http\Controllers\StaffRoleController` and `App\Http\Controllers\PermissionController`, gated by `App\Policies\StaffRolePolicy`. The permission list is `App\Enums\Permission`.

## How Permissions Work

| User | What they can do |
| --- | --- |
| `super_admin` | Everything, in every hotel. |
| `admin` | Everything in their own hotel. Admins never have a staff role. |
| `employee` with no staff role | The [default permissions](#default-permissions), which match what every employee could do before staff roles existed. |
| `employee` with a staff role | **Exactly** the role's permissions. The role replaces the defaults rather than adding to them, so a role without `guests.view` removes guest access. |

Rules that apply whatever a role grants:

- **Own hotel only.** A permission covers records in the employee's own hotel. Another hotel's record still returns `403`.
- **Admin-only areas.** These are not in the permission list and cannot be granted:
  - User management (`/api/users`)
  - Staff roles and `/api/permissions`
  - Hotel settings (`/api/hotel`)
  - AI advisor chat (`/api/ai-advisor/chat`)
  - Account usage (`/api/usage`)
- **Hard limits.** Nobody can edit or delete a booking or a transaction; they are cancelled or reversed instead. Only a super admin can create or delete a hotel.

### Default Permissions

An employee with no staff role has:

`activities.view`, `bookings.view`, `bookings.create`, `bookings.update_status`, `dashboard.view`, `guests.view`, `recommendations.record_outcome`

`GET /api/permissions` returns this list as `employee_defaults`, so the UI does not need to hard-code it.

### Permission Reference

| Permission | Endpoints it opens |
| --- | --- |
| `activities.view` | `GET /api/activity`, `GET /api/activity/{id}` |
| `activities.create` | `POST /api/activity` |
| `activities.update` | `PUT /api/activity/{id}` |
| `activities.delete` | `DELETE /api/activity/{id}` |
| `activity_categories.view` | `GET /api/activity-category`, `GET /api/activity-category/{id}` |
| `activity_categories.create` | `POST /api/activity-category` |
| `activity_categories.update` | `PUT /api/activity-category/{id}` |
| `activity_categories.delete` | `DELETE /api/activity-category/{id}` |
| `ai_insights.view` | `GET /api/ai-insights` |
| `ai_insights.generate` | `POST /api/ai-insights` |
| `bookings.view` | `GET /api/booking`, `GET /api/booking/{id}` |
| `bookings.create` | `POST /api/booking` |
| `bookings.update_status` | `POST /api/booking/{id}/status` |
| `dashboard.view` | `GET /api/dashboard` |
| `guests.view` | `GET /api/guest`, `GET /api/guest/{id}` |
| `guests.create` | `POST /api/guest` |
| `guests.update` | `PUT /api/guest/{id}` |
| `guests.delete` | `DELETE /api/guest/{id}` |
| `history.view` | `GET /api/history/{type}/{id}` |
| `hotel_policies.view` | `GET /api/hotel-policy` |
| `hotel_policies.create` | `POST /api/hotel-policy` |
| `hotel_policies.update` | `PUT /api/hotel-policy/{id}` |
| `hotel_policies.delete` | `DELETE /api/hotel-policy/{id}` |
| `knowledge_base_articles.view` | `GET /api/knowledge-base-articles`, `GET /api/knowledge-base-articles/{id}` |
| `knowledge_base_articles.create` | `POST /api/knowledge-base-articles` |
| `knowledge_base_articles.update` | `PUT /api/knowledge-base-articles/{id}` |
| `knowledge_base_articles.delete` | `DELETE /api/knowledge-base-articles/{id}` |
| `recommendations.view` | `GET /api/recommendation`, `GET /api/recommendation/{id}`, `GET /api/analytics/conversion` |
| `recommendations.update` | `PUT /api/recommendation/{id}` |
| `recommendations.delete` | `DELETE /api/recommendation/{id}` |
| `recommendations.generate` | `POST /api/reservation/{id}/recommendations` |
| `recommendations.record_outcome` | `POST /api/recommendation/{id}/outcome` |
| `reservations.view` | `GET /api/reservation`, `GET /api/reservation/{id}` |
| `reservations.create` | `POST /api/reservation` |
| `reservations.update` | `PUT /api/reservation/{id}` |
| `reservations.delete` | `DELETE /api/reservation/{id}` |
| `reservations.import` | `POST /api/reservation/import` |
| `rooms.view` | `GET /api/room`, `GET /api/room/{id}` |
| `rooms.create` | `POST /api/room` |
| `rooms.update` | `PUT /api/room/{id}` |
| `rooms.delete` | `DELETE /api/room/{id}` |
| `task_categories.view` | `GET /api/task-category`, `GET /api/task-category/{id}` |
| `task_categories.create` | `POST /api/task-category` |
| `task_categories.update` | `PUT /api/task-category/{id}` |
| `task_categories.delete` | `DELETE /api/task-category/{id}` |
| `tasks.view` | `GET /api/task`, `GET /api/task/{id}` |
| `tasks.create` | `POST /api/task` |
| `tasks.update` | `PUT /api/task/{id}` |
| `tasks.delete` | `DELETE /api/task/{id}` |
| `teams.view` | `GET /api/team`, `GET /api/team/{id}` |
| `teams.create` | `POST /api/team` |
| `teams.update` | `PUT /api/team/{id}`, `POST /api/team/{id}/members` |
| `teams.delete` | `DELETE /api/team/{id}` |
| `transactions.view` | `GET /api/transaction`, `GET /api/transaction/{id}` |
| `transactions.import` | `POST /api/transaction/import` |
| `transactions.reverse` | `POST /api/transaction/{id}/reverse` |

## Base URL and Headers

All endpoints are under `/api` and need:

```http
X-API-KEY: {your_api_key}
Authorization: Bearer {login_token}
Accept: application/json
Content-Type: application/json
```

## Who Can Call These Endpoints

Every endpoint in this document, including `GET /api/permissions`, is for `admin` (own hotel) and `super_admin` (any hotel). An employee gets `403` whatever their role grants.

## Response Format

Successful responses use the usual wrapper:

```json
{ "message": "Some message", "code": 200, "body": {} }
```

Validation errors (`422`) use Laravel's default shape `{ "message": "...", "errors": { "field": ["..."] } }`. A policy failure is `{ "message": "This action is unauthorized." }` with `403`.

## The Staff Role Object

```json
{
  "id": "019fb2a0-1111-7000-9000-abcdef123456",
  "hotel_id": "019f9b37-c265-726d-a6fe-f7eaa7852636",
  "name": "Front Desk",
  "description": "Check-in, guests and bookings",
  "permissions": ["guests.view", "guests.update", "bookings.view", "bookings.create"],
  "users_count": 3,
  "created_at": "2026-09-15T09:00:00.000000Z",
  "updated_at": "2026-09-15T09:00:00.000000Z"
}
```

- `permissions` is always an array of permission values and may be empty.
- `users_count` is the number of employees who hold the role.

## 1. List Permissions

`GET /api/permissions`

Returns everything a role editor needs: all permissions grouped by resource, plus the defaults for employees without a role. Labels are English display text generated from the values.

HTTP `200`:

```json
{
  "message": "Permissions fetched successfully.",
  "code": 200,
  "body": {
    "groups": [
      {
        "group": "guests",
        "label": "Guests",
        "permissions": [
          { "value": "guests.view", "action": "view", "label": "View" },
          { "value": "guests.create", "action": "create", "label": "Create" },
          { "value": "guests.update", "action": "update", "label": "Update" },
          { "value": "guests.delete", "action": "delete", "label": "Delete" }
        ]
      },
      {
        "group": "bookings",
        "label": "Bookings",
        "permissions": [
          { "value": "bookings.view", "action": "view", "label": "View" },
          { "value": "bookings.create", "action": "create", "label": "Create" },
          { "value": "bookings.update_status", "action": "update_status", "label": "Update Status" }
        ]
      }
    ],
    "employee_defaults": ["activities.view", "bookings.view", "bookings.create", "bookings.update_status", "dashboard.view", "guests.view", "recommendations.record_outcome"]
  }
}
```

(Shortened: the real response has every group from the [permission reference](#permission-reference).)

Not every group has all four of view/create/update/delete (for example, `transactions` has `view`, `import` and `reverse`). Build the grid from the response rather than assuming a fixed set of columns.

## 2. List Staff Roles

`GET /api/staff-roles`

Supports the usual `filter[<column>]`, `search`, `sort` (`-name` for descending), `page` and `per_page` (1–100, default 15). An admin sees only their own hotel's roles; a super admin sees all.

HTTP `200`. `body.data` holds [staff role objects](#the-staff-role-object); pagination is under `body.meta` and `body.links`.

## 3. Create a Staff Role

`POST /api/staff-roles`

```json
{
  "name": "Housekeeping",
  "description": "Rooms and tasks",
  "permissions": ["rooms.view", "rooms.update", "tasks.view", "tasks.update"]
}
```

| Field | Rules |
| --- | --- |
| `name` | required, string, max 255, unique among this hotel's roles. Another hotel may use the same name, and so can a new role after the old one is deleted. |
| `description` | optional, string. |
| `permissions` | required but may be empty `[]`; must be a JSON array (not an object) of distinct values from the [permission reference](#permission-reference). An unknown value, for example `users.update`, returns `422`. |
| `hotel_id` | Ignored for an admin: the role always goes to their own hotel. A super admin must send it. |

HTTP `201` with the created staff role.

Errors:

- `422`: validation (duplicate name, unknown or repeated permission, missing `name`).
- `403` `{ "message": "You must belong to, or specify, a valid hotel.", "code": 403, "body": null }`: an admin with no hotel, or a super admin who didn't send a valid `hotel_id`.

## 4. Get a Staff Role

`GET /api/staff-roles/{id}`

HTTP `200` with the staff role. `404` if the id doesn't exist; `403` if it belongs to another hotel. Treat the `403` like a `404`.

## 5. Update a Staff Role

`PUT /api/staff-roles/{id}` (or `PATCH`)

Send only what changes. `permissions`, if sent, **replaces** the whole list; it is not merged.

```json
{ "permissions": ["rooms.view", "tasks.view", "tasks.update"] }
```

Same rules as create, but every field is optional. Changes take effect on the employees' next request; they don't need to log in again.

HTTP `200` with the updated role. `403` for another hotel's role.

## 6. Delete a Staff Role

`DELETE /api/staff-roles/{id}`

The role is soft-deleted. You **can't delete a role that is still assigned**. Move its employees to another role, or to no role, first:

HTTP `422`:

```json
{
  "message": "This staff role is still assigned to employees. Reassign them before deleting it.",
  "code": 422,
  "body": null
}
```

Use `users_count` to warn before the admin tries. On success, HTTP `200` with `"Staff role deleted successfully."`.

## Assigning a Role to an Employee

A role is assigned through user management with `staff_role_id`. See [User Management API](/D:/Hospitality%20Ecosystem/docs/user-management-api-documentation.md#staff-roles).

```http
PUT /api/users/{id}
{ "staff_role_id": "019fb2a0-1111-7000-9000-abcdef123456" }
```

- Send `"staff_role_id": null` to put the employee back on the defaults.
- A role from another hotel returns `403`.
- Giving a role to an `admin` or `super_admin` returns `422`.
- Promoting an employee to admin clears their role.

## Showing and Hiding UI

`GET /api/user` (and every user object from `/api/users`) includes `permissions`, the user's **effective** permissions:

- the full list for admins and super admins,
- the defaults for an employee without a role,
- the role's list otherwise.

Decide what to show from that array (e.g. `permissions.includes('rooms.create')`) instead of checking `role === 'admin'`. The server still enforces every rule, so a hidden button is a convenience, not the protection.

`permissions` never includes the admin-only areas. Keep using `role` for the users, staff roles, hotel settings, AI advisor and usage screens.

## Audit Trail

Creating, updating or deleting a role writes `staff_role.created`, `staff_role.updated` or `staff_role.deleted` to the event log, including the name, description and permission changes. Assigning or removing a user's role writes `user.staff_role_assigned` with the previous and new `staff_role_id`.

## Related Docs

- [User Management API](/D:/Hospitality%20Ecosystem/docs/user-management-api-documentation.md)
- [Latest Changes — 2026-09-15](/D:/Hospitality%20Ecosystem/docs/latest-changes-2026-09-15.md)
