# AI Hospitality Ecosystem — Master Implementation Plan v1.0

**Date:** 2026-09-22
**Governed by:** `.specify/memory/constitution.md` (v2.0.0)
**Supersedes:** `docs/PGRIP_Ecosystem_Phase2_Implementation_Plan_v1_1.md` (unfinished SaaS
work packages — plans, subscriptions, enforcement — are deferred post-MVP; metering and AI
cost attribution already shipped and are kept).

This is the roadmap that sits between the constitution and individual Spec-Kit
specifications. It defines the target domain, the gap from today's code, the phase order,
dependencies, and the boundaries each `/speckit-specify` request should follow. It is not a
specification itself.

Strategy: **preserve and evolve.** Tenancy, permissions, reservations, stays, tasks,
activities, bookings, AI agents, WhatsApp, recommendations/pitching, RAG, insights, metering
and AI cost attribution already exist. We change what the target model needs and keep the
rest.

---

## 1. Decisions log

Settled during the plan review on 2026-09-22. Specs must follow these; changing one means
updating this plan first.

| # | Topic | Decision |
| --- | --- | --- |
| D1 | Room types | New per-hotel `room_types` table. Migration backfills one RoomType per hotel for each `RoomTypes` enum value in use, sets `rooms.room_type_id`, then retires the enum column. |
| D2 | Reservation rooms | One `reservation_rooms` row **per room unit** ("2 × Deluxe" = 2 rows), each with `room_type_id` and a nullable `room_id` filled at assignment. |
| D3 | Stays | **One Stay per reservation room.** The unique `stays.reservation_id` constraint is replaced by uniqueness on the reservation room. |
| D4 | Check-in/out API | Dedicated endpoints (per room and whole reservation) with prerequisite validation. Setting `checked_in`/`checked_out` through `PUT /reservation/{id}` is deprecated, then rejected. |
| D5 | Status model | `RoomStatus`: `available` / `occupied` / `out_of_order` (replaces `maintenance`). `HousekeepingStatus`: `dirty` / `cleaning` / `clean` / `inspected`. Existing `blocked` rows migrate to room status `out_of_order`. |
| D6 | Buildings | `rooms.building` nullable string, like `floor`. No Building entity for MVP. |
| D7 | Default teams | Every hotel gets **Housekeeping** and **Maintenance** teams with default task categories, created on hotel creation and backfilled for existing hotels. |
| D8 | Party composition | On the reservation: `adults`, `children`, `children_ages` (JSON int array; length must equal `children`). Totals across all rooms. |
| D9 | Recommendation approval | All recommendations start `pending_approval`. The Concierge pitches (reactive and proactive) **only from approved** ones. Inline generation (`PitchRecommendationGenerator`) is kept but creates pending records for review instead of pitching them. |
| D10 | Decline retry | After a decline, at most **one** different approved activity may be pitched within 24 h of the first pitch; after that the stay is closed to pitching. Replaces the current `DECLINED_THIS_STAY` behavior. |
| D11 | Transactions | Isolated behind a config/per-hotel feature flag, **off by default**: routes and UI hidden, MVP flows stop writing to the ledger. Code and tables kept. |
| D12 | WhatsApp number | **One shared platform number** (current). Guest resolution keeps the cross-hotel order: in-house → upcoming → latest past stay → newest guest. |
| D13 | Frontend | No separate frontend phase. Each phase lists its frontend slice; specs live in this repo, frontend tasks run in `ecosystem-frontend`. |
| D14 | Phase 0 | The gap analysis is folded into §2 of this plan. Phase 0 shrinks to a baseline test run and a frontend inventory. |
| D15 | Conversations | Keep the `laravel/ai` conversation tables for MVP. **Conversation search is post-MVP** (constitution amended to v2.0.0). Admin-only visibility stays in MVP. |
| D16 | Import | Laravel `ReservationsImport` is the only MVP import path; normalization moves into Laravel. The Python ETL repo is reference only. |
| D17 | Admin AI scope | Admin AI **reads** users, staff roles, permissions and hotel settings but cannot mutate them in MVP. PMS operations are writable, subject to permissions. |
| D18 | Reservation voucher | A PDF **voucher** (proof of reservation), **not an invoice**. Built from a saved reservation, with optional per-request overrides of the displayed fields that never change the reservation. Rendered **on the fly**, not stored; each generation or email is an audit entry. Prices are hidden by default, and staff can choose to show the total. English or Arabic from the guest's language (RTL for Arabic) with hotel branding. Staff download it and can optionally email it to the guest. Built in Phase 2, after the restructure. |

