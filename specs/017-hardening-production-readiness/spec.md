# Feature Specification: Hardening and Production Readiness

**Feature Branch**: `017-hardening-production-readiness`

**Created**: 2026-10-24

**Status**: Draft

**Input**: Phase 14 — Hardening + Production Readiness, read from
docs/AI_Hospitality_Ecosystem_Implementation_Plan_v1_0.md. Covers the phase's four backlog
specs as one feature: SPEC-102 Tenant Isolation Audit, SPEC-103 AI Authorization Audit,
SPEC-104 Performance & Queues, and SPEC-105 Production Readiness. Closes the open
infrastructure items carried in plan §2.16 from the system design assessment
(docs/system-design-assessment-2026-09-19.md §7): F5 (post-deploy health check; atomic
releases are deferred to after the MVP), F9 (split queues; the separate fast store is deferred to after the MVP) and the
rest of F11 (dashboard caching and error tracking). Follows constitution Principles III (Tenant Isolation), IV
(Permission-Based Authorization), V (AI Acts Through Tools), VI (Auditability and
Observability) and the "Security, and Privacy" and "Notifications and Background Jobs"
rules.

## Overview

Phases 1–13 built the MVP domain: inventory, reservations, stays, housekeeping, activities,
the WhatsApp concierge, the Admin AI, knowledge, recommendations, memories, insights and
import. Each phase added its own isolation and permission tests, but nobody has yet checked
the whole system end to end, as one product, before real hotels depend on it.

This is the last MVP phase. It adds no new hotel-facing capability. It makes sure the
capabilities that exist are safe, fast enough and operable:

- **Nothing leaks between hotels.** Every way into the system (staff API, admin reporting,
  background work, the WhatsApp webhook, scheduled work and every AI tool) is listed and
  proven to keep one hotel's data away from another, and one guest's data away from another
  guest.
- **AI never does more than the person behind it may.** Every AI tool that changes data is
  checked against the same permission a staff member would need to do the same thing by
  hand, and every AI change is recorded with the AI as actor.
- **It stays fast as hotels grow.** The lookups that run most often (availability, room
  assignment overlap, bookings, knowledge search) stay quick at the expected data volume,
  and no list can return an unbounded number of rows.
- **Slow work cannot block urgent work.** A guest's WhatsApp reply never waits behind a
  batch of insights or an import, and the dashboard does not re-run its queries on every
  poll.
- **It can be deployed and watched.** Every deploy ends with a health check that flags a
  broken release; failures reach a person; and a
  written checklist says what "ready for production" means and proves each item.

Much of the assessment's remediation is already done (F1–F4, F6–F8, F10, queued imports from
F11). This feature does not redo that work. It verifies it as part of the audits and
finishes what is still open.

## Clarifications

### Session 2026-10-24

- Q: If a deploy fails its health check after its database changes have already run, how
  should the system go back to the previous version? → A: Database changes must work with
  the previous code too; rollback only switches the code back and never undoes database
  changes; a change that cannot work both ways is marked in advance as a manual deploy.
  *(Superseded on 2026-10-25: automatic rollback is deferred to after the MVP, see below.)*
- Q: Who should be able to call the health check that tests the database, the
  background-work queue and the cache? → A: A public check answers only up/down; the full
  check needs a secret token held by the deploy and monitoring; neither reveals internal
  details.
- Q: How long may work wait in each background lane before the platform owner gets an
  alert? → A: Guest conversations 1 min, notifications/email 5 min, batch AI 30 min,
  imports 60 min.
- Q: Should Sentry receive only error reports, or also performance traces that time
  requests and background jobs? → A: Errors, plus traces on a 10% sample of requests and
  jobs, with personal data removed.

### Session 2026-10-25

- Q: Where should background work, cache and locks live for the MVP? → A: They stay in
  the main database (PostgreSQL). Work is split into lanes there, each with its own
  workers. Moving to a separate store (Redis) is deferred to after the MVP. Reason: the
  production server has 2 GB of memory with about 1 GB free, and the MVP needs only the
  lane separation, not a new store.
- Q: Are database backups part of this feature? → A: No. Backups and restore are deferred
  to after the MVP (FR-029).
