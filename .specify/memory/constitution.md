<!--
Sync Impact Report
==================
Version change: 1.0.0 → 2.0.0 (same day as ratification)
Bump rationale: MAJOR — an MVP requirement is removed: conversation search moves from MVP
to post-MVP (Conversations and Guest Memories; MVP Boundary). Conversations stay in the
laravel/ai tables for MVP. Decided during the implementation-plan review.

Previous (1.0.0) report follows.
Version change: (unratified template) → 1.0.0
Bump rationale: first ratification; every placeholder replaced with project content.

Principles (all new):
  I.    PMS Is the System of Record
  II.   Preserve and Evolve
  III.  Tenant Isolation (NON-NEGOTIABLE)
  IV.   Permission-Based Authorization (NON-NEGOTIABLE)
  V.    AI Acts Through Tools, Never Around Them
  VI.   Auditability and Observability
  VII.  Data Integrity and Idempotency
  VIII. Tested at the Domain Boundary
  IX.   Spec-Driven, Incremental Delivery

Added sections:
  - Domain Model and Product Constraints (SECTION_2): stack, reservations and stays,
    room inventory, tasks/housekeeping/maintenance, activities, guest identity and
    WhatsApp, agents, knowledge/RAG, conversations and memories, recommendations,
    AI measurement and cost, notifications and jobs, import, API, i18n, security,
    MVP boundary and financial scope
  - Development Workflow and Quality Gates (SECTION_3): spec and plan principles,
    documentation, Definition of Done, Non-Negotiable Architectural Rules
  - Governance

Removed sections: none (template placeholders only)

Source mapping: user-supplied constitution sections 1–59, regrouped into the template
structure without dropping any rule. Wording changes made during normalization:
  - §36 "Hotel Admin should see…" → SHOULD (RFC-style keyword, same strength)
  - §41 "No MVP feature should depend on the transaction ledger" → MUST NOT, to match
    Non-Negotiable Rule 19 (payments and settlement are outside MVP)
Repo-specific anchors (BelongsToHotel, TenantContext, Permission enum, ChecksPermissions,
apiResponse) are taken from CLAUDE.md so principles are checkable against the code.

Templates: not modified by this command (they read the constitution at runtime).
  ✅ .specify/templates/plan-template.md — "Constitution Check" gate reads this file
  ✅ .specify/templates/spec-template.md — no change required
  ✅ .specify/templates/tasks-template.md — no change required

Deferred TODOs: none. Ratification date set to the date of this first adoption.
-->

# AI Hospitality Ecosystem Constitution

The AI Hospitality Ecosystem is a multi-property hospitality management platform — hotels,
hostels, camps, and similar properties — that combines a complete PMS with AI-powered guest
communication, staff assistance, operational automation, knowledge retrieval,
recommendations, and business insights. It evolves from the existing Laravel application;
it is not rewritten from scratch.

> Preserve and evolve the existing system while moving it toward the target hospitality
> platform. AI is an operational layer on top of a reliable PMS — not a replacement for
> the PMS domain itself.

## Core Principles

### I. PMS Is the System of Record

- The PMS database is the authoritative source for operational facts. Structured PMS data
  MUST NOT be replaced by AI-generated knowledge.
- The following MUST be structured domain data: hotels, hotel groups, users, permissions,
  staff roles, guests, room types, physical rooms, reservations, reservation guests,
  reservation room types, stays, activities, activity bookings, tasks, housekeeping,
  maintenance, knowledge records, conversations, recommendations, AI usage, and AI audit
  records.
- AI MAY interpret, retrieve, recommend, or act on this data but MUST NOT become its
  authoritative store.
- Live operational information (availability, occupancy, bookings, statuses) MUST come from
  PMS data via tools, never from RAG or embeddings.

Rationale: every AI behavior is only as trustworthy as the operational data under it.

### II. Preserve and Evolve

Implementation MUST:

1. Inspect existing functionality before introducing a replacement.
2. Reuse existing models, services, tools, jobs, policies, and infrastructure when they
   satisfy the target requirements.
3. Modify existing functionality when the target model differs.
4. Add missing functionality only where required.
5. Avoid unnecessary rewrites and destructive deletion of existing functionality.
6. Clearly identify functionality deferred to post-MVP.