---

## 2. Current state and gaps

KEEP = works as target. MODIFY = exists, needs change. ADD = missing. DEFER = post-MVP.

### 2.1 Tenancy, auth, permissions — KEEP

`BelongsToHotel` + `TenantContext`, `Permission` enum, `StaffRole`, `ChecksPermissions`,
admin bypass, `routes/admin.php` super-admin stack, Sanctum with 7-day tokens. Every new
resource adds its permission cases (CLAUDE.md procedure).

### 2.2 Rooms — MODIFY / ADD

| Today | Target | Action |
| --- | --- | --- |
| `rooms.room_type` enum string (`RoomTypes`) | `room_types` table + `rooms.room_type_id` | ADD model, CRUD, permissions; backfill (D1) |
| No building, notes | `building`, `notes` | ADD columns (D6) |
| `RoomStatusesEnum` available/occupied/maintenance | available/occupied/out_of_order | MODIFY (D5) |
| `HousekeepingStatusesEnum` clean/dirty/blocked | dirty/cleaning/clean/inspected | MODIFY; migrate `blocked` (D5) |
| `ReservationCreator::syncRoomOccupancy` derives occupancy from reservations | Occupancy from in-house stays per room | MODIFY in Phase 4 |
| `MakeRoomDirtyOvernightJob` skips `blocked` | Skips `out_of_order` | MODIFY with D5 |
| `CreateRoomTool`, `GetRoomsTool` | Use room types and new statuses | MODIFY |

### 2.3 Guests — KEEP (verify)

Guest already has name, email, phone, language, nationality, preferences, loyalty, VIP,
external_id, channel, contact timestamps. `GuestIdentityService` matches per hotel; the
WhatsApp path matches by phone digits across hotels (D12). No structural change planned.

### 2.4 Reservations — MODIFY (largest change)

| Today | Target | Action |
| --- | --- | --- |
| `reservations.room_id` → one physical room | `reservation_rooms` per unit, `room_id` nullable (D2) | ADD table; migrate each existing reservation to one row; retire `room_id` |
| `reservations.guest_id` → one guest | primary guest + additional guests | ADD `reservation_guests` (with `is_primary`); keep `guest_id` as the primary-guest pointer or migrate — spec decides |
| `adults`, `children` | + `children_ages` (D8) | ADD column |
| Status: pending/confirmed/checked_in/checked_out/cancelled | + `no_show`; each transition defined | MODIFY; lifecycle table in the spec |
| `ReservationCreator` shared by controller, AI tool, import | Same, extended for rooms/guests | MODIFY, do not duplicate |
| `reservation_id` column = external reference | Keep | KEEP |

### 2.5 Availability and assignment — ADD

No availability service, no assignment endpoints, no overlap protection beyond occupancy
syncing.

### 2.6 Stays — MODIFY

`Stay` and `StayService` (expected/in_house/departed/no_show/cancelled) exist and mirror the
reservation 1:1 (unique `reservation_id`). Target: one Stay per reservation room (D3),
created/advanced by the new check-in/out endpoints (D4). There are no check-in/out
endpoints today; the lifecycle is driven by `PUT /reservation` status.

### 2.7 Tasks, teams, housekeeping, maintenance — MODIFY / ADD

Task (room, reservation, guest, team, user, category, priority, due date, guest_signal),
Team, TaskCategory, and assignment notifications exist. Missing: default teams and
categories (D7), a housekeeping status lifecycle linked to tasks, checkout → dirty →
housekeeping task, maintenance → `out_of_order`, and a stay link on tasks.

### 2.8 Activities and bookings — MODIFY

Activity has category, price, active flag, availability window, operating hours,
unavailable periods, audience, `duration_days`, `daily_capacity`. There are no time slots.
Booking is generic and links guest, stay, activity and recommendation, with a status
lifecycle and `BookingService`. Missing: slot/capacity checks at booking time, guest
cancellation requests routed to staff, and slot modeling (spec decides whether hourly slots
are MVP).

### 2.9 WhatsApp — KEEP

Webhook, `wamid` dedupe (`whatsapp_inbound_messages`), queued processing with overlap lock,
sender recognition, guest/staff routing, device pairing, per-sender rate limit, guest spend
cap. Changes come only from domain changes (tools, identity data).