- Q: Should deploys move to atomic releases with automatic rollback now? → A: No. The
  deploy stays as it is (update in place), plus a health check at the end that fails the
  deploy visibly. Release directories, automatic rollback and the migration compatibility
  gate are deferred to after the MVP (FR-024, FR-025).

## User Scenarios & Testing *(mandatory)*

### User Story 1 - Prove no hotel can see another hotel's data (Priority: P1)

The platform owner needs to be able to tell a hotel, with evidence, that its guests,
reservations, conversations and reports cannot be seen or changed by any other hotel on the
platform, whichever way the request arrives. The audit produces a complete inventory of
entry points (every staff API endpoint, every admin reporting endpoint, every background
job, the WhatsApp webhook, every scheduled task and every AI tool) and, for each one, an
automated check that a user, guest or job working for Hotel A gets nothing belonging to
Hotel B. Any leak found is fixed in this feature.

**Why this priority**: Tenant isolation is non-negotiable in the constitution. One leak
would break the trust of every hotel on the platform, and it is far cheaper to find before
launch than after.

**Independent Test**: Seed two hotels with the same kinds of records. For every entry point
in the inventory, act as Hotel A (staff, admin, guest or job) and confirm that nothing from
Hotel B is returned, counted, changed or mentioned. The check fails if an entry point
exists that is not in the inventory.

**Acceptance Scenarios**:

1. **Given** two hotels with reservations, guests, stays, tasks, bookings, knowledge,
   conversations, memories, recommendations and insights, **When** a Hotel A admin calls any
   list, detail, update, delete or report endpoint, **Then** no Hotel B record appears in
   the response, in totals or counts, or in a related or nested record.
2. **Given** a Hotel A user, **When** they send the id of a Hotel B record (directly, or as
   a related record inside a create or update), **Then** the request is refused as not found
   or invalid and Hotel B's record is unchanged.
3. **Given** a background job or scheduled task that works for Hotel A, **When** it runs,
   **Then** it reads and writes only Hotel A's records, and it cannot run with no hotel
   context unless it is on a reviewed list of deliberately cross-hotel tasks.
4. **Given** a guest identified on WhatsApp as a Hotel A guest, **When** they ask the
   concierge about reservations, bookings, requests or activities, **Then** they see only
   their own records and Hotel A's public information, never another guest's or another
   hotel's.
5. **Given** a new endpoint, job or AI tool added later without an isolation check, **When**
   the automated test suite runs, **Then** it fails and names the uncovered entry point.

---

### User Story 2 - Prove AI cannot act beyond the user's permissions (Priority: P1)

A hotel admin who gives an employee a narrow staff role (for example "housekeeping only")
needs to be sure that the employee cannot get around that role by asking the Admin AI to do
something for them, and that the guest concierge can only do what a guest is allowed to do.
The audit lists every AI tool, classifies it as read or write, maps it to the permission
its manual equivalent requires, and proves with automated checks that the tool refuses when
the acting person lacks that permission and records an audit entry when it succeeds.

**Why this priority**: The constitution says AI must use the same authorization model as
human actors. A gap here would let any employee raise their own access through a chat box.

**Independent Test**: For every AI write tool, run it once as an employee who has the
matching permission (succeeds, audit entry with the AI actor and the human on whose behalf
it acted) and once as an employee without it (refused, nothing changed, the attempt
recorded as refused). For every guest concierge tool, run it as Guest A and try to reach Guest B's
data.

**Acceptance Scenarios**:

1. **Given** an employee without the permission to change reservations, **When** they ask
   the Admin AI to cancel a reservation, **Then** the AI refuses, the reservation is
   unchanged, and the reply says the action is not allowed without revealing the
   reservation's details.
2. **Given** an employee with that permission, **When** they ask the same thing, **Then**
   the reservation is cancelled and the audit log records the AI as actor and the employee
   as the person it acted for.
3. **Given** any person using the Admin AI, **When** they ask it to change users, staff
   roles, permissions or hotel settings, **Then** it refuses, because those stay read-only to
   AI in the MVP (plan decision D17).