- Existing functionality MUST NOT be removed merely because it was absent from an earlier
  plan. Non-MVP functionality is isolated and deferred, not deleted.
- When an existing feature conflicts with the target architecture, a controlled
  migration/deprecation path MUST be preferred over silent removal.
- New architecture MUST justify why the existing architecture cannot be reused.

### III. Tenant Isolation (NON-NEGOTIABLE)

- Hierarchy: `HotelGroup → Hotel`. HotelGroup is the account-level boundary for usage
  metering, AI cost attribution, future billing, and multi-property administration.
- Operational data MUST be hotel-scoped unless explicitly defined as global. Tenant-owned
  models use `BelongsToHotel`; scope is driven by `TenantContext`.
- Tenant boundaries MUST be enforced consistently in HTTP requests, models, policies,
  services, jobs, queues, webhooks, and AI tools.
- Code without an HTTP request (jobs, webhooks, schedulers) MUST explicitly establish hotel
  context (`TenantContext::runForHotel()` / `withoutScope()`), used deliberately and never to
  make a failing isolation test pass.
- A user belongs to one hotel account/context for MVP. Access to another hotel is granted
  through another appropriate account/user relationship, never by silently turning a user
  into an unrestricted cross-hotel identity. Hotel Admin MAY manage multiple hotels within
  the same HotelGroup according to the platform's multi-property rules.

### IV. Permission-Based Authorization (NON-NEGOTIABLE)

- Authorization MUST be permission-based (`App\Enums\Permission`), not role-name based.
  The existing permission architecture, hotel-specific staff roles, policies, and
  administrative bypass behavior MUST be preserved.
- Hotel Admin has full access within its administrative scope; staff access is determined by
  assigned permissions; Super Admin has platform-level access.
- Every new protected operation MUST have an explicit authorization path (policy via
  `ChecksPermissions::allows()` or an equivalent permission check).
- AI MUST use the same authorization model as human actors and MUST NOT bypass permissions
  because an operation was requested through an agent. "Admin AI can perform all authorized
  admin operations" never means "AI bypasses authorization."

### V. AI Acts Through Tools, Never Around Them

- The current direct agent architecture is preserved for MVP: Guest Concierge, Admin Advisor,
  Recommendation, Insights, and Turn Signal agents, each with clearly separated
  responsibilities. An AI Orchestrator is NOT part of the target architecture.
- Agents interact with the system through explicit tools, divided into read tools (current
  structured data) and write/action tools (authorized mutations).
- Every AI write operation MUST: (1) establish the correct actor context, (2) verify
  authorization, (3) validate input, (4) execute the domain operation through domain
  services, (5) record the operation in the audit system.
- AI MUST NOT be trusted with unrestricted database mutations and MUST NOT bypass domain
  services, policies, validation, or auditing.
- Agent instructions MUST emphasize: never invent operational data; use tools for live data;
  use RAG for knowledge; respect permissions; confirm ambiguous information; avoid duplicate
  actions; report failures honestly.
- Structured tool results are authoritative for the operation they represent.

### VI. Auditability and Observability

- Important mutations MUST be auditable, and the audit record MUST identify the actor,
  including an explicit AI actor identity for AI-generated mutations.
- Audit logging SHOULD cover reservations, room assignment, check-in, check-out, tasks,
  maintenance, housekeeping, activity bookings, knowledge changes, AI actions, and
  administrative changes.
- Conversation history ("what was communicated?") and AI audit ("what did the AI do?") MUST
  remain separate; AI actions MUST be traceable independently of conversational text.
- Important operations MUST be observable through application logs, job failure reporting,
  audit records, AI usage and cost records, and appropriate metrics.
- Failures MUST NOT be silently swallowed. Background failures SHOULD be retryable where safe.

### VII. Data Integrity and Idempotency

- Domain operations that modify multiple related records (reservation creation, room
  assignment, check-in, check-out, activity booking, AI multi-record actions) MUST run in
  database transactions. Partial business operations MUST NOT be left behind.
- Database constraints SHOULD enforce invariants wherever practical; mutations MUST preserve
  domain invariants.
