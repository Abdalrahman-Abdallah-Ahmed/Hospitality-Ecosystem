# Latest Backend Changes — 2026-09-15

Admins can now decide what each employee may do. Nothing breaks: every existing employee keeps exactly the access they had.

## 1. New: Staff Roles

An admin creates named roles for their hotel ("Front Desk", "Housekeeping"), ticks the permissions each one has (view, create, update, delete, plus actions like `bookings.update_status` or `transactions.reverse`), and assigns one role per employee.

- `GET /api/permissions`: every permission grouped by resource, plus `employee_defaults`. Use it to build the role editor.
- `GET|POST /api/staff-roles`, `GET|PUT|DELETE /api/staff-roles/{id}`: role CRUD. Admins and super admins only.
- Deleting a role still assigned to employees returns `422`.

**Full doc:** [Staff Roles API](/D:/Hospitality%20Ecosystem/docs/staff-roles-api-documentation.md)

## 2. New: `staff_role_id` on Users

`POST /api/users` and `PUT /api/users/{id}` accept `staff_role_id`:

- Only employees can have a role; sending one for an admin returns `422`.
- The role must belong to the user's hotel (`403` otherwise).
- `null` puts the employee back on the defaults.
- Promoting an employee to admin clears their role.

**Full doc:** [User Management API § Staff Roles](/D:/Hospitality%20Ecosystem/docs/user-management-api-documentation.md#staff-roles)

## 3. New: `permissions`, `staff_role_id` and `staff_role` on User Objects

`POST /api/login` and `POST /api/register` (`body.user`), `GET /api/user` and every user object from `/api/users` now include:

- `permissions`: the user's effective permission values. The full list for admins; the defaults or their role's list for employees.
- `staff_role_id` and `staff_role`: `null` when unassigned.

Show and hide navigation and actions from `permissions` rather than from `role`.

## 4. Unchanged for Existing Employees

An employee without a staff role has the same access as before:

- view guests and activities
- view, create and change the status of bookings
- record recommendation outcomes
- see the dashboard

Admins still have full access to their hotel. Users, staff roles, hotel settings, the AI advisor and usage stay admin-only and can't be granted.

## Checklist

- [ ] Run migrations on deploy (`staff_roles` table, `users.staff_role_id`).
- [ ] Admin settings: add a Staff Roles screen (list, create/edit with a permission grid from `GET /api/permissions`, delete with a warning when `users_count > 0`).
- [ ] User form: add a staff role picker for employees; hide it for admins.
- [ ] Navigation and action buttons: drive visibility from `permissions` on `GET /api/user`.