### 2.10 AI agents and tools — MODIFY

Agents: GuestConcierge, AdminAdvisor, Recommendation, Insights, TurnSignal (KEEP, no
orchestrator). There are 21 tools. Those touching reservations, rooms, tasks or bookings
must move to the new domain. Missing tools: availability, room assignment, check-in/out,
room-change request, cancellation request, and a knowledge-document search that covers
documents. AI writes use `ActorKind::AI_AGENT` through `EventLogger`; this is applied in
several places and must become consistent (Phase 11).

### 2.11 Knowledge / RAG — MODIFY / ADD

`KnowledgeBaseArticle` and `HotelPolicy` are chunked into `knowledge_chunks` (pgvector,
HNSW, Gemini embeddings, nullable `hotel_id` = global) by `SyncKnowledgeChunksJob`.
`KnowledgeSearchTool` returns hotel and global knowledge. The `knowledge_documents` table
exists with **no model or pipeline**. Missing: file upload, extraction (PDF, DOCX, TXT, MD,
images, Excel, CSV), hotel-over-global precedence, citations in answers, and super-admin
management of global knowledge.

### 2.12 Recommendations and pitching — MODIFY

Generation (job and inline), delivery, `PitchCoordinator` and gates, turn-signal
classifier, outcomes and conversion attribution exist. `RecommendationStatus` has no
approval states. Missing: approval workflow (D9) and the decline-retry rule (D10).

### 2.13 Conversations, memories, audit — KEEP / ADD / DEFER

Conversations are stored in `laravel/ai` tables (KEEP, D15). Search is DEFERRED.
Conversation visibility needs an explicit admin-only rule (ADD). Guest memories don't exist
(ADD). `EventLog` audit with actor kinds exists (KEEP, extend).

### 2.14 Insights, usage, cost — KEEP

Insights with evidence levels, append-only meter events, usage counters, effective-dated
model pricing, AI cost recorder, spend ceiling, super-admin usage and cost reports. Needs
verification only: hotel admins must never see provider cost.

### 2.15 Transactions — ISOLATE (D11)

Ledger, import, reversal and attribution exist. They go behind a flag that is off by
default.

### 2.16 Known open infrastructure items

These come from `docs/system-design-assessment-2026-09-19.md` §7:
- F9: Redis and split queues.
- F5: atomic release directories and a post-deploy health check.
- F11: queued imports, dashboard caching, error tracking.

All are scheduled in Phase 14.

### 2.17 Frontend (`ecosystem-frontend`, Next.js)

Modules exist for dashboard, hotels, rooms, guests, reservations, activities, activity
categories, bookings, tasks, task categories, teams, staff roles, users, knowledge base,
hotel policies, recommendations, AI advisor, reports, transactions, usage, AI cost,
settings, notifications. Missing: room types, availability/assignment, stays/check-in
board, housekeeping board, maintenance view, recommendation approval queue, conversations
viewer. The Phase 0 inventory confirms this list.

---

## 3. Phases

```text
Phase 0   Baseline
Phase 1   Inventory Foundation (room types, rooms, statuses, default teams, finance flag)
Phase 2   Reservation Restructure
Phase 3   Availability + Room Assignment
Phase 4   Stay Lifecycle + Check-in/out
Phase 5   Housekeeping + Maintenance
Phase 6   Activities + Bookings
Phase 7   Guest Services + Concierge on the new domain
Phase 8   Knowledge + RAG
Phase 9   Admin AI on the new domain
Phase 10  Recommendation Approval + Proactive Concierge
Phase 11  Conversation Visibility, Guest Memories, AI Audit
Phase 12  Insights + AI Economics verification
Phase 13  Import + Migration
Phase 14  Hardening + Production Readiness
```

Every phase ends with the app working, its migrations applied, Pest green, docs updated, and
its frontend slice delivered.

### Phase 0 — Baseline

- Run the Pest suite against Postgres and record failures.
- Run the frontend build and lint.
- Confirm migrations run from scratch plus seed.
- Smoke-test the WhatsApp webhook, queue worker and scheduled jobs locally.
- Write a frontend inventory: page → API calls → gaps, confirming §2.17.

**Output:** `docs/baseline-2026-09.md`. No domain changes.

### Phase 1 — Inventory Foundation