4. **Given** a guest talking to the concierge, **When** they try to make the concierge act
   on another guest's reservation, booking or request (by naming it, or by giving an id),
   **Then** the concierge refuses and nothing changes.
5. **Given** the AI tool inventory, **When** a write tool exists with no mapped permission
   or no audit entry, **Then** the automated check fails and names the tool.
6. **Given** any AI read tool, **When** it returns guest data to the AI provider, **Then**
   it returns only the fields the task needs, not whole records with contact details or
   identity data.

---

### User Story 3 - Urgent work is never stuck behind slow work (Priority: P2)

A guest who messages the hotel on WhatsApp expects a reply in seconds, even while the
system is generating nightly insights for every hotel, indexing a large knowledge document
or importing a year of reservations. Background work is split into separate lanes by
urgency (guest conversations; batch AI work such as insights, embeddings and memory
extraction; notifications and email; imports), each served by its own workers, so a
backlog in one lane does not delay another. For the MVP the lanes stay in the main
database; moving them to a separate store is a later upgrade.

**Why this priority**: A delayed guest reply is visible to guests and directly hurts the
product's main promise. It matters before the number of hotels grows (assessment: needed
before 10–100 hotels), but it does not block a pilot.

**Independent Test**: Fill the batch lane with long-running work, then send a guest
message and a task-assignment notification. Both are handled within their normal time,
while the batch work keeps running.

**Acceptance Scenarios**:

1. **Given** 500 batch AI jobs waiting, **When** a guest sends a WhatsApp message, **Then**
   the reply is sent within the same time as when the system is idle.
2. **Given** a large reservation import running, **When** a task is assigned to staff,
   **Then** the assignment notification is delivered without waiting for the import.
3. **Given** a lane's worker stops, **When** work keeps arriving for that lane, **Then** the
   work is kept (not lost), the other lanes keep working, and an alert is raised (see User
   Story 5).
4. **Given** the switch from the current single lane to separate lanes, **When** it is
   deployed, **Then** no work already waiting is lost or skipped, and no job is sent to a
   lane that has no worker.
5. **Given** a hotel admin's dashboard is refreshed repeatedly, **When** nothing has changed,
   **Then** the figures come from a short-lived per-hotel copy instead of being recalculated
   each time, are never more than 60 seconds old, and are never shared with another hotel.

---

### User Story 4 - Busy screens stay fast as data grows (Priority: P2)

Front-desk staff check availability, assign rooms and review bookings many times a day.
The concierge searches hotel knowledge on almost every guest question. These lookups must
stay quick when a hotel has several years of reservations and the platform has many
hotels. Every list the API returns is bounded, so no screen or AI tool can ask for an
unbounded number of rows.

**Why this priority**: Slowness grows with data, so it is not yet felt by a pilot hotel,
but fixing it after launch means changes on large live tables.

**Independent Test**: Load a realistic volume (see SC-005), then time availability,
room-assignment overlap checks, booking lists and knowledge search, and confirm each meets
its target. Request every list endpoint with no page size and with an oversized page size
and confirm the result is bounded.

**Acceptance Scenarios**:

1. **Given** a hotel with 3 years of reservations, **When** staff check availability for a
   30-night range, **Then** the result comes back within the target in SC-005.
2. **Given** the same hotel, **When** staff assign a physical room, **Then** the overlap
   check that prevents double assignment completes within the target and still rejects
   every real overlap.
3. **Given** many hotels with knowledge documents, **When** the concierge searches one
   hotel's knowledge, **Then** the hotel's own relevant articles are still found (no loss
   of recall as other hotels add content) and the search meets its target.
4. **Given** any list endpoint, **When** it is called with no page size or with a page size
   above the maximum, **Then** it returns at most the maximum page size and says how to get
   the next page.
5. **Given** an AI read tool that lists records, **When** it is used, **Then** it returns a
   bounded number of rows and tells the AI when more exist.

---

### User Story 5 - Deploys are safe and failures reach a person (Priority: P2)

