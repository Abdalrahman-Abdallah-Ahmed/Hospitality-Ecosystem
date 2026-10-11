# Frontend Changes: Conversation Visibility, Guest Memories, AI Audit (Phase 11)

For `ecosystem-frontend` (D13). The backend is on branch `011-conversations-memories-audit`.
API details are in the linked docs; this lists the screens and the rules each must follow.

## 1. Conversation viewer (new screen)

API: [docs/conversations-api-documentation.md](../../docs/conversations-api-documentation.md)

- **List**: `GET /api/conversations`, with the guest name and phone, message count, and last
  activity, newest first, paged at 25.
  - Filters: guest (picker → `guest_id`), last-active date range (`last_active_from` /
    `last_active_to`, hotel-local `Y-m-d`).
  - **No search box**: search is post-MVP and the API ignores it.
- **Thread**: `GET /api/conversations/{id}`. Messages are oldest first; load more with
  `page`.
  - Guest bubbles on one side, Concierge bubbles on the other.
  - A **"Sent by the hotel"** badge when `proactive: true`.
  - Attachments show as type and name chips only; there is no download.
- **Menu visibility**: show the menu item only when the user's permissions include
  `conversations.view` (admins always have it). Super admins pick a hotel first
  (`hotel_id`).
- **Errors**: `404` → "Conversation not found"; `403` → hide the screen.

## 2. Guest page

- **"Conversations" tab**: `GET /api/conversations?guest_id={guest}`; clicking one opens the
  thread view.
- **"What we remember" panel**
  ([docs/guest-memories-api-documentation.md](../../docs/guest-memories-api-documentation.md)):
  - `GET /api/guest/{id}/memories`, filterable by category chips (preference, communication,
    interest, other).
  - Each row shows the content (`dir="rtl"` when `language` is `ar`), a category badge, a
    confidence badge (medium/high), the source stay dates, and when it was noted.
  - **Delete** button (confirm dialog) → `DELETE /api/guest/{id}/memories/{memory}`. Show it
    only with `guest_memories.delete`.
  - When `guest_memories_enabled` is `false`, show the notice "Guest memories are switched off
    for this hotel. Existing notes are kept but not used."
  - Show the panel only with `guest_memories.view`. There is no add or edit: memories come
    only from extraction.
- **Escalation tasks** titled "Memory request — …" come from guests asking about their
  memories. They can link to this panel.

## 3. AI activity (new screen)

API: [docs/ai-activity-api-documentation.md](../../docs/ai-activity-api-documentation.md)

- `GET /api/ai-activity`. Tabs or segmented control: **All / Changes / Guest outreach / Runs**
  (`category`).
- **Filters**: agent, result (succeeded / refused / failed), date range, conversation, and a
  "this turn" link that sets `ai_run_id`.
- **Row content**:
  - agent, action, target (linking to `target.history_url` when not null), who it acted for,
    and the time;
  - the `reason` for refused or failed rows;
  - for runs, the usage (requests and tokens).
- **Cost**: show a `cost_usd` column **only if the field is present** (super admins). Never
  compute or show cost otherwise.
- **Visibility**: show the screen with `history.view`.

## 4. Hotel settings

- New toggle **"Remember returning guests"** → `guest_memories_enabled` on
  `PUT /api/hotel/{hotel}`, on by default.
- Help text: "After check-out, the assistant keeps a few short notes (preferences, interests)
  from what the guest said, and uses them next time. You can review and delete them on the
  guest's page."

## 5. Staff roles

The three new permissions, `conversations.view`, `guest_memories.view` and
`guest_memories.delete`, come from `GET /api/permissions` automatically. Group them under
"Guests / Conversations" in the role editor if it groups by resource.

## 6. AI advisor chat

No UI change. The advisor no longer answers "what did guests write?" with message text; it
points to the conversation viewer. Consider a deep link in its reply rendering if one is
added later.

## 7. Super admin

An optional operator view of `GET /api/admin/guest-memory-extractions` (status filter), with
a **Re-run** action on `failed` / `skipped_spend_ceiling` rows. It can wait.
