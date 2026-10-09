# Latest Changes: 2026-10-09

## Admin AI PMS Tools (SPEC-055, Phase 9)

### Summary

The admin advisor can now read and run the whole hotel through tools, not just today's arrivals and a few create actions:

- **Reads:** guests and their history, reservations for any dates, one reservation in full, room types, rooms by status, availability, stays, tasks, the housekeeping board, maintenance, activities, activity bookings, reports (dashboard, occupancy per night, recommendation conversion, AI insights, AI usage), staff and staff roles, and hotel settings.
- **Writes:** update guests, change and cancel reservations, assign rooms, check in and out, update tasks, set housekeeping status, take rooms out of order and back, report room issues, book activities, change booking status, answer guest cancellation requests, and save or correct the hotel's knowledge base articles.

Every tool checks the acting admin's permission each time it runs (the same permission as the matching staff endpoint), works only inside their hotel, and goes through the same service as the staff screen, with the same rules and messages. Reads return at most 50 records with the total.

Hard-to-reverse actions wait for the admin's confirmation: cancel a reservation, remove rooms from a reservation, check out, put a room out of order, cancel a booking, approve a guest's cancellation request. Only the admin's next message can confirm, within 10 minutes; anything else drops them. Up to 10 actions can be confirmed at once.

The advisor never deletes, never changes users, roles, permissions or settings, never writes policies, documents or global knowledge, never shows AI costs or ledger figures, and never overrides overbooking or activity capacity.

### API changes (additive, no breaking change)

`POST /api/ai-advisor/chat`:

- New optional request fields `decision` (`confirm` | `decline`) and `pending_ids`.
- `message` is now optional when `decision` is sent; `conversation_id` is required with `decision`.
- New response field `body.pending_confirmation` (`null` unless actions are waiting): `ids`, `items[{id, tool, summary}]`, `expires_at`, `locale`.
- New statuses: `409` when `pending_ids` do not match what is waiting, or another message in the same conversation is still being answered; `422` "There is nothing waiting for confirmation." when a `decision` is sent with nothing waiting.

Clients that send only `message` and read only `reply` keep working: `reply` contains the list of actions and the YES/NO question. See [AI Advisor Chat API Documentation](/D:/Hospitality%20Ecosystem/docs/ai-advisor-chat-api-documentation.md).

### WhatsApp

Admins on WhatsApp now talk to the advisor in a WhatsApp conversation of its own (titled "WhatsApp"), so a WhatsApp "yes" can never confirm actions waiting in the web chat. Their first WhatsApp message after this release starts that conversation fresh.

### Audit trail

- AI writes made on WhatsApp now record the admin as the actor. They used to record no human, because no one is signed in to a queue job.
- Every AI write carries `context.ai = {agent, tool, tool_call_id, conversation_id}` in `event_logs`.
- Activities and knowledge base articles are now audited like other records (`activity.*`, `knowledge_base_article.*` events). This applies to staff edits too, not just the AI.

### Internal changes (no API change)

Business rules that lived in controllers moved, unchanged, into services the staff endpoints and the AI tools share:

| Service | From |
| --- | --- |
| `App\Services\Guests\GuestRegistrar` | `GuestController` store/update |
| `App\Services\Reservations\ReservationCommands` | the update path of `ReservationController` |
| `App\Services\Tasks\TaskCommands` | `TaskController` store/update and its guards |
| `BookingService::takeStaffBooking`, `::reservationFor` | `BookingController` store |
| `App\Services\Knowledge\ArticleCommands` | `KnowledgeBaseArticleController` store/update |
| `App\Services\Reports\{DashboardSummary, ConversionReport, HousekeepingBoard, MaintenanceList}` | the dashboard, conversion, housekeeping board and maintenance controllers |
| `MaintenanceService::issueRefusal` | `TaskIssueController` |

Every response, message and status code is the same as before.

### Not in this release

- Marking a reservation as a no-show. The reservation `no_show` status (SPEC-012) does not exist yet; the advisor gets a tool for it when it does.
- `children_ages` on reservations (decision D8) is not built yet, so the advisor changes `adults` and `children` only.

### Frontend

Render `body.pending_confirmation.items` as a Confirm / Cancel card with a countdown to `expires_at`. The buttons send `decision` and `pending_ids`. See `specs/009-admin-ai-pms-tools/frontend-changes.md`.