The platform owner wants to know immediately if a newly deployed version is unhealthy,
and to hear about errors and stuck background work before a hotel reports them. For the
MVP the deploy stays as it is today; it ends with a health check, and a failed check marks
the deploy as failed so the owner is told at once. Unhandled errors and failed background work are
reported to an error-tracking destination with enough context to act on, without guest
personal data or secrets. Alerts fire when work piles up, when background work keeps
failing, and when a hotel group hits its AI spend ceiling.

**Why this priority**: Today nothing checks a deploy afterwards, and errors only go to a
log file nobody watches (assessment F5, F11). That is acceptable for development but not
for paying hotels. Safer deploys (atomic switch, automatic rollback) come after the MVP.

**Independent Test**: Deploy a version that fails its health check and confirm the
deploy is reported as failed. Trigger an unhandled error and a failing job and confirm
each reaches the error-tracking destination with the hotel identified and no guest phone,
email or message text. Stop a worker and confirm a queue alert fires.

**Acceptance Scenarios**:

1. **Given** a new version whose health check fails, **When** the deploy finishes, **Then**
   the deploy is reported as failed and the platform owner is notified.
2. **Given** the full health check, **When** the deploy or monitoring calls it with the
   health-check token, **Then** it reports healthy only if the application can reach its
   database, its background-work queue and its cache; **When** anyone calls it without the
   token, **Then** it is refused; and the public check answers only up or down. Neither
   reveals versions, hostnames, credentials or other internal details.
4. **Given** an unhandled error during a request or a background job, **When** it happens,
   **Then** it is reported once with the hotel and the kind of operation, and guest
   contact details, message contents, tokens and keys are removed.
5. **Given** waiting work in any lane older than its limit in FR-028, or repeated job failures,
   or a hotel group reaching its AI spend ceiling, **When** that happens, **Then** an alert
   reaches the platform owner within 5 minutes.

---

### User Story 6 - A written, proven production readiness checklist (Priority: P3)

Before the first paying hotel goes live, the platform owner needs one checklist that says
what "production ready" means and shows, for each item, how it was proven: a fresh
environment can be built from scratch, workers and the scheduler run and restart on their
own, the AI provider is configured correctly,
monitoring is in place, and the frontend production build handles errors, empty data and
loading states on every screen.

**Why this priority**: It depends on Stories 1–5 being done, and its value is in tying
them together into a go-live decision.

**Independent Test**: Hand the checklist to someone who did not build the system. Using
only the checklist and its linked runbooks, they can build a fresh environment and confirm
every item.

**Acceptance Scenarios**:

1. **Given** an empty database, **When** all migrations and the base seeders run, **Then**
   they finish without error and leave a usable platform (a super admin, default teams and
   categories, no demo data unless asked for).
2. **Given** the server restarts, **When** it comes back, **Then** every background-work lane
   and the scheduler are running again without anyone logging in.
3. **Given** a missing or wrong AI provider setting, **When** the readiness check runs,
   **Then** it names the problem before the system is put live, instead of failing on the
   first guest message.
4. **Given** each frontend screen, **When** the API returns an error, an empty list or is
   slow, **Then** the screen shows a clear error, empty or loading state instead of a blank
   page or a crash.

---

### Edge Cases

- A record that is deliberately global (global knowledge articles, platform-wide reference
  data) must stay visible to every hotel; the isolation audit records these as intended
  exceptions rather than "fixing" them.
- A super admin is unrestricted by design; a user restricted to zero hotels must see
  nothing. The audit tests both and treats "no hotel context" in background work as an
  error unless the task is on the reviewed cross-hotel list.
- A guest who has stayed at two hotels on the shared WhatsApp number (decision D12) is
  resolved to one hotel per conversation; the audit confirms the concierge never mixes the
  two hotels' records in one answer.
- An AI tool that reads data on behalf of a person without the related view permission
  must refuse in the same way as the matching endpoint, not return partial data.
- A lane's workers all stop: its work stays waiting in the database (not lost), the other
  lanes keep working, the lane's wait alert fires, and the work resumes when a worker
  restarts.
- A lane's workers are busy with long jobs when the scheduler checks wait times: the wait
  check runs on the scheduler itself, not as a queued job, so it is never stuck behind the
  work it is checking.
- A cached dashboard must reflect a change of hotel (a multi-property admin switching
  hotels) immediately, never showing the previous hotel's figures.
