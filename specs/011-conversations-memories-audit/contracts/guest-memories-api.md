# Contract: Guest Memories API (SPEC-084)

Same middleware stack and `{ message, code, body }` envelope as every tenant route.
Memories are written only by extraction, so there are no create or update routes (FR-021).

## List one guest's memories — `GET /api/guest/{guest}/memories`

**Authorization**: `GuestMemoryPolicy::viewAny`. It requires
`allows($user, Permission::GUEST_MEMORIES_VIEW, $guest)`, which is the permission plus the
same hotel. Another hotel's guest → `404`, via route-model binding through the tenant
scope.

| Param | Notes |
| --- | --- |
| `category` | Optional: `preference`, `communication`, `interest`, `other` |
| `per_page`, `page` | Default 20 (the cap), max 50 |

Sort: `extracted_at` desc.

Item (`GuestMemoryResource`):

```json
{
  "id": "uuid",
  "category": "preference",
  "content": "Prefers feather-free pillows.",
  "language": "en",
  "confidence": "high",
  "source_stay": { "id": "uuid", "arrival_date": "2026-06-10", "departure_date": "2026-06-14" },
  "extracted_at": "2026-06-15T03:10:00+03:00"
}
```

The response also carries `guest_memories_enabled` (the hotel switch), so the panel can
show "memories are switched off for this hotel".

**Access event (FR-029)**: every call records `guest.memories_viewed` (subject: the
guest).

## Delete one memory — `DELETE /api/guest/{guest}/memories/{memory}`

**Authorization**: `allows($user, Permission::GUEST_MEMORIES_DELETE, $memory)`. The memory
must belong to `{guest}`; otherwise `404`.

The delete is permanent. `RecordsEvents` writes `guest_memory.deleted` with no content. The
Concierge stops receiving the memory on its next turn.

`200` `{ "message": "Guest memory deleted successfully.", "code": 200, "body": null }`

## Hotel switch — `PUT /api/hotel/{hotel}` (extended)

This is admin-only, like every other hotel setting. It adds one optional field:

```json
{ "guest_memories_enabled": false }
```

- Validation: `boolean`.
- `HotelResource` returns `guest_memories_enabled`.
- A change records `hotel.guest_memories_toggled` with `{from, to}`.
- While it is `false`: no extraction runs (recorded as `skipped_disabled`), and the
  Concierge receives no memories. The list and delete endpoints keep working.

## Super admin: re-run an extraction — `POST /api/admin/guest-memory-extractions/{extraction}/rerun`

This route is in `routes/admin.php`, so the `super_admin` guard applies to it.
- Allowed only when the status is `failed` or `skipped_spend_ceiling`. Any other status →
  `422` with the status in the message.
- It sets `attempts` to 1 (so a failing re-run is final at once), sets the status to `pending_retry` with `retry_at = now`, and
  dispatches the job, which claims it through its normal path.
- `202` `{ "message": "Extraction re-run queued.", "code": 202, "body": { "id": "uuid", "status": "pending_retry" } }`

## Super admin: list extractions — `GET /api/admin/guest-memory-extractions`

- Filters: `hotel_id`, `status`.
- Paged, newest first.
- Lets operators find final failures to re-run. This is also in `routes/admin.php`.

## Errors

| Case | Code |
| --- | --- |
| Missing permission | 403 |
| Guest or memory of another hotel / memory not the guest's | 404 |
| Re-run of a non-rerunnable status | 422 |
