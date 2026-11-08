# Contract: Account-Level Hotel Ownership

All responses use the usual `{ message, code, body }` shape. There are no new routes.

## Hotel representations

`owner_id` and `owner` are removed everywhere a hotel appears: `/api/hotel`, `/api/hotel/{id}`, `/api/superadmin/hotels`, and the nested `hotel` in login, `GET /api/user`, `/api/users`, `/api/superadmin/users` and `POST /api/register`.

On hotel lists, `filter[owner_id]` and `sort=owner_id` return `422`. A sent `owner_id` on a hotel write is ignored.

## Choosing a hotel

Every tenant route accepts `X-Hotel-Id`. Super admins are unaffected. `POST /api/logout`, `GET /api/hotel`, `POST /api/connect` and `GET /api/check-paired` don't apply it: they never narrow to a hotel, or refuse a stale one.

An admin's request works in **one hotel**. Its lists, reports, records and new records are all that hotel's, and a record in another hotel returns `404`, even one the admin can reach.

| Header | Admin | Employee |
| --- | --- | --- |
| Absent | Works in their default hotel | As today |
| A hotel they can reach | Works in that hotel. `user.hotel` shows it, and `user.hotel_id` stays the stored home hotel | As today: their records stay limited to the home hotel |
| Their suspended hotel | Suspended `403` (spec 024) | Suspended `403` |
| Anything else, including unknown, soft-deleted or malformed | Plain `403` | Plain `403` |

The plain refusal gives away nothing about the hotel:

```json
{ "message": "This action is unauthorized.", "code": 403, "body": null }
```

Clients should clear a stored choice when they get this response. A client-sent `hotel_id` stays ignored for admins and employees.

## `GET`, `PUT`, `PATCH /api/hotel/{id}`

| Caller | Result |
| --- | --- |
| Owner of the hotel's account | `200` |
| Other admin with this as home or member hotel | `200` |
| Reachable, but the hotel is suspended | Suspended `403`, scope `hotel` |
| Admin who can't reach it | Plain `403`, nothing about suspension |
| Employee, with any permissions | `403` |
| Super admin | Unchanged |

## Records in every other tenant module

| Caller | In the hotel the request works in | In any other hotel |
| --- | --- | --- |
| Owner or other admin | Same as their home hotel today | `404` from the tenant scope, or `403` |
| Employee | Home hotel only. A member hotel's record is `403` | `404` or `403` |

Links to other records are checked against the hotel of the record being written, and a mismatch is the existing `403`. An update never moves a record to another hotel. On `PUT /api/hotel/{id}`, team and category settings are checked against that hotel, whichever hotel is chosen.

`POST /api/ai-advisor/chat`: a `conversation_id` that started in another hotel returns the existing `404` with `Conversation not found.`.

## `POST /api/register`

As today, and the response's `user` now includes:

```json
{ "hotel_group_id": "<the new hotel's account>", "group_role": "owner" }
```

## `POST /api/superadmin/users`

`group_role` accepts `owner` or null. An admin is never made owner without it, including the first admin of an account.

| Case | Status | Message |
| --- | --- | --- |
| `group_role: group_admin`, or any other value | `422` | Validation error on `group_role` |
| The account already has an owner | `422` | `This account already has an owner.` |
| On an employee | `422` | `Only admins can be given an account-wide role.` (existing) |

Nothing is created on a refusal, and no email is sent.

## `PUT /api/superadmin/users/{id}/access`

| Input | Result |
| --- | --- |
| `group_role: owner` | Owner, with `hotel_group_id` set. `422` as above if the account has another owner |
| `group_role: owner` for the current owner | `200`, no change, no event |
| `group_role: null` | Role and `hotel_group_id` cleared. The user keeps their home hotel and memberships |
| `role: employee` on an owner | Role, group role and `hotel_group_id` cleared |
| `role: admin` on an employee | Admin, never owner |

## `/api/users`

`hotel_group_id` and `group_role` are ignored for every caller on create and update, and the rest of the request applies. A malformed value still gets `422`. Sending them for a super admin target stays `403`.

| Caller | Target | `GET` | `PUT` / `PATCH` | `DELETE` |
| --- | --- | --- | --- | --- |
| Admin | An employee whose home hotel is the one the request works in | Allowed | Allowed | Allowed |
| Admin, not the owner | Another admin, or the owner, based at that hotel | Allowed | `403` | `403` |
| Admin, not the owner | Themselves | Allowed | Allowed | Allowed |
| Owner | Another admin based at that hotel | Allowed | Allowed | Allowed |
| Owner | Themselves | Allowed | Allowed, but a changed `role` is `403` | `403` |
| Super admin | The owner | Allowed | Allowed. A changed `role` also ends the ownership | Allowed |

`GET /api/users` lists users whose home hotel is the one the request works in, plus the admin themselves. `POST /api/users` creates the user in that hotel. An owner manages another hotel's staff by choosing that hotel first.

Only the account owner or a super admin can send `role: admin` on `POST /api/users`, or change a user's role to admin on `PUT /api/users/{id}`. Any other admin gets `403` with `Only the account owner can create or promote an admin.`, and nothing changes. A missing role still creates an employee.

## `PUT /api/superadmin/hotels/{id}`

The owner refusal is gone. Moving a hotel stays `422` while it has memberships, home users with an account link, or home users with memberships elsewhere. A self-registered customer's hotel falls under the second rule until a super admin sends `group_role: null` for them.

## Breaking changes

For `docs/latest-changes-<date>.md`:

1. `owner_id` and `owner` are gone from every hotel, and `filter[owner_id]` and `sort=owner_id` are `422`.
2. `group_admin` is gone, and an account can have one owner.
3. An admin is no longer made anything automatically when created or promoted.
4. `X-Hotel-Id` naming an unreachable hotel is a `403`, where it used to be ignored.
5. `X-Hotel-Id` now decides the one hotel an admin's request works in, and without it that hotel is their default. **Admins with several hotels no longer get lists or dashboards combining them.**
6. Admins can work in every hotel they reach by choosing it, including hotels that used to refuse them. `GET /api/hotel` keeps listing all of them.
7. `/api/users` ignores `hotel_group_id` and `group_role`. Only the account owner or a super admin can create or promote an admin, or update or delete another admin. An admin who could do these before now gets `403`.
8. Registration returns an owner, and moving that customer's hotel needs `group_role: null` first.
9. An advisor conversation can't be continued from another hotel.

**What the frontend needs to do**: remove `owner_id` from its types and the hotel details view, and `group_admin` from its role list. Add a hotel switcher that sends `X-Hotel-Id` and clears on a plain `403`. Correct the wording about hotel ownership in the access and create-user forms. Hide edit and delete on other admins' rows, and the admin choice in the role field, unless the signed-in user has `group_role: owner`. Start a new advisor chat on switching hotels.