- Error reports for failures inside the WhatsApp path must not include the guest's phone
  number or message text, even though they are in the failing job's data.
- A list endpoint that a frontend screen currently calls without a page size and relies on
  getting every row: the change is listed as breaking and the frontend slice handles it.
- An alert source that is noisy (for example one hotel's repeated job failures) must not
  hide alerts for other hotels or other kinds of failures.

## Requirements *(mandatory)*

### Functional Requirements

**Tenant isolation audit (SPEC-102)**

- **FR-001**: The system MUST have a maintained inventory of every entry point that can
  read or change tenant data: staff API endpoints, admin reporting endpoints, background
  jobs, scheduled tasks, the WhatsApp webhook and every AI tool.
- **FR-002**: Every entry point in the inventory MUST have an automated check proving that
  an actor for one hotel cannot read, count, change or reference another hotel's records,
  including through related or nested records.
- **FR-003**: An automated check MUST fail when an entry point exists that is not in the
  inventory, so new endpoints, jobs and tools cannot ship without an isolation check.
- **FR-004**: Every background job and scheduled task MUST run inside an explicit hotel
  context, except tasks on a reviewed, documented list of deliberately cross-hotel tasks;
  each listed task MUST state why it needs to cross hotels.
- **FR-005**: Records referenced by id from another hotel MUST be refused as not found or
  invalid, both when addressed directly and when supplied as a related record in a create
  or update.
- **FR-006**: Guest-facing tools MUST limit a guest to their own reservations, stays,
  bookings, requests and memories plus their hotel's public information.
- **FR-007**: Intended global data (global knowledge, platform reference data) MUST be
  documented as an exception in the inventory and covered by a check that it stays
  read-only to hotels.
- **FR-008**: Every leak found by the audit MUST be fixed in this feature and covered by a
  regression check.

**AI authorization audit (SPEC-103)**

- **FR-009**: The system MUST have a maintained inventory of every AI tool, marking each as
  read or write, the agent(s) that can use it, and the permission its manual equivalent
  requires.
- **FR-010**: Every Admin AI tool MUST refuse when the person it acts for lacks the mapped
  permission, using the same permission and same-hotel rule as the matching endpoint.
- **FR-011**: Admin AI MUST NOT change users, staff roles, permissions or hotel settings
  (D17); an automated check MUST prove each such request is refused.
- **FR-012**: Every successful AI write MUST create an audit entry naming the AI agent as
  actor and the person or guest it acted for; refused writes MUST NOT change data and
  MUST be recorded as refused, as the AI activity log already does.
- **FR-013**: An automated check MUST fail when a write tool has no mapped permission or no
  audit entry, naming the tool.
- **FR-014**: AI read tools MUST send the AI provider only the fields their task needs; whole
  records with contact details, identity data or internal identifiers beyond what is
  needed MUST NOT be sent.
- **FR-015**: A refusal MUST tell the person that the action is not allowed without
  revealing details of the record they were not allowed to act on.

**Performance and queues (SPEC-104)**

- **FR-016**: Availability, physical-room assignment overlap, booking lists and knowledge
  search MUST meet the response targets in SC-005 at the data volume stated there.
- **FR-017**: Knowledge search MUST keep returning a hotel's own relevant articles as the
  number of hotels and documents grows; an automated check MUST seed many hotels and
  confirm the hotel's own article is still found.
- **FR-018**: Every list endpoint MUST apply a default page size and a maximum page size,
  and MUST tell the caller how to fetch the next page; no list may return all rows on
  request.
- **FR-019**: Every AI read tool that lists records MUST return a bounded number of rows and
  say when more exist.
- **FR-020**: Background work MUST be split into separate lanes by urgency: guest
  conversations, batch AI work, notifications and email, and imports. Each lane MUST be
  served by its own workers so a backlog in one does not delay another.
- **FR-021**: For the MVP, background work, locks, rate limits and short-lived cached data
  stay in the main database. Lanes, wait alerts and worker supervision MUST be built so
  that moving to a separate store later changes only configuration and server setup, not
  the code that sends work to a lane. *(Moving to a separate store is deferred to after
  the MVP.)*
- **FR-022**: The switch to separate lanes MUST NOT lose, skip or strand work already
  waiting, and MUST NOT send work to a lane that has no worker listening.
- **FR-023**: The hotel dashboard MUST be served from a short-lived per-hotel copy no older
  than 60 seconds, MUST never be shared across hotels, and MUST reflect a hotel switch
  immediately.

**Production readiness (SPEC-105)**

- **FR-024**: *(Deferred to after the MVP, Clarifications 2026-10-25.)* Preparing each
  new version fully before switching to it in one step. For the MVP the deploy keeps
  updating the live directory in place, as today.
- **FR-025**: After each deploy the full health check MUST run. If it fails, the deploy
  MUST be reported as failed and the platform owner notified. *(Automatic rollback to the
  previous version, and the rule that every database change works with the previous code,
  are deferred to after the MVP.)*
- **FR-026**: There MUST be two health checks. The public check answers only up or down.
  The full check reports healthy only when the database, the background-work queue and the
  cache are reachable, and MUST refuse callers without the secret health-check token held
  by the deploy and monitoring. Neither check may reveal versions, hostnames, credentials
  or other internal details.
- **FR-027**: Unhandled errors in requests and background work MUST be reported to an
  error-tracking destination once, with the hotel and the operation, and with guest contact
  details, message contents, tokens and keys removed before the report leaves our servers.
  The destination is a hosted error-tracking service, Sentry (decided 2026-10-24); its
  connection details are configuration, never written into code or docs.
- **FR-027a**: Performance traces MUST be sent to the same service for a sample of 10% of
  requests and background jobs, with the same removal of guest contact details, message
  contents, tokens and keys. The sample rate MUST be adjustable through configuration
  without a code change.
- **FR-028**: Alerts MUST reach the platform owner within 5 minutes when the oldest waiting
  work in a lane is older than that lane's limit (guest conversations 1 minute,
  notifications and email 5 minutes, batch AI 30 minutes, imports 60 minutes), when background work fails repeatedly, when the health
  check fails, or when a hotel group reaches its AI spend ceiling.