- External events and retryable actions — WhatsApp webhooks, retryable AI tool execution,
  imports, notifications, external integrations, background jobs — MUST be idempotent.
  A retry MUST NOT create duplicate reservations, bookings, tasks, or messages.

### VIII. Tested at the Domain Boundary

- Every feature MUST include automated Pest tests; important domain behavior MUST have
  coverage. Tests run against real PostgreSQL with pgvector.
- Tests SHOULD cover: authorization, tenant isolation, validation, domain services,
  reservation lifecycle, room assignment, check-in, check-out, housekeeping, maintenance,
  activity booking, AI tools, AI authorization, WhatsApp idempotency, RAG isolation,
  recommendation approval, conversation visibility, and import behavior.
- Every tenant-scoped feature MUST have a test proving another hotel cannot see it. Every new
  permission MUST have a test showing allowed-with and `403`-without.
- AI functionality MUST be tested at the tool/domain boundary, not exclusively through
  model-output tests.

### IX. Spec-Driven, Incremental Delivery

- Significant development MUST follow: Constitution → Specify → Plan → Tasks →
  Implementation → Verification. Implementation of a significant feature MUST NOT begin
  before its specification and plan exist.
- The constitution defines stable principles; a specification defines WHAT; a plan defines
  HOW within the existing architecture; tasks break the plan into executable work.
- The system is delivered in phases. Each phase SHOULD leave the application working and
  have defined scope, migrations, backend work, frontend work where applicable, tests,
  verification, and documentation updates. MVP MUST remain incrementally deployable.
- Large cross-domain changes SHOULD be split into smaller specifications.

## Domain Model and Product Constraints

### Technology Stack

- Backend MUST use PHP 8.3+, Laravel `^13.8`, PostgreSQL, pgvector where vector search is
  required, Laravel AI SDK, Sanctum for API auth, Laravel queues/jobs for asynchronous work,
  Laravel Notifications where appropriate, and Pest for tests. It exposes a JSON API and is
  the authoritative source of operational data.
- Frontend MUST remain a separate application on Next.js `16.2.11` and React `19.2.4`,
  communicating only through the JSON API.
- MVP does not require WebSockets or other realtime transport; API refresh/polling is
  acceptable.

### Reservations and Stays

- A reservation represents booking intent and MUST NOT require a physical room at creation.
  It initially specifies room type requirements.
- Reservations MUST support one or more room types, multiple rooms, multiple guests, an
  explicit primary guest, adults, children, children's ages, arrival date, and departure date.
- All rooms in a reservation MUST share the reservation's arrival and departure dates for
  MVP. Per-room date ranges are deferred.
- Physical room assignment is a separate operation after creation. Lifecycle:
  Reservation → Room Type Requirement → Availability → Physical Room Assignment →
  Check-in → Stay → Check-out.
- Reservation and Stay MUST remain separate: a Reservation is booked accommodation; a Stay
  is actual physical guest presence and supports occupancy and operational attribution.
- Check-in MAY be performed by Hotel Admin, staff with the required permission, or AI acting
  for an authorized actor. It MUST validate prerequisites (including physical room assignment
  where required) and SHOULD create/update the Stay, update occupancy and room status, and
  preserve audit history.
- Check-out MAY be performed by the same actors. It MUST complete the Stay and SHOULD update
  reservation/stay state, release the room, mark it dirty, and trigger housekeeping work.
- Payment and folio settlement are not part of check-in/out in MVP.

### Room Inventory

- Room Type and physical Room MUST remain separate entities.
- Room Type MUST support name, description, maximum occupancy, adult capacity, child
  capacity, bed configuration, amenities, base price, and active/inactive state.
- A physical Room MUST require room number and room type, and SHOULD support floor,
  building, room status, housekeeping status, occupancy, out-of-order state, and notes.
  Buildings/wings MUST be supported.
- Room operational status and housekeeping status MUST be separate fields (e.g. `OCCUPIED`
  + `DIRTY`) and MUST NOT be collapsed into one.

### Tasks, Housekeeping, and Maintenance

- Tasks are the common operational work unit and MAY be associated with hotel, room, guest,
  reservation, stay, task category, assigned team, and assigned user.
- Guest service requests SHOULD be Tasks with appropriate categories; a separate request
  entity requires a demonstrated domain need.
