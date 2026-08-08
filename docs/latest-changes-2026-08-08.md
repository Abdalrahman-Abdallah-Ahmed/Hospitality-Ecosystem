# Latest Backend Changes — 2026-08-08

Summary of everything new/changed on the backend since the last handoff (`Add AI-powered hotel insights API` / the AI Insights doc). Three things: one new endpoint, one new side effect on an existing endpoint, and one breaking field change on an existing endpoint.

## 1. New: Dashboard Summary Endpoint

`GET /api/dashboard` — a single call returning pending/in-progress task counts, today's arrivals and departures (full reservation objects, not just ids), and a live room-occupancy percentage. Open to any authenticated hotel user, not just admins.

**Full doc:** [Dashboard API Documentation](/D:/Hospitality%20Ecosystem/docs/dashboard-api-documentation.md)

If you're building a dashboard/home screen, this replaces having to make 3+ separate calls (tasks list + reservations list + rooms list) and computing the counts client-side.

## 2. Behavior Change: Confirming a Reservation Now Occupies Its Room

`POST /api/reservation`, `POST /api/whatsapp-reservation`, and `PUT /api/reservation/{id}` now have a side effect: if the reservation ends up with `status: confirmed` **and** a non-null `room_id`, the backend automatically sets that room's `status` to `occupied`.

**What changed for the frontend:**
- You no longer need to make a separate `PUT /api/room/{id}` call to mark a room occupied when confirming a booking — it happens automatically as part of the reservation call.
- **This is one-directional as of now** — cancelling or checking out a reservation does **not** free the room back to `available`. If your UI has a "release room" concept, you still need to call the room API for that explicitly; don't assume it self-corrects.
- Affects `GET /api/room` responses (a room's `status` can now change as a side effect of reservation actions, not just direct room edits) and the new [Dashboard endpoint](#1-new-dashboard-summary-endpoint)'s `occupancy_percentage`.

**Full doc update:** [Reservations API Documentation § Status Values](/D:/Hospitality%20Ecosystem/docs/reservations-api-documentation.md#status-values)

## 3. Breaking Change: `created_by_user_id` Is Now Ignored on Task Create

`POST /api/task` **no longer accepts a client-supplied `created_by_user_id`.** It's silently discarded and always forced to the authenticated caller's own user id, regardless of what you send.

**What changed for the frontend:**
- **If your create-task form sends `created_by_user_id`, stop** — it has no effect now, and the response will always show the id of whoever's logged in and made the call. Remove any "created by" picker from the create form, or repurpose it as read-only display after creation.
- **`PUT /api/task/{id}` (update) is unaffected** — it still accepts and validates a client-supplied `created_by_user_id` the same as before (hotel-ownership checked, same as `assigned_to_user_id`). This asymmetry between create and update is current backend behavior, not a bug in this doc — don't share the same validation/field config between your create and edit task forms for this field.

**Full doc update:** [Task Management API Documentation § Create a Task](/D:/Hospitality%20Ecosystem/docs/task-management-api-documentation.md#32-create-a-task) *(see the `created_by_user_id` row in the validation table, and the [update-section caveat](/D:/Hospitality%20Ecosystem/docs/task-management-api-documentation.md#34-update-a-task) right after it)*

## Checklist for the FE Agent

- [ ] Wire up `GET /api/dashboard` for the dashboard/home screen — see full doc for response shape.
- [ ] If there's an existing "mark room occupied" manual action tied to confirming a reservation, it can likely be removed/simplified — the backend does it now. Keep any "release room" action, since that part is still manual.
- [ ] Remove/hide any `created_by_user_id` input on the **create task** form; leave it as-is on the **edit task** form.
- [ ] If an occupancy widget already exists elsewhere, cross-check it against the new `occupancy_percentage` field for consistency — both read the same live `rooms.status` data, just from different endpoints.
