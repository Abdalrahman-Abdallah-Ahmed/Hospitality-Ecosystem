# Latest Backend Changes — 2026-08-21

Summary of everything new/changed on the backend since the last handoff (`latest-changes-2026-08-08.md`). Two things: one new endpoint, and one breaking response-shape change that hits two existing list endpoints at once.

## 1. New: Bulk Reservation Import

`POST /api/reservation/import` — admins (not super admins, see below) can upload an `.xlsx`/`.xls`/`.csv`/`.txt` file to bulk-create reservations, matching/creating guests by phone number and auto-creating rooms referenced by a `room_number` that doesn't exist yet.

**Full doc:** [Reservations API Documentation § 6](/D:/Hospitality%20Ecosystem/docs/reservations-api-documentation.md#6-import-reservations-bulk-upload)

Key points for the frontend:

- Send as `multipart/form-data` with a single `file` field — don't set `Content-Type: application/json`.
- The response is just counts, **not** the created reservations: `{ imported: number, skipped: [{ row, reason }] }`. Re-fetch `GET /api/reservation` to show the new rows.
- Bad rows are skipped individually, not fatal to the whole upload — `skipped[].reason` is sometimes a friendly message, sometimes a raw PHP error string (e.g. for an invalid `status` value). Display it as opaque text.
- **A super admin cannot use this endpoint at all** — it always imports into `$request->user()->hotel`, and super admins have no hotel of their own, so they get a `403 You do not belong to any hotel.` This is unlike every other write endpoint in the API, which lets a super admin pass an explicit `hotel_id`.
- Expected column headers and per-column behavior are fully listed in the linked doc — worth reading before building the upload UI/template, especially the `status` and `reservation_id` gotchas.

## 2. Breaking Change: `index` Response Shape for Rooms and Tasks

`GET /api/room` and `GET /api/task` no longer return the paginator directly in `body`. Both now return:

```json
{
  "body": {
    "data": { "current_page": 1, "data": [ "...the actual rows..." ], "last_page": 1, "total": 1, "...": "rest of paginator fields" },
    "room_types": [ "...only on GET /api/room..." ],
    "task_categories": [ "...only on GET /api/task..." ]
  }
}
```

**What changed for the frontend:**

- The row array moved from `body.data` to **`body.data.data`** (one level deeper).
- Pagination fields (`current_page`, `last_page`, `total`, `next_page_url`, etc.) moved from `body.*` to **`body.data.*`**.
- **Every other endpoint is unaffected** — `store`/`show`/`update`/`destroy` on both Room and Task, and all of `GET /api/reservation`, `GET /api/team`, `GET /api/task-category`, `GET /api/guest`, etc. still return their paginator the old way (`body` *is* the paginator, list at `body.data`). This change is scoped to just these two `index` actions.
- `GET /api/room`'s new `body.room_types` is a genuinely useful addition: the authoritative list of valid `room_type` enum values (`{ name, value }` pairs) — use it instead of hard-coding a dropdown. It pairs with another change below.
- `GET /api/task`'s new `body.task_categories` is hotel-scoped the same way `body.data` is (a regular admin only sees their own hotel's categories) — safe to use for a category picker, saving a separate `GET /api/task-category` call when rendering the task list/board.

**Full doc updates:** [Room API § List Rooms](/D:/Hospitality%20Ecosystem/docs/room-api-documentation.md#1-list-rooms) · [Task Management API § 3.1](/D:/Hospitality%20Ecosystem/docs/task-management-api-documentation.md#31-list-tasks)

## 3. Related Change: `room_type` Is Now a Real Enum

Bundled with the above — `rooms.room_type` is now validated server-side against `App\Enums\RoomTypes`: `single`, `double`, `twin`, `triple`, `suite`, `deluxe`, `family`. Previously any string was accepted on `POST /api/room` / `PUT /api/room/{id}`.

**What changed for the frontend:**

- If your create/edit room form free-types `room_type`, switch it to a `<select>` sourced from `GET /api/room`'s new `body.room_types` (see above) — a value outside the seven now returns a `422` on `room_type` where it previously silently saved.
- `room` objects in every response (list, show, create, update) still serialize `room_type` as a plain string (e.g. `"double"`), so nothing changes about how you *read* an existing room's type — only what's accepted on write.

**Full doc update:** [Room API Documentation § The Room Object](/D:/Hospitality%20Ecosystem/docs/room-api-documentation.md#the-room-object)

## Checklist for the FE Agent

- [ ] Build the bulk-import UI against `POST /api/reservation/import` — `multipart/form-data`, one `file` field, show `imported`/`skipped` counts, refresh the reservations list after a successful upload.
- [ ] Update the rooms list screen: read rows from `body.data.data` (was `body.data`), pagination from `body.data.*` (was `body.*`).
- [ ] Update the tasks list/board screen: same `body.data.data` / `body.data.*` shift.
- [ ] If the room form has a free-text `room_type` input, replace it with a `<select>` populated from `body.room_types` on the rooms list response.
- [ ] `GET /api/task`'s bundled `body.task_categories` is hotel-scoped and safe to use for a category picker on the task list/board screen.
- [ ] Do not build against a super-admin "import for another hotel" flow — the backend doesn't support it on this endpoint yet.