- Housekeeping is MVP. Every hotel MUST have a default Housekeeping team. Housekeeping MUST
  support status, cleaning tasks, assignment, completion, inspection where applicable,
  check-out → dirty, and cleaning → clean, using the Task architecture.
- A guest housekeeping request SHOULD create a Task, assign the housekeeping category and
  default Housekeeping team, notify staff, allow completion, and notify the guest when
  appropriate.
- Maintenance is MVP and MUST use Tasks with a maintenance category. An issue identified in a
  housekeeping or operational workflow SHOULD automatically create a maintenance task.
  Lifecycle: Issue detected → Maintenance Task → Assignment → Work → Completion.

### Activities and Bookings

- Activities are sellable/bookable inventory and MUST support, where applicable: name,
  description, category, price, availability windows, operating hours, unavailable periods,
  duration, capacity, time slots, and audience/eligibility.
- Activity booking MUST reuse the existing generic Booking domain; an activity-specific
  booking domain requires a demonstrated domain constraint.
- Guest-requested activity cancellations MUST go to staff. The Guest Concierge MUST NOT
  cancel an activity booking directly in MVP; it MAY create or route a cancellation request.

### Guest Identity and WhatsApp

- WhatsApp is the primary AI interface for MVP. The registered WhatsApp phone number is the
  primary guest identity, and guests MUST be contacted through it.
- Unknown numbers MUST NOT receive privileged guest operations until identity is established.
  Identity resolution MUST be conservative and never attach one guest's conversation or
  actions to another guest.
- The existing WhatsApp architecture is preserved and evolved. Inbound flow: Meta →
  Webhook → Idempotency → Queue → Sender Recognition → Guest/Staff Routing → Agent →
  Store Response → Send Response.
- Inbound messages MUST be idempotent; retries MUST NOT duplicate AI generation or business
  actions. Staff WhatsApp device pairing MUST remain supported.

### Guest Concierge and Admin AI

- Guest Concierge MUST support: answering hotel and policy questions, searching activities,
  retrieving hotel knowledge, booking activities, creating service/housekeeping and
  maintenance requests, requesting a room change, and requesting human staff. It MUST respect
  the guest's identity and hotel scope.
- Guest room changes SHOULD remain a staff-controlled action, not automatic reassignment.
- Admin AI is an operational assistant for authorized administrators and MAY cover guests,
  reservations, room types, rooms, availability, room assignment, stays, check-in/out,
  activities, activity bookings, tasks, housekeeping, maintenance, knowledge, reports,
  recommendations, users, permissions, and hotel settings — each subject to normal
  authorization and domain validation.

### Knowledge and RAG

- RAG is for knowledge, not live state: policies, documents, procedures, descriptive hotel
  information, general platform knowledge, and other relatively stable text. Room
  availability and other live data MUST come from PMS tools.
- Knowledge MUST support global platform knowledge and hotel-specific knowledge;
  hotel-specific knowledge takes precedence for hotel-specific behavior. Retrieval MUST
  enforce hotel isolation.
- Documents MUST support, where extraction is available: PDF, DOCX, TXT, Markdown, images,
  Excel, and CSV. Pipeline: Source → Extraction → Normalization → Chunking → Embedding →
  pgvector → Retrieval.
- The vector index is derived data and MUST be rebuildable. Indexing failures MUST NOT
  corrupt the source document.
- Hotel Admin MAY create, update, activate/deactivate, and delete hotel knowledge; Super
  Admin manages global knowledge. Knowledge operations MUST be audited.

### Conversations and Guest Memories

- Guest, staff, and AI conversations MUST be retained indefinitely for MVP, separate from
  structured PMS data. History MAY give AI context but MUST NOT replace structured records.
- Conversation search is post-MVP. When built, it MUST support, where applicable, guest,
  phone number, date, reservation, and keyword, within hotel and authorization boundaries.
  For MVP, conversations remain in the `laravel/ai` conversation tables.
- Full guest WhatsApp conversation visibility is restricted to Hotel Admin and Super Admin.
  Regular staff MUST NOT automatically receive it; the permission model SHOULD make this
  access explicit and extensible.
