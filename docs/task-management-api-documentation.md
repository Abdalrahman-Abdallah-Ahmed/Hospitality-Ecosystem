# Task Management API Documentation

This document describes the **team, task-category, and task** APIs — the three new resources that let a hotel organize work: group employees into **teams**, group work types into **task categories** owned by a team, and create **tasks** that reference a room/guest/reservation and are routed to a team + category + assignee.

Controllers: `App\Http\Controllers\TeamController`, `App\Http\Controllers\TaskCategoryController`, `App\Http\Controllers\TaskController`.
Policies: `App\Policies\TeamPolicy`, `App\Policies\TaskCategoryPolicy`, `App\Policies\TaskPolicy`.

## The Domain Model, In Order

These three resources are hierarchical. Build the UI to create them in this order:

1. **Team** — e.g. "Housekeeping", "Front Desk". Belongs to one hotel.
2. **Employee membership** — a `User` with `role: employee` is attached to a team via a dedicated endpoint (`POST /api/team/{team}/members`), not by editing the user directly.
3. **Task Category** — e.g. "Cleaning", "Maintenance Request". Belongs to one hotel, and optionally to one team.
4. **Task** — the actual unit of work. Can reference a room/guest/reservation, and can be routed to a team + a category. **If both are set, the category must belong to that same team** (see [Team/Category Consistency](#teamcategory-consistency-rule) below) — the flow the backend expects is "choose team, then choose one of that team's categories," not independent pickers.

## Base URL

All endpoints below are defined in `routes/api.php`, served under:

`/api`

Examples:

- `GET /api/team`, `POST /api/team`, `PUT /api/team/{id}`, `DELETE /api/team/{id}`, `POST /api/team/{id}/members`
- `GET /api/task-category`, `POST /api/task-category`, `PUT /api/task-category/{id}`, `DELETE /api/task-category/{id}`
- `GET /api/task`, `POST /api/task`, `GET /api/task/{id}`, `PUT /api/task/{id}`, `DELETE /api/task/{id}`

## Required Headers

Every request below needs all three:

```http
X-API-KEY: {your_api_key}
Authorization: Bearer {login_token}
Accept: application/json
Content-Type: application/json
```

- `X-API-KEY` is checked by the `api.key` middleware.
- `Authorization: Bearer {login_token}` is required because every route below is inside `auth:sanctum`. Get this token from `POST /api/login` (see `docs/auth-api-documentation.md`) — the token is at `body.token` in that response.
- Without a valid bearer token, the API returns HTTP `401 Unauthenticated.` before any controller/policy logic runs.

## Who Can Call These Endpoints

All three policies follow the same shape: a `before()` hook lets **`super_admin` bypass every check below, unconditionally**.

| Resource | Action | Rule (non-super-admin) |
| --- | --- | --- |
| Team | `index`, `create` | `role` must be `admin`. |
| Team | `show`, `update`, `delete`, add member | `role` must be `admin`, **and** the team's `hotel_id` must equal the caller's own hotel. |
| Task Category | `index`, `create` | `role` must be `admin`. |
| Task Category | `update`, `delete` | `role` must be `admin`, **and** the category's `hotel_id` must equal the caller's own hotel. |
| Task | `index`, `create` | `role` must be `admin`. |
| Task | `show`, `update`, `delete` | `role` must be `admin`, **and** the task's `hotel_id` must equal the caller's own hotel. |

Practical implications for the UI:

- A non-admin user (`employee`) should never reach any of these screens — treat a `403` here as "shouldn't be on this page."
- A `403` on a specific id most likely means it belongs to a different hotel — treat it like a `404`.
- `index` is additionally query-scoped to `where('hotel_id', <caller's own hotel>)` for every one of these three resources — a regular admin's list can never contain another hotel's rows.

## Response Format

Successful custom API responses use this structure:

```json
{
  "message": "Some message",
  "code": 200,
  "body": {}
}
```

**Validation errors (`422`) and Laravel's own authorization/not-found failures are exceptions to this** — they use Laravel's default shapes, not the `message/code/body` wrapper. See [Error Shapes](#error-shapes).

---

## 1. Team API

### The Team Object

```json
{
  "id": "019fc000-1111-7000-9000-abcdef123456",
  "hotel_id": "019f9b37-c265-726d-a6fe-f7eaa7852636",
  "name": "Housekeeping",
  "description": null,
  "is_active": true,
  "created_at": "2026-08-01T10:00:00.000000Z",
  "updated_at": "2026-08-01T10:00:00.000000Z"
}
```

- `id` and `hotel_id` are UUID strings.
- `is_active` defaults to `true` at the database level when omitted on create.
- No soft deletes on teams — `DELETE` permanently removes the row.
- `index` eager-loads `hotel`. `show` eager-loads `hotel` **and** `members` (the array of `User` objects whose `team_id` points at this team). `store`/`update` only eager-load `hotel` — if you need the freshly-created team's member list, re-fetch via `show`.

### 1.1 List Teams — `GET /api/team`

Query params (all optional): `filter[<column>]`, `search`, `sort`, `page`, `per_page` — same generic behavior as every other list endpoint in this API (see `docs/room-api-documentation.md` for the full param reference). Filterable/sortable columns: `id`, `hotel_id`, `name`, `description`, `is_active`, `created_at`, `updated_at`.

### 1.2 Create a Team — `POST /api/team`

```json
{
  "hotel_id": "any-valid-hotel-uuid",
  "name": "Housekeeping",
  "description": "Room cleaning and turndown",
  "is_active": true
}
```

| Field | Rules |
| --- | --- |
| `hotel_id` | **Required by validation** (`exists:hotels,id`) but **always ignored** — the backend overwrites it with the caller's own hotel before saving. You must still send *some* valid hotel id or you'll get a `422` for a missing field; simplest is to send the caller's own `hotel_id`. |
| `name` | required, string, max 255. **Must be unique within the caller's hotel** — a different hotel can reuse the same name. |
| `description` | optional, string. |
| `is_active` | optional, boolean. Defaults to `true`. |

**Duplicate name error** — this is a manual check in the controller (the DB has a composite `unique(hotel_id, name)` index, but that alone can't produce a friendly message), so it comes back as a normal Laravel validation error:

```json
{
  "message": "The given data was invalid.",
  "errors": { "name": ["A team with this name already exists."] }
}
```

**No associated hotel:** if the caller has no hotel, you get the custom wrapper: `{"message": "You do not belong to any hotel.", "code": 403, "body": null}`.

Success: `201`, `body` is the created [team object](#the-team-object).

### 1.3 Get a Team — `GET /api/team/{id}`

Success: `200`, `body` is the [team object](#the-team-object) with `hotel` and `members` loaded.

### 1.4 Update a Team — `PUT /api/team/{id}`

Same fields as create, all optional (partial update). **`hotel_id` in the payload is silently ignored** — you cannot move a team to a different hotel through this endpoint. The duplicate-name check re-runs (excluding the team being edited), so renaming to a name already used by another team in the same hotel returns the same `422` shown above.

Success: `200`, `body` is the updated [team object](#the-team-object) (with `hotel` loaded, not `members`).

### 1.5 Add a Member to a Team — `POST /api/team/{id}/members`

This is the **only** way to attach an employee to a team — there is no way to set `team_id` through a generic user-update endpoint documented here.

```json
{ "user_id": "019f...-employee-uuid" }
```

| Field | Rules |
| --- | --- |
| `user_id` | required, uuid, must exist in `users.id`. |

Server-side checks, in order:

1. Authorization: caller must be `admin` of the team's own hotel (same rule as update).
2. The target user must belong to the caller's hotel — otherwise `403`: `{"message": "The selected user does not belong to you.", "code": 403, "body": null}`.
3. The target user's `role` must be `employee` — otherwise `422`: `{"message": "Only employees can be added to a team.", "code": 422, "body": null}`. Note this one is `422` but **not** the Laravel validation shape (no `errors` key) — it's the custom wrapper.
4. On success, the user's `team_id` is set to this team's id.

**A user can only belong to one team at a time** (`team_id` is a single foreign key on `users`, not a many-to-many pivot). Adding an employee who is already on Team A to Team B silently *moves* them — it does not add them to both. If your UI shows "add existing member," warn the user if the target is already on another team.

Success: `200`, `body` is the [team object](#the-team-object) with `hotel` and `members` reloaded — read the updated roster from `body.members`, not from the request you just sent.

### 1.6 Delete a Team — `DELETE /api/team/{id}`

Hard delete — **but only if the team has no members.** `users.team_id`'s foreign key has no cascade/null-on-delete rule, so deleting a team that still has at least one member fails at the database level with a foreign-key constraint violation, which surfaces as an unhandled `500` (there is no try/catch in the controller to turn this into a friendly `403`/`422`). **The frontend must block the delete action (or confirm the team is empty) whenever `members` is non-empty**, rather than relying on the API to reject it cleanly.

Task categories and tasks are safe by comparison: `task_categories.team_id` and `tasks.assigned_to_team_id` both use `nullOnDelete()`, so deleting an empty team correctly nulls those references out instead of failing.

---

## 2. Task Category API

### The Task Category Object

```json
{
  "id": "019fc000-2222-7000-9000-abcdef123456",
  "hotel_id": "019f9b37-c265-726d-a6fe-f7eaa7852636",
  "team_id": "019fc000-1111-7000-9000-abcdef123456",
  "name": "Cleaning",
  "description": null,
  "created_at": "2026-08-01T10:00:00.000000Z",
  "updated_at": "2026-08-01T10:00:00.000000Z"
}
```

- `team_id` is **nullable** — a category can be hotel-wide (no team) or scoped to one team.
- No soft deletes — `DELETE` is permanent.
- `index` eager-loads `hotel` and `team`. `store`/`update` also load `hotel` and `team`.

**There is currently no `GET /api/task-category/{id}` (show) endpoint working**, even though the route is registered (`Route::resource(...)->except(['edit', 'create'])` includes `show` by default). `TaskCategoryController` has no `show()` method, so hitting that URL will error server-side rather than return `404` cleanly. **Do not build a single-category detail fetch against this endpoint** — build the detail/edit view from the row you already have in the list, the same workaround used for `hotel-policy` (see `docs/hotel-policy-api-documentation.md`). Flag this to the backend team if you need it fixed.

### 2.1 List Task Categories — `GET /api/task-category`

Same generic `filter`/`search`/`sort`/`page`/`per_page` params. Filterable/sortable columns: `id`, `hotel_id`, `team_id`, `name`, `description`, `created_at`, `updated_at`.

### 2.2 Create a Task Category — `POST /api/task-category`

```json
{
  "hotel_id": "any-valid-hotel-uuid",
  "team_id": "019fc000-1111-7000-9000-abcdef123456",
  "name": "Cleaning",
  "description": "Routine room cleaning tasks"
}
```

| Field | Rules |
| --- | --- |
| `hotel_id` | Required by validation, but **always ignored/overwritten** with the caller's own hotel — same pattern as Team. |
| `team_id` | optional, uuid, `exists:teams,id`. If provided, **must belong to the caller's own hotel**, or you get `403`: `"The selected teams does not belong to you."` |
| `name` | required, string, max 255. No uniqueness constraint (unlike Team's `name`). |
| `description` | optional, string. |

Success: `201`, `body` is the created [task category object](#the-task-category-object).

### 2.3 Update a Task Category — `PUT /api/task-category/{id}`

Same fields, all optional. `hotel_id` is still forced server-side to the record's own hotel on every update — you cannot reassign a category to a different hotel this way. `team_id`, if sent, is re-validated against the caller's hotel the same way as create.

**Caveat:** updating a category's `team_id` here does **not** check whether existing tasks under this category still make sense (see [Team/Category Consistency](#teamcategory-consistency-rule) below — that rule is only enforced from the Task side, when a task is created/updated, not when you edit a category's own `team_id` out from under tasks that already reference it).

Success: `200`, `body` is the updated [task category object](#the-task-category-object).

### 2.4 Delete a Task Category — `DELETE /api/task-category/{id}`

Hard delete. Tasks referencing this category have `task_category_id` set to `NULL` automatically (`nullOnDelete()`).

---

## 3. Task API

### The Task Object

```json
{
  "id": "019fc000-3333-7000-9000-abcdef123456",
  "hotel_id": "019f9b37-c265-726d-a6fe-f7eaa7852636",
  "room_id": null,
  "reservation_id": null,
  "guest_id": null,
  "assigned_to_team_id": "019fc000-1111-7000-9000-abcdef123456",
  "assigned_to_user_id": null,
  "task_category_id": "019fc000-2222-7000-9000-abcdef123456",
  "created_by_user_id": null,
  "title": "Fix the AC in room 204",
  "description": null,
  "created_by": "ai",
  "status": "pending",
  "priority": "normal",
  "due_date": null,
  "created_at": "2026-08-01T10:00:00.000000Z",
  "updated_at": "2026-08-01T10:00:00.000000Z",
  "deleted_at": null
}
```

Field notes:

- `created_by` is a fixed enum: `ai`, `system`, `guest`, `maintenance_schedule`, `manual`. Defaults to `ai` if omitted.
- `status` is a fixed enum: `pending`, `in_progress`, `completed`, `cancelled`. Defaults to `pending`.
- `priority` is a fixed enum: `low`, `normal`, `high`. Defaults to `normal`.
- Unlike `category` on hotel-policy or `status` on room, **these three fields are real, server-enforced enums** — sending any other string returns a `422`.
- **Tasks use `SoftDeletes`** — `DELETE` sets `deleted_at`, it does not remove the row. (Teams and task categories do **not** soft-delete — only Task does.)
- `index`, `show`, `store`, and `update` all eager-load only `hotel`, `guest`, `room`. **`assignedToTeam`, `assignedToUser`, `taskCategory`, `reservation`, and `createdByUser` are never eager-loaded** — you only get the raw `*_id` values for those. If you need to display the team name, category name, or assignee name on a task row, resolve those ids client-side against data you've already fetched from `GET /api/team` / `GET /api/task-category` / your user list, rather than expecting them embedded here.

### 3.1 List Tasks — `GET /api/task`

Same generic params. Filterable/sortable columns: `id`, `hotel_id`, `room_id`, `reservation_id`, `guest_id`, `assigned_to_team_id`, `assigned_to_user_id`, `task_category_id`, `created_by_user_id`, `title`, `description`, `created_by`, `status`, `priority`, `due_date`, `created_at`, `updated_at`, `deleted_at`. A useful board/kanban filter: `filter[status]=in_progress` or `filter[assigned_to_team_id]=<team-id>`.

**Breaking change — response shape:** `body` is no longer the paginator directly. It's now:

```json
{
  "message": "Tasks fetched successfully.",
  "code": 200,
  "body": {
    "data": {
      "current_page": 1,
      "data": [ { "...": "one task object" } ],
      "last_page": 1,
      "total": 1,
      "...": "rest of the standard paginator fields"
    },
    "task_categories": [
      { "id": "...", "hotel_id": "...", "team_id": "...", "name": "Cleaning", "description": "..." }
    ]
  }
}
```

- The task list moved from `body.data` to **`body.data.data`**; pagination fields (`current_page`, `last_page`, `total`, …) moved from `body.*` to **`body.data.*`**.
- `body.task_categories` is new — the full [task category](#the-task-category-object) list for the caller's hotel (same hotel-scoping rule as `body.data`: a regular admin gets only their own hotel's categories, a super admin gets every category system-wide since they have no hotel of their own). Meant to save a second `GET /api/task-category` call when rendering a task list/board that needs to show or filter by category name — safe to use for a category picker.

### 3.2 Create a Task — `POST /api/task`

The intended flow, per product: **title + description → choose team → choose one of that team's categories → submit.**

```json
{
  "hotel_id": "any-valid-hotel-uuid",
  "title": "Fix the AC in room 204",
  "description": "Guest reported no cold air.",
  "assigned_to_team_id": "019fc000-1111-7000-9000-abcdef123456",
  "task_category_id": "019fc000-2222-7000-9000-abcdef123456",
  "room_id": null,
  "reservation_id": null,
  "assigned_to_user_id": null,
  "created_by_user_id": null,
  "priority": "high",
  "due_date": "2026-08-05T14:00:00Z"
}
```

| Field | Rules |
| --- | --- |
| `hotel_id` | Required by validation, but **always ignored/overwritten** with the caller's own hotel. |
| `title` | required, string, max 255. |
| `description` | optional, string. |
| `room_id`, `reservation_id` | optional, uuid, must `exist` in their respective tables **and** belong to the caller's hotel. |
| `guest_id` | **Do not send this — it's not a client-settable field.** It's always resolved server-side from `reservation_id`: if you send a `reservation_id`, `guest_id` is silently overwritten with that reservation's own `guest_id` (so if the reservation has no guest, the task won't either). If you don't send `reservation_id`, `guest_id` is `null`. This applies to both `store` and `update` — the frontend never chooses which guest a task is assigned to; it's implied entirely by the reservation. |
| `assigned_to_team_id` | optional, uuid, must be a team belonging to the caller's hotel. |
| `assigned_to_user_id` | optional, uuid, must be a user belonging to the caller's hotel (any role with `hotel_id` set — not restricted to `employee`, unlike team membership). |
| `created_by_user_id` | **Do not send this on create — as of the latest backend change, it's silently discarded and always forced to the caller's own user id.** Sending someone else's id here has no effect; you'll get the authenticated user's id back regardless. (This only applies to `store`; see the [update caveat](#34-update-a-task) below — `update` still accepts it from the client.) |
| `task_category_id` | optional, uuid, must be a category belonging to the caller's hotel, **and must belong to the chosen `assigned_to_team_id`** — see below. |
| `created_by`, `status`, `priority` | optional, must be one of the enum values listed under [The Task Object](#the-task-object). |
| `due_date` | optional, ISO 8601 datetime. |

#### Team/Category Consistency Rule

If `task_category_id` is present in the request, the backend checks it against whatever `assigned_to_team_id` you also sent (or `null`, if you didn't send one):

- The category's own `team_id` must **exactly equal** the `assigned_to_team_id` you sent.
- This means a category with `team_id: null` (a hotel-wide category, not scoped to any team) can only be used on a task that also has **no** `assigned_to_team_id`. Choosing a team on the task and a team-less category together is rejected.
- Practical UI implication: **once the user picks a team, the category dropdown must be filtered to that team's categories only** (`GET /api/task-category?filter[team_id]=<chosen-team-id>`) — don't show every category in the hotel, or you'll let the user pick combinations the backend will reject.

Failure: `403`, custom wrapper: `{"message": "The selected task category does not belong to the chosen team.", "code": 403, "body": null}`.

#### Hotel-Ownership Errors (any of the FK fields above)

`403`, custom wrapper, with the message naming the relation (not the field) that failed:

```json
{
  "message": "The selected teams does not belong to you.",
  "code": 403,
  "body": null
}
```

The word plugged in is one of: `rooms`, `guests`, `reservations`, `teams`, `users`, `taskCategories` — depending on which field failed. It's a relation name, not a field name (e.g. a bad `assigned_to_user_id` *and* a bad `created_by_user_id` both surface as `"users"`) — don't try to map this string back to a specific form field; treat it as a generic "one of your selections belongs to a different hotel" banner rather than a per-input error.

Success: `201`, `body` is the created [task object](#the-task-object).

### 3.3 Get a Task — `GET /api/task/{id}`

Success: `200`, `body` is the [task object](#the-task-object). `404` if the id doesn't exist; `403` (treat as not-found) if it belongs to a different hotel.

### 3.4 Update a Task — `PUT /api/task/{id}`

All fields optional (partial update). `hotel_id` is still forced server-side to the caller's hotel on every update (not the task's original hotel — same forced-hotel pattern as store, which in practice can't change anything since `update`'s authorization already requires the task's hotel to match the caller's).

**The team/category consistency check runs on update too, using the *effective* values** — i.e., whichever of `assigned_to_team_id` / `task_category_id` you didn't include in this request falls back to the task's current value. Concretely:

- Send only `task_category_id` → it's checked against the task's *existing* `assigned_to_team_id`.
- Send only `assigned_to_team_id` → the task's *existing* `task_category_id` (if any) is checked against the *new* team, and rejected if it doesn't belong there. You cannot reassign a task to a different team while leaving behind a category that belonged only to the old team — clear or update `task_category_id` in the same request.

Same hotel-ownership and enum-value validation as create applies to whichever fields you include. (`hotel_id` on update is likewise always overwritten with the caller's own hotel, not read from the payload — for a regular admin this can't differ from the task's real hotel anyway, since the policy already required a match to get this far. See [Super Admin Caveats](#super-admin-caveats) for the one case where it matters.)

**`created_by_user_id` behaves differently here than on create:** unlike `store` (which now always forces it to the caller's own id — see [create](#32-create-a-task)), `update` still accepts whatever `created_by_user_id` you send, subject to the normal hotel-ownership check. This asymmetry is current backend behavior, not a documentation typo — don't let a client-side form component share validation assumptions between the create and edit forms for this one field.

**`guest_id` on update is only re-derived when you send `reservation_id` in that same request.** If your `PUT` payload omits `reservation_id` entirely (e.g. you're only changing `status` or `priority`), the task's existing `guest_id` is left as-is — it isn't wiped to `null` just because you didn't resend the reservation. If you do send `reservation_id` (including explicitly sending `null` to detach it), `guest_id` is recalculated from it, same as on create.

Success: `200`, `body` is the updated [task object](#the-task-object).

### 3.5 Delete a Task — `DELETE /api/task/{id}`

**Soft delete** (`deleted_at` is set). Success: `200`, `body: null`.

---

## Super Admin Caveats

The `before()` bypass on all three policies means a `super_admin` passes every *authorization* check unconditionally. But several of these controllers separately gate on `$request->user()->hotel` for their own business logic, **before** that authorization result is even used for scoping — and a super admin typically has no owned hotel (their `hotel` relation resolves to nothing). The two checks don't agree with each other, so behavior is inconsistent per endpoint. If you're building a super-admin console against these APIs, don't assume "policy allows it" implies "the endpoint will actually let them do it":

| Endpoint | Super admin can actually use it? |
| --- | --- |
| `GET /api/team`, `/api/task-category`, `/api/task` (list) | **No** — the query is hardcoded to `where('hotel_id', <caller's hotel>)`, which is `null` for a super admin, so these return an **empty list**, not "everything." |
| `POST /api/team` | **No** — blocked by the "You do not belong to any hotel." check before the insert. |
| `PUT /api/team/{id}` | **Yes** — this endpoint never checks `$request->user()->hotel` at all; only the (bypassed) policy gates it. A super admin can rename/edit any hotel's team. |
| `POST /api/team/{id}/members` | **Yes** — the ownership check uses the *team's* hotel (`$team->hotel`), not the caller's, so it works regardless of the super admin's own hotel. |
| `DELETE /api/team/{id}` | **Yes** — no hotel check in the controller, only the (bypassed) policy. Still subject to the empty-team FK restriction above. |
| `POST /api/task-category`, `POST /api/task` | **No** — same "You do not belong to any hotel." block as Team's create. |
| `PUT /api/task-category/{id}`, `PUT /api/task/{id}` | **No** — these two, unlike Team's update, *do* check `$request->user()->hotel` and block with the same 403 if it's empty. A super admin cannot edit any task or task category through these endpoints as of this writing. |
| `DELETE /api/task-category/{id}`, `DELETE /api/task/{id}` | **Yes** — no hotel check, only the (bypassed) policy. |

If a super-admin "manage everything across all hotels" screen is in scope, confirm with the backend team which of these gaps are being fixed first — as of this writing, a super admin can delete a task category, edit a team, and add a team member on any hotel, but cannot list, create, or edit tasks/task-categories, or create/list teams.

---

## Error Shapes

| Situation | HTTP | Shape |
| --- | --- | --- |
| Field-level validation failure (missing/wrong-type field, bad enum value, failed `exists` check) | `422` | Laravel default: `{"message": "The given data was invalid.", "errors": {"field": ["..."]}}` |
| Duplicate team name | `422` | Same Laravel shape as above, under `errors.name` |
| "Only employees can be added to a team" | `422` | **Custom wrapper**, no `errors` key: `{"message": "...", "code": 422, "body": null}` |
| Cross-hotel ownership failures (room/guest/team/user/category not belonging to caller) | `403` | Custom wrapper |
| No associated hotel | `403` | Custom wrapper: `{"message": "You do not belong to any hotel.", ...}` |
| Wrong role, or record belongs to a different hotel (authorization failure) | `403` | Laravel default: `{"message": "This action is unauthorized."}` |
| Unknown id | `404` | Laravel default: `{"message": "No query results for model [App\\Models\\{Team|TaskCategory|Task}] {id}"}` |
| Unknown `filter`/`sort` column | `422` | Laravel default, same shape as field validation |

Always check for an `errors` key to distinguish Laravel's native validation shape from this API's custom `message/code/body` wrapper — both can appear with `422`.

## Example Flow (cURL)

```bash
# 1. Create a team
curl -X POST http://your-domain.com/api/team \
  -H "Accept: application/json" -H "Content-Type: application/json" \
  -H "X-API-KEY: YOUR_API_KEY" -H "Authorization: Bearer USER_LOGIN_TOKEN" \
  -d '{ "hotel_id": "YOUR_HOTEL_ID", "name": "Housekeeping" }'

# 2. Add an employee to that team
curl -X POST http://your-domain.com/api/team/TEAM_ID/members \
  -H "Accept: application/json" -H "Content-Type: application/json" \
  -H "X-API-KEY: YOUR_API_KEY" -H "Authorization: Bearer USER_LOGIN_TOKEN" \
  -d '{ "user_id": "EMPLOYEE_USER_ID" }'

# 3. Create a category owned by that team
curl -X POST http://your-domain.com/api/task-category \
  -H "Accept: application/json" -H "Content-Type: application/json" \
  -H "X-API-KEY: YOUR_API_KEY" -H "Authorization: Bearer USER_LOGIN_TOKEN" \
  -d '{ "hotel_id": "YOUR_HOTEL_ID", "team_id": "TEAM_ID", "name": "Cleaning" }'

# 4. Create a task for that team + category
curl -X POST http://your-domain.com/api/task \
  -H "Accept: application/json" -H "Content-Type: application/json" \
  -H "X-API-KEY: YOUR_API_KEY" -H "Authorization: Bearer USER_LOGIN_TOKEN" \
  -d '{
    "hotel_id": "YOUR_HOTEL_ID",
    "title": "Fix the AC in room 204",
    "assigned_to_team_id": "TEAM_ID",
    "task_category_id": "CATEGORY_ID"
  }'
```

## Summary for the Frontend

- Every request needs `X-API-KEY` and `Authorization: Bearer {login_token}`.
- `hotel_id` is required in the request body on every Team/TaskCategory/Task create call (send the caller's own hotel id), but it is **always overwritten server-side** — you can never create or move a record into a different hotel through these endpoints.
- Team membership is single (`team_id` on the user), managed only via `POST /api/team/{id}/members`, and restricted to users with `role: employee`.
- Filter the task-category picker by the chosen team (`filter[team_id]=`) before letting the user pick a category on a task — the backend rejects team/category combinations that don't match.
- `GET /api/task-category/{id}` does not work — don't build a detail fetch against it.
- Task's `status`/`priority`/`created_by` are real server-side enums (see [The Task Object](#the-task-object) for the fixed value lists) — safe to drive a `<select>` directly from them.
- None of Task's relation ids (`assigned_to_team_id`, `assigned_to_user_id`, `task_category_id`, `room_id`, `guest_id`, `reservation_id`, `created_by_user_id`) come back with the related object embedded — resolve names from data you already have.
- Task deletion is soft (`deleted_at`); Team and TaskCategory deletion is permanent.
- **Don't show a guest picker on the task form.** `guest_id` is never client-settable — it's always derived server-side from `reservation_id` (the reservation's own guest). Only expose a reservation picker; the guest shows up as a side effect.
- A cross-hotel ownership `403` names a *relation* (`rooms`, `teams`, `users`, `taskCategories`, `guests`, `reservations`), not a specific form field — show it as a form-level error, not tied to one input.
- **Breaking change:** `GET /api/task`'s `body` is now `{ data: <paginator>, task_categories: [...] }` instead of being the paginator directly — the task list moved to `body.data.data` and pagination fields moved to `body.data.*`. `task_categories` is hotel-scoped the same way `data` is, and is safe to use for a category picker. See [§3.1](#31-list-tasks).