1. **Room types (D1):**
   - `room_types` with hotel_id, name, description, max_occupancy, adult_capacity,
     child_capacity, bed_configuration (json), amenities (json), base_price, currency,
     is_active.
   - CRUD API, policy, and `room_types.*` permissions.
   - Backfill migration, then `rooms.room_type_id` NOT NULL.
2. **Rooms:**
   - Add `building` and `notes`.
   - Require `room_number` + `room_type_id`.
   - Keep `room_number` unique per hotel.
3. **Status split (D5):**
   - New enum values and a data migration (`blocked` → `out_of_order`).
   - Update `MakeRoomDirtyOvernightJob`, the room tools and the dashboard counts.
4. **Default teams (D7):**
   - Housekeeping and Maintenance teams plus default categories, created by a hotel
     `created` hook.
   - A backfill migration/command for existing hotels.
   - Starting categories:
     - Housekeeping: cleaning, inspection, linen, amenities, room issue.
     - Maintenance: HVAC, plumbing, electrical, appliance, furniture, structural, other.
5. **Finance flag (D11):**
   - A `features.transactions` config/per-hotel flag, off by default.
   - Transaction routes return 404/403 when it is off.
   - MVP flows skip ledger writes.
6. **Guest verification:** Guest fields match §2.3. No change expected.

**Frontend:**
- Room Types module.
- Room form with room type, building and notes, plus both status badges.
- Transactions menu hidden when the flag is off.

**Specs:** SPEC-002 Room Types, SPEC-003 Physical Rooms & Status Split, SPEC-004 Default
Teams & Categories, SPEC-005 Transactions Feature Flag.

### Phase 2 — Reservation Restructure

1. `reservation_rooms` (D2): hotel_id, reservation_id, room_type_id, room_id (nullable),
   status.
2. Reservation guests: primary + additional, exactly one primary.
3. Add `children_ages` (D8) with validation.
4. Status lifecycle: add `no_show`. Define allowed transitions and who may trigger them.
5. Common dates: all rooms use the reservation's dates. No per-room dates.
6. `ReservationCreator`: accepts room-type lines and guests. It is shared by the API,
   `CreateReservationTool` and the import.
7. Data migration:
   - Each existing reservation → one `reservation_room` using its room's type and
     `room_id`.
   - Guest → primary.
8. API: the reservation create/update payload and resource change. This is a breaking
   change → `docs/latest-changes-<date>.md`.
9. Update the AI reservation tools (`CreateReservationTool`, `GetReservationsTool`,
   `GetOwnReservationTool`).
10. **Reservation voucher (D18):**
    - **Generate:** `GET`/`POST /reservation/{id}/voucher` returns a PDF rendered from the
      current reservation.
    - **Content:**
      - hotel branding and contact details
      - reservation reference and status
      - primary guest and other guests
      - arrival/departure and nights
      - room-type lines
      - adults, children and ages
      - special requests
      - an optional total (off by default)
    - **Overrides:** the request can override displayed fields and add a staff note. These
      apply to that PDF only and are never written back to the reservation.
    - **Email:** `POST /reservation/{id}/voucher/email` queues an email to the guest with
      the PDF attached. It is idempotent, so a retry does not send it twice.
    - **Rules:**
      - Nothing is stored. Each generation or email writes an audit entry, including any
        overrides.
      - English or Arabic follows `guest.preferred_language`, with an RTL layout for
        Arabic. The PDF engine must shape Arabic text correctly; the spec picks it.
      - Cancelled reservations cannot get a voucher; the spec confirms this.
    - **Permissions:** `reservations.voucher` to generate and `reservations.voucher_email`
      to email (final names in the spec). Tenant-isolation and permission tests are
      required.

**Frontend:** reservation form with room-type lines × quantity, guest list with primary,
children ages; "Voucher" action on the reservation page (preview/download, show-total
toggle, override fields, email button).

**Specs:** SPEC-010 Reservation Rooms, SPEC-011 Reservation Guests & Party, SPEC-012
Reservation Lifecycle, SPEC-013 Reservation Voucher (PDF).

### Phase 3 — Availability + Room Assignment

1. **Availability service:** for a hotel, room type and date range → sellable count.
   - Formula: active rooms of the type − out_of_order − rooms held by overlapping
     reservations.
   - An unassigned `reservation_room` still consumes type inventory.