- Guest memories hold durable information (preferences, communication preferences,
  recurring interests), not temporary conversation state. MVP extraction SHOULD run once
  after a completed stay, not continuously. Extracted memories are guest data and MUST
  respect privacy, authorization, and deletion requirements.

### Recommendations

- The system MUST support staff/admin-triggered generation, admin approval, delivery of
  approved recommendations, Concierge pitching, guest response tracking, and conversion
  measurement. Lifecycle: Staff/Admin → Recommendation Agent → Generated Recommendation →
  Admin Approval → Concierge → Guest.
- A recommendation MUST be approved by an administrator before the Concierge may pitch it.
- Proactive WhatsApp messaging is allowed in MVP and MUST respect guest context, stay
  lifecycle, approval, frequency limits, recommendation caps, WhatsApp rules, and audit.
- Uncontrolled repetitive pitching MUST be prevented. After a decline, the system MAY try
  another appropriate activity via next message, delayed attempt, next conversation, or a
  combination — within a maximum 24-hour retry/pitching window for that flow.

### AI Measurement, Usage, and Cost

- Existing measurement SHOULD be preserved and evolved: recommendation outcomes, conversion
  attribution, upsell and revenue-related measurement, evidence levels, and AI insights.
  Evidence levels MUST NOT be presented as authoritative business truth without context.
- AI usage MUST be measurable. The system SHOULD preserve usage logs, model pricing, cost
  attribution, trigger context, account attribution, spend controls, usage counters, and
  cost reports.
- Hotel Admin SHOULD see their hotel's AI usage. Super Admin SHOULD see provider cost and
  margin data. Provider costs MUST NOT be exposed to hotel admins unless a future business
  rule explicitly requires it.

### Notifications and Background Jobs

- Notifications SHOULD be asynchronous. Events needing staff attention (new guest task,
  maintenance request, housekeeping issue, request for human staff, activity cancellation
  request, reservation changes) SHOULD notify staff. Duplicate delivery MUST be avoided.
- Long-running work SHOULD use queued jobs (extraction, embeddings, indexing, memory
  extraction, insights, async recommendation generation, notifications, imports, scheduled
  recommendation retry, analytics attribution). Jobs MUST establish tenant context explicitly.

### Import and Migration

- Reservation import MUST be preserved for migration from existing PMS systems.
- Imports MUST normalize legacy data, validate records, preserve hotel scope, avoid duplicate
  guests/reservations, be idempotent where practical, report errors, and support
  reconciliation. Legacy data MUST be treated as inconsistent and semi-structured.

### API Design

- The backend MUST remain a JSON API. Endpoints MUST enforce authentication where required,
  hotel scope, and authorization; validate requests; return consistent responses via
  `apiResponse()` and Resources; and avoid exposing internal implementation details.
- Existing API conventions SHOULD be preserved; breaking changes SHOULD be avoided and, when
  unavoidable, documented.

### Internationalization, Security, and Privacy

- The system MUST support English and Arabic. Guest-facing AI responses SHOULD follow the
  guest's known language preference. Domain services MUST NOT hard-code language-specific
  business logic.
- Sensitive data MUST be protected by authentication, authorization, tenant isolation,
  least privilege, validation, secure secret management, and logging controls.
- AI prompts, logs, and audit records MUST NOT unnecessarily expose sensitive information.
  Guest conversations and memories are protected guest data.

### MVP Boundary and Financial Scope

- MVP delivers a usable operational PMS with AI assistance: multi-property foundation,
  guests, room types, physical rooms, reservations (multiple rooms, multiple guests, primary
  guest, room-type reservation), room assignment, stays, check-in/out, housekeeping,
  maintenance, tasks, activities, activity bookings, WhatsApp, Guest Concierge, Admin AI,
  knowledge/RAG, recommendations and approval, proactive WhatsApp, conversations,
  guest memories, AI audit, AI insights, AI usage/cost attribution, reservation import, and
  English/Arabic.
- Payments, folios, and transaction settlement are NOT part of MVP. Existing
  transaction/commerce infrastructure SHOULD be preserved but isolated; MVP features MUST NOT
  depend on the transaction ledger being active.
- Post-MVP candidates: payments, folios, settlement, advanced pricing, revenue management,
  OTA/channel managers, advanced reservation modifications, advanced financial reporting,
  more provider integrations, advanced forecasting, conversation search. Post-MVP work MUST NOT compromise the
  MVP domain model.