- **FR-029**: *(Deferred to after the MVP, Clarifications 2026-10-25.)* Automatic
  off-server database backups, a restore runbook, a tested restore, the recovery target and
  backup retention are not part of this feature. The readiness checklist lists backups as
  a known post-MVP item, so the go-live decision is made knowing they are not in place.
- **FR-030**: All migrations and base seeders MUST run cleanly on an empty database and
  leave a usable platform without demo data unless demo data is explicitly requested.
- **FR-031**: Every background-work lane and the scheduler MUST be supervised so they start
  on boot and restart after a crash.
- **FR-032**: A readiness check MUST verify the AI provider configuration (credentials
  present, model names valid, spend ceilings set) and name any problem before go-live.
- **FR-033**: A production readiness checklist MUST list every item in this section, each
  with how it is proven and a link to its runbook or test.
- **FR-034**: Every frontend screen MUST show clear error, empty and loading states, and the
  frontend production build MUST succeed with no errors (frontend slice, delivered in
  `ecosystem-frontend` per decision D13).
- **FR-035**: Every breaking API change made by this feature (such as a list endpoint that
  becomes bounded) MUST be documented in the matching API document and in a
  latest-changes note.

### Key Entities

- **Entry-point inventory**: the list of every way into tenant data (endpoint, job,
  scheduled task, webhook, AI tool), with its hotel-context rule and the check that covers
  it; the deliberately cross-hotel tasks and global data are listed as exceptions with a
  reason.
- **AI tool inventory**: every AI tool with its agent(s), read/write kind, mapped permission
  and audit behavior.
- **Work lane**: a named stream of background work grouped by urgency, with its own workers,
  its own age threshold for alerts and the kinds of work it carries.
- **Health status**: the result of a health check — up/down for the public check; healthy
  or unhealthy with the parts checked for the token-protected full check; no internal
  details in either.
- **Error report**: one unhandled failure as sent to error tracking: when, hotel, kind of
  operation and scrubbed details.