2. **Availability API and read tool**, used by the admin UI, Admin AI and the Concierge.
3. **Assignment:**
   - Assign, reassign and unassign a physical room per `reservation_room`.
   - The room's type must match the line; the spec decides whether upgrades are allowed.
4. **Conflict prevention:**
   - A DB-level guarantee that one room cannot hold two overlapping active reservation
     rooms (Postgres exclusion constraint or equivalent; the spec decides).
   - Plus a service check with a clear error.
5. **Room changes:**
   - Staff-controlled reassign endpoint.
   - The guest AI can only create a room-change request task.
6. Permissions: `reservations.assign_room`, `availability.view` (final names in the spec).

**Frontend:** availability grid by type/date, assignment dialog, unassigned-arrivals list.

**Specs:** SPEC-020 Room-Type Availability, SPEC-021 Physical Room Assignment &
Reassignment.

### Phase 4 — Stay Lifecycle + Check-in/out

1. One Stay per reservation room (D3):
   - Migrate the unique constraint.
   - `StayService` creates an expected stay per line.
2. **Check-in endpoint** (D4), per room or for the whole reservation:
   - Requires confirmed status, an assigned room, and a room that isn't out_of_order.
   - Stay → in_house, room → occupied, reservation → checked_in once any/all rooms are in
     (the spec decides), audit entry.
3. **Check-out endpoint:**
   - Stay → departed, room → available + dirty, housekeeping cleaning task created.
   - Reservation → checked_out when all rooms are out. Audit entry.
4. Deprecate lifecycle transitions through `PUT /reservation`. Occupancy is derived from
   stays.
5. Stay attribution: tasks gain `stay_id`; bookings already have it.
6. AI tools: check-in/check-out (Admin AI) through the same service.
7. Permissions: `stays.check_in`, `stays.check_out`, `stays.view`.

**Frontend:** arrivals/departures board, check-in/out actions, in-house list.

**Specs:** SPEC-023 Stay per Room, SPEC-024 Check-in, SPEC-025 Check-out.

### Phase 5 — Housekeeping + Maintenance

1. **Housekeeping lifecycle:**
   - dirty → cleaning (task started) → clean (task completed) → inspected (inspection task
     completed, optional per hotel).
   - Task status drives room housekeeping status through a service, not model events
     scattered around the code.
2. **Automatic cleaning tasks** on check-out, and from the overnight job for stay-overs
   (the spec decides the stay-over rule).
3. **Maintenance:**
   - Tasks in maintenance categories assigned to the Maintenance team.
   - A "room issue" found during housekeeping creates a maintenance task automatically.
   - Optionally marks the room out_of_order; availability reflects this immediately.
   - Completing the task can return the room to available (explicit action).
4. **Notifications:** housekeeping/maintenance staff on new and assigned tasks, with no
   duplicate delivery.
5. Permissions: reuse `tasks.*`, plus `rooms.update_housekeeping_status` and
   `rooms.set_out_of_order` if needed.