## Development Workflow and Quality Gates

### Specifications

- Specifications MUST describe observable behavior rather than implementation, and MUST
  define: problem, user/business need, scope, actors, behavior, business rules, acceptance
  criteria, edge cases, authorization expectations, data requirements, and failure behavior.
- Specifications MUST NOT silently introduce unestablished requirements. Important
  ambiguities MUST be clarified before implementation.

### Plans

- Plans MUST start from the existing codebase and SHOULD first identify existing models,
  services, controllers, policies, AI agents, tools, jobs, migrations, and frontend modules.
- Plans SHOULD prefer extension and reuse over duplication. When a new design conflicts with
  an existing one, the plan MUST explain the migration path.
- Every plan MUST pass a Constitution Check against the principles above before tasks are
  generated; any violation MUST be justified explicitly.

### Documentation

- Documentation MUST cover architecture, domain model, API behavior, AI agents, AI tools, RAG
  architecture, authorization, tenant boundaries, deployment, migrations, and operational
  workflows, and MUST evolve with implementation.
- Every API change updates the matching `docs/<resource>-api-documentation.md`; permission
  changes also update `docs/staff-roles-api-documentation.md`.

### Definition of Done

A significant feature is complete only when:

1. The specification is satisfied.
2. Authorization is implemented.
3. Tenant isolation is verified.
4. Database changes are migrated safely (new migrations; shipped migrations never edited).
5. Domain behavior is tested.
6. AI tools, if applicable, are tested.
7. API behavior is verified.
8. Frontend behavior is verified where applicable.
9. Audit behavior is verified where applicable.
10. Failure and edge cases are handled.
11. Documentation is updated.
12. Existing functionality remains intact unless explicitly deprecated.
13. The feature works within the existing architecture.

### Non-Negotiable Architectural Rules

These rules MUST NOT be violated without an explicit, recorded architectural decision:

1. PMS structured data is the operational source of truth.
2. AI does not bypass authorization.
3. Tenant isolation is mandatory.
4. Reservation does not require physical room assignment at creation.
5. Reservation room dates are shared at reservation level for MVP.
6. Reservation and Stay remain separate concepts.
7. Room Type and physical Room remain separate.
8. Room status and housekeeping status remain separate.
9. Tasks are the common operational work unit where appropriate.
10. Activity bookings use the existing Booking domain.
11. Guest WhatsApp identity is based on the registered phone number.
12. Unknown WhatsApp users cannot perform privileged guest operations.
13. Live operational information comes from tools, not RAG.
14. RAG is scoped by global/hotel knowledge boundaries.
15. AI write operations are audited.
16. Conversation history and AI audit remain separate.
17. Recommendation pitching requires administrator approval.
18. Guest activity cancellations go to staff.
19. Payments and transaction settlement are outside MVP.
20. Existing functionality is preserved unless explicitly deprecated.
21. Significant work follows Constitution → Specify → Plan → Tasks.
22. New architecture must justify why existing architecture cannot be reused.
23. Database mutations must preserve domain invariants.
24. External/retryable events must be idempotent.
25. MVP must remain incrementally deployable.

## Governance

- This constitution supersedes other project practices. Where `CLAUDE.md` or other guidance
  conflicts with it, the constitution wins and the guidance file MUST be updated.
  `CLAUDE.md` remains the runtime development guide for conventions and commands.
- Amendments are made through `/speckit-constitution`, MUST include a Sync Impact Report,
  and MUST be reviewed and committed by the repository owner. An amendment that changes a
  Non-Negotiable Architectural Rule MUST record the architectural decision behind it.
- Versioning follows semantic versioning: MAJOR for backward-incompatible removals or
  redefinitions of principles or rules; MINOR for new principles/sections or materially
  expanded guidance; PATCH for clarifications and wording.
- Compliance: every plan's Constitution Check, every task list, and every code review MUST
  verify adherence to the Core Principles and Non-Negotiable Rules. Complexity or deviation
  MUST be justified in the plan's Complexity Tracking section.

**Version**: 2.0.0 | **Ratified**: 2026-09-22 | **Last Amended**: 2026-09-22