- **Alert**: a notification to the platform owner with its trigger (lane age, repeated
  failures, health, spend ceiling), the affected hotel or group, and the time.
- **Readiness checklist item**: one go-live condition, how it is proven, its evidence and
  its owner.

## Success Criteria *(mandatory)*

### Measurable Outcomes

- **SC-001**: 100% of entry points in the inventory (endpoints, jobs, scheduled tasks,
  webhook, AI tools) have a passing cross-hotel isolation check, and the suite fails when an
  uncovered entry point is added.
- **SC-002**: 100% of AI write tools refuse an actor without the mapped permission and
  record an audit entry on success; 0 AI tools can change users, staff roles, permissions or
  hotel settings.
- **SC-003**: With 500 batch jobs waiting, 95% of guest WhatsApp replies are sent within
  the same time as on an idle system (no more than 10% slower).
- **SC-004**: Task-assignment notifications are delivered within 1 minute while a
  10,000-row import is running.
- **SC-005**: At 100 hotels, each with 3 years of reservations (about 15,000 reservations
  per hotel) and 200 knowledge documents, 95% of availability checks for a 30-night range,
  room-assignment overlap checks, booking-list pages and knowledge searches complete in
  under 1 second.
- **SC-006**: 0 list endpoints and 0 AI list tools return more than their maximum page size
  in one response.
- **SC-007**: 100% of deploys run the full health check at the end, and a deploy whose
  health check fails is reported as failed within 2 minutes.
- **SC-008**: 100% of unhandled request and job errors reach error tracking, and a review of
  100 sampled reports and 100 sampled traces finds 0 guest phone numbers, emails, message
  texts, tokens or keys.
- **SC-009**: Each alert condition (lane backlog, repeated failures, failed health check,
  spend ceiling) reaches the platform owner within 5 minutes in a rehearsal.
- **SC-010**: A person who did not build the system builds a fresh environment using only
  the runbooks, and every readiness checklist item is marked proven (or listed as a known
  post-MVP item) before the first paying hotel goes live.
- **SC-011**: Repeated dashboard refreshes within 60 seconds recalculate the figures at most
  once per hotel, and 0 refreshes show another hotel's figures.

## Assumptions

- The audits verify the remediation already recorded in the assessment (F1–F4, F6–F8, F10
  and queued imports from F11) rather than redoing it; work happens only where a check
  fails.
- Background work, locks and cache stay in the main database for the MVP (Clarifications
  2026-10-25). The separate store the plan chose for F9 (plan §2.16) is a post-MVP
  upgrade. The production host has 2 GB of memory (about 1 GB free, no swap), so the
  lanes start with few workers.
- Lanes follow the assessment's recommendation: guest conversations (real-time), batch AI
  (insights, embeddings, memory extraction, recommendations), default (notifications,
  email) and imports. The planning step may merge or rename lanes as long as FR-020 holds.
- The platform stays on one application host for MVP (assessment §5); horizontal scaling,
  read replicas and table partitioning are post-MVP.
- The existing per-endpoint maximum page sizes are kept: 100 for most lists, with 50 (guest
  memories) and 200 (conversation messages) as documented exceptions. No maximum changes.
- The 60-second dashboard freshness limit is acceptable to hotel admins because the
  dashboard shows daily operational figures, not live counters.
- Alerts go to the platform owner by email at minimum; paging or chat channels are optional.
  Error and health-check alerts come from the error-tracking service; lane-wait,
  repeated job failure and spend-ceiling alerts come from the application itself.
- Backups and restore are deferred to after the MVP (FR-029). Until then the database has
  no backup made by this system; that risk is accepted for the MVP.
- The SC-005 volume (100 hotels, 3 years of reservations each) is the scale the assessment
  says must be ready before growth beyond the pilot; it is used for performance tests, not
  as a capacity limit.
- The frontend slice (error, empty and loading states; production build) is specified here
  and delivered in `ecosystem-frontend` per decision D13, with a frontend-changes handoff.
- Users, staff roles, hotel settings, the AI advisor and usage stay admin-only, so no new
  permission cases are added by this feature unless the audits find an unguarded action.
- The Phase 13 import rehearsal (T059, 2026-10-23) is not part of this feature.