**Frontend:** housekeeping board (rooms by status, today's tasks), maintenance list.

**Specs:** SPEC-030 Housekeeping Lifecycle, SPEC-033 Maintenance & Out-of-Order,
SPEC-035 Automatic Maintenance Requests.

### Phase 6 — Activities + Bookings

1. Activity availability check at booking time: window, operating hours, unavailable
   periods, daily capacity. The spec decides whether hourly time slots are MVP.
2. Booking stays on the existing `Booking`/`BookingService`. Add a reservation link if
   needed; the stay link already exists.
3. Staff workflows: confirm, update details, cancel.
4. **Guest cancellation requests:** the guest AI creates a cancellation-request task for
   staff. It never cancels directly.
5. Update `CreateBookingTool` and `GetActivitiesTool` to the availability checks.

**Frontend:** booking calendar/list by activity and date, cancellation-request queue.

**Specs:** SPEC-041 Activity Availability & Capacity, SPEC-043 Booking Staff Workflow &
Cancellation Requests.

### Phase 7 — Guest Services + Concierge on the new domain

1. **Request classification:** the Concierge maps guest requests to task categories
   (housekeeping / maintenance / other). The task is assigned to the category's team.
2. **Lifecycle:** guest → AI → task → team → staff → completed → guest notified on
   WhatsApp. The spec settles the 24 h session window and template messages.
3. **Human escalation:** `EscalateToHumanTool` creates a task + staff notification and
   pauses pitching (existing gate).
4. **Guest read tools:**
   - own reservation, stay and room
   - activities + availability
   - own bookings
   - own open requests
   - hotel info/policies via RAG
5. **Guest write tools:**
   - booking
   - service request
   - maintenance request
   - room-change request
   - cancellation request
   - human escalation
6. **Guest restrictions**, enforced in the tools and not only in the prompt. The guest AI
   can never:
   - cancel a booking
   - change a room
   - see other guests or staff conversations
   - bypass policy
7. **Identity:** unknown numbers get information only, no privileged actions (D12 order
   unchanged).

**Frontend:** none beyond task views (existing).

**Specs:** SPEC-044 Guest Service Requests, SPEC-052 Concierge Read Tools, SPEC-053
Concierge Action Tools, SPEC-054 Human Escalation.

### Phase 8 — Knowledge + RAG

1. **Knowledge documents:**
   - Model on the existing `knowledge_documents` table.
   - Upload API, storage, status (uploaded/extracting/indexed/failed).
2. **Extraction pipeline:** detect type → extract → normalize → chunk (`TextChunker`) →
   embed (Gemini, configurable dimensions) → `knowledge_chunks`. It is a queued job,
   idempotent and rebuildable. A failure never alters the source file.
   - Formats: PDF, DOCX, TXT, MD, CSV, Excel.
   - Images: the spec decides between OCR and vision.
3. **Scope:**
   - Global knowledge is managed by the super admin (`routes/admin.php`).
   - Hotel knowledge is managed by the hotel admin.
   - Retrieval merges both, and hotel knowledge wins on conflict.
4. **Citations:** search results carry source metadata; the Concierge and Insights cite
   sources.
5. Permissions: `knowledge.*` (hotel); the global side is super-admin only.

**Frontend:** document upload/list with indexing status; super-admin global knowledge page.

**Specs:** SPEC-060 Knowledge Documents & Extraction, SPEC-064 Retrieval Precedence &
Citations, SPEC-065 Global Knowledge Management.

### Phase 9 — Admin AI on the new domain

1. **Read tools:**
   - guests, reservations, room types, rooms, availability, stays
   - tasks/housekeeping/maintenance, activities, bookings
   - knowledge, reports
   - users/roles/settings (read-only, D17)
2. **Write tools** through the same services as the API:
   - guest, reservation, room assignment, check-in/out
   - task, maintenance/housekeeping, booking, knowledge
3. No user, permission or settings mutation by AI in MVP (D17).
4. Every tool resolves the acting user and checks `Permission` like the API.

**Specs:** SPEC-055 Admin AI PMS Tools.

### Phase 10 — Recommendation Approval + Proactive Concierge

1. **Approval (D9):**
   - Add `pending_approval`, `approved` and `rejected_by_admin` (names final in the spec)
     to the lifecycle.
   - Approve/reject endpoints and `recommendations.approve` permission.
   - The approval queue is audited.
2. **Pitching from the approved pool only:**
   - `PitchCoordinator` candidates are filtered to approved recommendations.
   - Inline generation creates pending records and does not pitch.
3. **Decline retry (D10):** replace `DECLINED_THIS_STAY` with "one different approved
   activity within 24 h of the first pitch, then closed".
4. **Proactive triggers:** stay milestones, upcoming activity, approved recommendation
   opportunity. Guardrails:
   - frequency caps
   - stay eligibility
   - quiet hours
   - duplicate prevention
   - opt-out
   - WhatsApp template rules
5. Outcome tracking and conversion attribution stay as they are.

**Frontend:** approval queue, recommendation status filters.

**Specs:** SPEC-071 Recommendation Approval, SPEC-073 Proactive WhatsApp, SPEC-074
Decline Retry Rule.

### Phase 11 — Conversation Visibility, Guest Memories, AI Audit

1. **Conversation visibility:**
   - Read-only conversation viewer API over the `laravel/ai` tables (D15).
   - Hotel Admin / Super Admin only, filtered to guests of the admin's hotel.
   - No search in MVP.
2. **Guest memories:**
   - A structured, hotel-scoped `guest_memories` table (category, content, source stay,
     confidence).
   - A one-time extraction job after a stay is departed.
   - The Concierge reads relevant memories.
   - Admins can view/delete them; guest deletion cascades.
   - Memories never override PMS data.
3. **AI audit consistency:** every AI write tool records actor, agent, tool, target,
   action, hotel, conversation id, result through `EventLogger`, separate from
   conversation text. A test enumerates write tools and asserts auditing.

**Frontend:** conversation viewer, guest memory panel on the guest page.

**Specs:** SPEC-082 Conversation Visibility, SPEC-084 Guest Memory Extraction &
Retrieval, SPEC-083 AI Audit Consistency.

### Phase 12 — Insights + AI Economics verification

1. Insights keep evidence levels and cite RAG sources where used.
2. Verify that hotel admins see usage (requests, tokens, features) but never provider cost,
   and that super admins see cost, model, feature, account and margin.
3. Add metering features for any new AI operations from Phases 7–11.

**Specs:** SPEC-090 Insights Citations, SPEC-091 Usage Visibility Audit.

### Phase 13 — Import + Migration (D16)

1. The `ReservationsImport` pipeline:
   - parse → normalize → validate → map → import → reconciliation report
   - The report lists imported / skipped / duplicate / invalid / unresolved.
2. Map legacy rows to room-type lines, guests (multiple, primary) and bookings. Treat the
   source as semi-structured (the September 2025 export is the reference dataset).
3. Conservative guest matching: phone digits, email, external id.
4. Idempotent re-import through external reference.
5. Queued import with a status endpoint (closes open item F11). This changes the API
   contract.

**Specs:** SPEC-100 Reservation Import v2, SPEC-101 Import Reconciliation.

### Phase 14 — Hardening + Production Readiness

1. **Security:** tenant-isolation audit across HTTP, jobs, webhooks and AI tools; AI
   authorization audit; guest isolation.
2. **Performance:**
   - indexes for availability, assignment overlap, bookings and knowledge
   - pgvector tuning
   - pagination review
3. **Infrastructure** (assessment F5/F9/F11):
   - Redis + named queues
   - atomic deploys + health check
   - error tracking
   - dashboard caching
4. **Readiness checklist:**
   - migrations/seeders
   - workers/scheduler
   - Gemini config
   - backups
   - monitoring
   - frontend production build, error/empty/loading states

**Specs:** SPEC-102 Tenant Isolation Audit, SPEC-103 AI Authorization Audit, SPEC-104
Performance & Queues, SPEC-105 Production Readiness.

---

## 4. Dependencies

```text
P0 Baseline
 └─ P1 Inventory Foundation
     └─ P2 Reservation Restructure
         └─ P3 Availability + Assignment
             └─ P4 Stays + Check-in/out
                 ├─ P5 Housekeeping + Maintenance ─┐
                 └─ P6 Activities + Bookings ──────┤
                                                   ├─ P7 Guest Services + Concierge ─┐
 P8 Knowledge + RAG (after P1, parallel track) ────┘                                 │
                                                     P9 Admin AI (after P4–P8) ◄──────┤
                                                     P10 Approval + Proactive (after P7)
                                                     P11 Visibility, Memories, Audit (after P4, P7)
                                                     P12 Insights/Economics (after P10–P11)
 P13 Import (after P2; full mapping after P4 and P6)
 P14 Hardening (last; audits can start any time)
```

- AI tools are only (re)built after the domain they touch is stable (P2–P6 before P7/P9).
- P8 has no dependency on the reservation work and can run in parallel with P2–P6.
- The finance flag (SPEC-005) ships inside P1 so no later phase writes to the ledger.

---

## 5. Specification backlog

Candidate specs in order. Each one goes through `/speckit-specify` → `/speckit-plan` →
`/speckit-tasks` → implement.

| Phase | Specs |
| --- | --- |
| 0 | SPEC-001 Baseline |
| 1 | SPEC-002 Room Types · SPEC-003 Physical Rooms & Status Split · SPEC-004 Default Teams & Categories · SPEC-005 Transactions Feature Flag |
| 2 | SPEC-010 Reservation Rooms · SPEC-011 Reservation Guests & Party · SPEC-012 Reservation Lifecycle · SPEC-013 Reservation Voucher (PDF) |
| 3 | SPEC-020 Room-Type Availability · SPEC-021 Physical Room Assignment & Reassignment |
| 4 | SPEC-023 Stay per Room · SPEC-024 Check-in · SPEC-025 Check-out |
| 5 | SPEC-030 Housekeeping Lifecycle · SPEC-033 Maintenance & Out-of-Order · SPEC-035 Automatic Maintenance Requests |
| 6 | SPEC-041 Activity Availability & Capacity · SPEC-043 Booking Staff Workflow & Cancellation Requests |
| 7 | SPEC-044 Guest Service Requests · SPEC-052 Concierge Read Tools · SPEC-053 Concierge Action Tools · SPEC-054 Human Escalation |
| 8 | SPEC-060 Knowledge Documents & Extraction · SPEC-064 Retrieval Precedence & Citations · SPEC-065 Global Knowledge Management |
| 9 | SPEC-055 Admin AI PMS Tools |
| 10 | SPEC-071 Recommendation Approval · SPEC-073 Proactive WhatsApp · SPEC-074 Decline Retry Rule |
| 11 | SPEC-082 Conversation Visibility · SPEC-083 AI Audit Consistency · SPEC-084 Guest Memory Extraction & Retrieval |
| 12 | SPEC-090 Insights Citations · SPEC-091 Usage Visibility Audit |
| 13 | SPEC-100 Reservation Import v2 · SPEC-101 Import Reconciliation |
| 14 | SPEC-102 Tenant Isolation Audit · SPEC-103 AI Authorization Audit · SPEC-104 Performance & Queues · SPEC-105 Production Readiness |

Deferred (post-MVP): conversation search, per-reservation conversation linking, payments /
folios / settlement, SaaS plans and subscriptions (old PGRIP WP-6/7/9+), per-room date
ranges, Building entity, OTA/channel managers, advanced pricing and forecasting.

### Questions left to each spec

These are recorded here so they aren't lost:
- **SPEC-011:** keep `reservations.guest_id` as the primary pointer, or derive the primary
  from `reservation_guests`?
- **SPEC-012:** exact status transitions, and who may trigger `no_show`.
- **SPEC-013:**
  - Which PDF engine renders Arabic correctly?
  - Which fields can be overridden?
  - Is a voucher allowed for pending and cancelled reservations?
  - Should the voucher carry a verification reference or QR code?
- **SPEC-021:** are room-type upgrades on assignment allowed?
- **SPEC-024:** when does the reservation become `checked_in` — when the first room checks
  in, or when all rooms do?
- **SPEC-030:** is inspection mandatory per hotel? What is the stay-over cleaning rule?
- **SPEC-041:** are hourly time slots MVP, or only daily capacity?
- **SPEC-044 / SPEC-073:** WhatsApp template messages outside the 24 h window.
- **SPEC-060:** image extraction method (OCR vs Gemini vision).

---

## 6. MVP completion gate

MVP is done when this runs end to end, with automated coverage of each step:

1. Hotel admin creates room types and physical rooms. Housekeeping and Maintenance teams
   already exist.
2. Staff or Admin AI creates a reservation with room-type lines, a primary guest plus other
   guests, and children's ages.
3. Staff generate the reservation voucher (PDF, in the guest's language) and email it to
   the guest.
4. Availability is calculated. Physical rooms are assigned with no overlap possible.
5. Check-in → one stay per room, room occupied, audit entry.
6. The guest messages on WhatsApp → identified → the Concierge creates a housekeeping task →
   the team is notified → the task is completed → the guest is notified.
7. The guest asks about activities → the Concierge checks live availability → books.
8. A recommendation is generated → the admin approves → the Concierge pitches → the guest
   responds → the outcome is recorded. A decline allows at most one alternative within 24 h.
9. Check-out → stay departed → room dirty → cleaning task → clean.
10. Post-checkout memory extraction runs once. The admin can view the conversation and the
   memories.
11. Every AI write in the flow appears in the audit log with the AI actor. The hotel admin
    sees usage but not provider cost.
12. No step writes to the transaction ledger while the finance flag is off.

---

## 7. How this plan is used

```text
constitution.md            stable rules (changes rarely)
   ↓
this plan                  target domain, decisions, phases (changes when the roadmap does)
   ↓
/speckit-specify           one capability (WHAT)
   ↓
/speckit-plan              code-level approach inside the existing architecture (HOW)
   ↓
/speckit-tasks             executable work
   ↓
implement → verify         Definition of Done in the constitution
```

When a spec needs a decision that contradicts §1, update this plan (new version) first.
Recommended first spec: **SPEC-002 Room Types**, then SPEC-003, then the reservation
restructure.
