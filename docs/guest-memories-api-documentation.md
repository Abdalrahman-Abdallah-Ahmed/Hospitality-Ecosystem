# Guest Memories API Documentation

*Added 2026-10-10 (SPEC-084).* After a guest checks out, the system notes a few lasting facts
about them from what they told the WhatsApp Concierge — "vegetarian", "prefers messages in
the evening", "loved the sunset camel ride" — so the Concierge can welcome them back. These
are **guest memories**. This page covers how they are made, how the Concierge uses them, and
the endpoints to review and delete them.

## Base URL and Headers

Same as every tenant endpoint: `X-API-KEY`, `Authorization: Bearer <token>`, `Accept: application/json`.
Responses use `{ message, code, body }`.

## How Memories Are Made

- **When.** Once per guest per reservation, after the guest's **last** room on the
  reservation checks out (any check-out path: staff, Admin AI, whole reservation). Only
  check-outs from 2026-10-10 onward; earlier stays are never processed.
- **From what.** Only what the guest and the Concierge said between check-in and check-out.
  Bookings, requests and other records are not read: the Concierge already has them.
- **What is kept.** Lasting preferences, contact preferences, recurring interests, and needs
  the guest stated (category `preference`, `communication`, `interest` or `other`). One short
  sentence each, in the language the guest used (`en` or `ar`).
- **What is never kept**, whatever the AI proposes: temporary requests, booking facts (dates,
  rooms, prices, statuses), anything with an email address, an identity-document-like number
  or any run of 9+ digits (cards, phones, IDs), and health details beyond a stated
  accessibility need. Facts below medium confidence are dropped.
- **Updates.** A fact the guest repeats refreshes the existing memory (newer source stay,
  confidence never lowered); a fact that contradicts one replaces it, in either language.
- **Limit.** At most 20 per guest per hotel; past that, the least confident and oldest go
  first.
- **Skipped** (recorded, no AI call): the guest said nothing during the stay
  (`skipped_no_messages`), or the hotel has memories switched off (`skipped_disabled`).
- **Retries.** If the AI fails after three quick attempts, or the account's daily AI spend
  ceiling stops it, the extraction gets **one** more try about 24 hours later; after that the
  result is final (`failed` or `skipped_spend_ceiling`). A super admin can re-run a final one
  by hand ([below](#super-admin-re-run-an-extraction)).

## How the Concierge Uses Them

- The Concierge reads the guest's memories **at this hotel only** as hints. Live records
  (reservation, bookings, availability) always win.
- It never lists or quotes them, or mentions that it keeps notes.
- If a guest asks what the Concierge remembers, or asks it to forget or correct something,
  the Concierge says the hotel will take care of it and raises a staff escalation titled
  **"Memory request — see memories"** or **"Memory request — forget: …"**. It stops using
  that note in the conversation but deletes nothing itself: staff delete the memory here.

## The Hotel Switch

`guest_memories_enabled` on the [hotel](hotel-api-documentation.md), **on by default**. While
off, no memories are extracted (those check-outs are skipped for good) and the Concierge gets
none. Existing memories stay, and can still be listed and deleted. Changing it is admin-only
and recorded as `hotel.guest_memories_toggled`.

## Who Can Call This

| Endpoint | Permission |
| --- | --- |
| List a guest's memories | `guest_memories.view` |
| Delete a memory | `guest_memories.delete` |

Admins hold both. Employees get them only through a [staff role](staff-roles-api-documentation.md);
employees without a role never do. Super admins can read any hotel's.

## List a Guest's Memories

`GET /api/guest/{guest}/memories`

| Param | Notes |
| --- | --- |
| `category` | Optional: `preference`, `communication`, `interest`, `other` |
| `per_page`, `page` | Default 20, max 50 |

Newest first.

```json
{
  "message": "Guest memories fetched successfully.",
  "code": 200,
  "body": {
    "guest_memories_enabled": true,
    "memories": {
      "data": [
        {
          "id": "uuid",
          "category": "preference",
          "content": "Prefers feather-free pillows.",
          "language": "en",
          "confidence": "high",
          "source_stay": { "id": "uuid", "arrival_date": "2026-06-10", "departure_date": "2026-06-14" },
          "extracted_at": "2026-06-15T00:10:00+00:00"
        }
      ],
      "links": { "…": "…" },
      "meta": { "current_page": 1, "per_page": 20, "total": 1, "last_page": 1 }
    }
  }
}
```

`confidence` is `medium` or `high`. `source_stay` and `extracted_at` can be `null`. There is
no `hotel_id` parameter: the guest id decides the hotel, and a super admin can read any hotel's
guest. Every call records `guest.memories_viewed` in the guest's
[history](history-api-documentation.md), without any memory text.

## Delete a Memory

`DELETE /api/guest/{guest}/memories/{memory}`

Permanent. Recorded as `guest_memory.deleted` without the memory's text. The Concierge stops
using it on its next reply.

```json
{ "message": "Guest memory deleted successfully.", "code": 200, "body": null }
```

There is no way to create or edit a memory by hand (`POST`/`PUT` → `405`).

**Deleting the guest** (`DELETE /api/guest/{guest}`) deletes all of their memories at the same
time. Restoring the guest does not bring them back.

## Super Admin: List Extractions

`GET /api/admin/guest-memory-extractions` — filters `hotel_id`, `status`; newest first; paged.

```json
{
  "id": "uuid",
  "hotel_id": "uuid",
  "guest_id": "uuid",
  "reservation_id": "uuid",
  "status": "failed",
  "attempts": 2,
  "retry_at": null,
  "memories_written": 0,
  "ai_run_id": "uuid",
  "last_error": "RuntimeException",
  "updated_at": "…"
}
```

`status`: `running`, `pending_retry`, `extracted`, `skipped_no_messages`, `skipped_disabled`,
`skipped_spend_ceiling`, `failed`.

## Super Admin: Re-run an Extraction

`POST /api/admin/guest-memory-extractions/{id}/rerun`

Only for `failed` or `skipped_spend_ceiling` (otherwise `422`). Queues one more attempt; if it
fails again it is final, with no further automatic retry.

```json
{ "message": "Extraction re-run queued.", "code": 202, "body": { "id": "uuid", "status": "pending_retry", "…": "…" } }
```

## Errors

| Case | Code |
| --- | --- |
| Missing permission | 403 |
| Another hotel's guest or memory, or a memory of a different guest | 404 |
| `POST`/`PUT` on memories | 405 |
| Re-run of an extraction that did not fail or hit the ceiling | 422 |
