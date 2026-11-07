# Feature Specification: Account and Hotel Suspension

**Feature Branch**: `024-superadmin-suspension`

**Created**: 2026-11-07

**Status**: Draft

**Input**: User description: "Stage 3 of docs/designs/super-admin-platform.md: full-stop suspension of an account or a single hotel, so a non-paying customer can be stopped and later reactivated with their data kept. Suspension is stored in new columns, not `is_active`. A hotel counts as suspended when it or its account is. Login is refused and existing tokens get a machine-readable 403. A user with several hotels keeps the active ones. Blocked jobs do not run, write no failed-job record and are not replayed. A suspended hotel's WhatsApp messages get a fixed reply at most once per 24 hours per hotel and phone, and STOP still works. The dashboard, impersonation endpoints, removing the super admin bypasses, and gating queued notifications and pairing codes are out of scope, as are rollback, data backfills and deploy coordination. The Stage 3 plan must be verified against the code, and any mismatch reported."

## Problem and Need

The platform has no way to stop a customer who has not paid. The only switch, `is_active`, can be changed by the hotel's own admin and already means something else to housekeeping and proactive messaging. Stopping a customer today would take direct database work, and it would leave AI costs and scheduled work running.

This feature lets the platform operator stop an account or one hotel completely, and later restore it with all its data. While stopped, the customer's staff cannot sign in or use the API, the AI stops, and background work for that hotel does not run.

**Actors**:

- **Super admin**: the platform operator, the only user who can suspend or reactivate. Never suspended themselves.
- **Hotel staff**: admins, account owners and employees of a suspended account or hotel. They are refused with a response the frontend can recognize.
- **Multi-hotel user**: belongs to several hotels, only some of them suspended. Keeps working in the others.
- **Guest**: writes to the hotel on WhatsApp, and gets a fixed reply instead of an AI answer.
- **Frontend application**: the API client, which shows the suspended message when it sees the suspended response.

Requirements tagged **[V1]** to **[V17]** settle a point where the design's Stage 3 plan disagrees with the code or leaves a gap. The evidence and the chosen default for each are in [plan-verification.md](plan-verification.md).

## Clarifications

### Session 2026-11-07

- Q: When a hotel is suspended, should an employee whose staff role belongs to that hotel keep the role's permissions in their other, active hotels? → A: An employee of a suspended hotel has no access to the account's other hotels, unless they were explicitly assigned to that other hotel. An assigned employee keeps working there with their role's permissions.
- Q: What should an employee holding the new `hotels.view` permission see for each hotel in `GET /api/hotel`? → A: A short record (`id`, `name`, `slug`, `timezone`, `currency`, `city`), while admins get the full record. Super admin APIs stay separate from the employee ones: `api/superadmin/hotels` is where a super admin reads hotels and suspension details, and nothing is built for super admins on the tenant routes.
- Q: When an admin with several hotels messages the WhatsApp advisor and their default hotel is suspended, what should happen? → A: They get the fixed reply and no AI runs, until the default hotel is reactivated. They use the dashboard for their active hotels.
- Q: What should an account's `GET /api/usage` report while one of its hotels is suspended? → A: It stays unchanged: the totals and hotel count include the suspended hotel.
- Q: Should the spec keep the five fixes as listed (V2, V4, V7, V10, V13)? → A: Keep them. For V2, a super admin has full read access to any hotel, even a suspended one. The user asked why a job would be blocked rather than objecting to V7, so V7 stays at its default.
- Q: Should the fixed WhatsApp reply stay English only, given that the constitution requires English and Arabic? → A: No. A guest gets it in their known language, English or Arabic. The English text stays the existing unavailable reply. Admins get English, and API messages stay English.

## User Scenarios & Testing *(mandatory)*

### User Story 1 - Stop a non-paying account (Priority: P1)

A customer has stopped paying. The super admin suspends their account with a reason. All staff are refused on their next request, nobody can sign in, and both hotels stop consuming AI and background work.

**Why this priority**: This is the commercial reason for the feature.

**Independent Test**: Suspend an account with two hotels. Check that a signed-in employee's next request, a new login and a queued job for either hotel are all stopped.

**Acceptance Scenarios**:

1. **Given** an active account with signed-in staff, **When** a super admin calls `POST /api/superadmin/accounts/{account}/suspend` with a reason, **Then** the account is returned with who suspended it, when and why, and the action is recorded in the platform audit log.
2. **Given** a suspended account, **When** a staff member makes any hotel API request with an existing token, **Then** they get `403` with `body.reason = "suspended"`, `body.scope = "account"` and the message "This account has been suspended."
3. **Given** a suspended account, **When** a staff member logs in with the correct password, **Then** they get the same `403` and no token is issued.
4. **Given** a suspended account, **When** someone logs in with a wrong password, **Then** they get the ordinary `401`, which reveals nothing about suspension.
5. **Given** a suspended account, **When** a staff member calls `POST /api/logout`, **Then** it succeeds.
6. **Given** a suspension request with no reason, **When** it is sent, **Then** it gets `422` and nothing changes.
7. **Given** an account that is already suspended, **When** it is suspended again, **Then** it gets `422` and the original who, when and why are kept.

---

### User Story 2 - Reactivate with everything intact (Priority: P1)

The customer pays. The super admin reactivates them, and they carry on with all their data and without any special steps.

**Why this priority**: Suspension is only safe to use if it can be fully undone.

**Independent Test**: Suspend and reactivate an account. Check that the old token works, login works, AI replies resume, scheduled work picks the hotels up again, and no record was changed by the suspension.

**Acceptance Scenarios**:

1. **Given** a suspended account, **When** a super admin calls `POST /api/superadmin/accounts/{account}/reactivate`, **Then** the suspension fields are cleared, the action is recorded, and the next request with an existing token succeeds.
2. **Given** a reactivated hotel, **When** the next scheduled run comes, **Then** recurring work includes that hotel again.
3. **Given** one-off work that was skipped during the suspension (an import, a voucher email, a guest request notice), **When** the hotel is reactivated, **Then** that work is not re-run, and staff redo it if they still want it.
4. **Given** a proactive message left scheduled during the suspension and still valid, **When** the hotel is reactivated, **Then** it may be sent late, if the normal send-time checks (validity, WhatsApp window, quiet hours) allow it. No catch-up of missed messages is built.
5. **Given** a hotel suspended in its own right inside a suspended account, **When** only the account is reactivated, **Then** that hotel stays suspended, with scope `hotel`. **[V6]**

---

### User Story 3 - Suspend one hotel of a multi-hotel user (Priority: P1)

One property of a customer stops paying. Users who also work at another hotel carry on there without noticing anything, even when the suspended hotel was their default.

**Why this priority**: The most likely way to break a customer who is still paying, and the hardest rule to get right.

**Independent Test**: Give a user hotels A and B with A as default, suspend A, and read, list and create without a header and with `X-Hotel-Id: B`.

**Acceptance Scenarios**:

1. **Given** a user in hotels A and B, with A suspended, **When** they log in, **Then** login succeeds, and the response's `hotel` is B.
2. **Given** that user, **When** they call `GET /api/hotel`, **Then** only B is listed.
3. **Given** A is their default hotel, **When** they read, run a policy-guarded request, or create a record with no header or with `X-Hotel-Id: B`, **Then** it all acts on B, and their stored default hotel does not change.
4. **Given** that user, **When** they send `X-Hotel-Id: A`, **Then** they get the suspended `403` with `body.scope = "hotel"` and the message "This hotel has been suspended."
5. **Given** that user, **When** they request a record of A by id with no header, **Then** it is not found.
6. **Given** an employee whose default hotel A is suspended and who was explicitly assigned to B, with a staff role belonging to A, **When** they work in B, **Then** they keep the permissions that role grants. **[V3]**
   An employee of A with no assignment to another hotel gets the suspended `403` with `body.scope = "hotel"`, even when the account has other active hotels.
7. **Given** a user whose only hotels are all suspended, in an account that is not, **When** they log in or make a request, **Then** they get the suspended `403` with `body.scope = "hotel"`.

---

### User Story 4 - AI and guests during a suspension (Priority: P1)

A guest of a suspended hotel writes on WhatsApp. They get a short fixed message telling them to contact the hotel directly, and no AI runs, so the platform stops paying for that hotel. A guest asking to stop messages is still honored.

**Why this priority**: Guests are not party to the dispute, and an unanswered message or an AI bill would both be wrong.

**Independent Test**: Suspend a hotel, send inbound messages from a guest and a paired admin, and check the replies, the number of sends and that no AI run was recorded.

**Acceptance Scenarios**:

1. **Given** a suspended hotel, **When** a guest sends a message, **Then** they get "Sorry, I can't answer messages right now. Please contact the hotel directly for assistance.", or the same message in Arabic when that is their known language, and no AI runs. **[V17]**
2. **Given** that guest got the reply, **When** they write again within 24 hours, **Then** the message is stored and no reply is sent.
3. **Given** 24 hours have passed, **When** they write again, **Then** they get the reply again.
4. **Given** a suspended hotel, **When** a guest sends STOP, **Then** the opt-out is applied and confirmed as usual.
5. **Given** a suspended hotel, **When** a paired admin of that hotel writes, **Then** they get the same fixed reply with no AI run.
   This also applies to an admin with other active hotels, when the suspended hotel is their default. The advisor does not move to another hotel.
6. **Given** an admin number that is not paired, **When** it writes, **Then** it gets the not-paired reply, which reveals nothing about suspension.
7. **Given** a sending failure after the fixed reply was decided, **When** the send is retried, **Then** the same reply is sent, not lost to the 24-hour limit.

---

### User Story 5 - Background work stops (Priority: P1)

Work queued before the suspension, and scheduled work after it, does not run for the suspended hotel, and it does not fill the failure logs or set off alerts.

**Why this priority**: Background work includes AI spend and messages to guests, and a false alarm for every suspended customer would teach the operator to ignore alerts.

**Independent Test**: Queue one of each hotel job, suspend the hotel, process the queue, and check that nothing ran and no failed-job record exists.

**Acceptance Scenarios**:

1. **Given** a job queued for a hotel that is then suspended, **When** a worker picks it up, **Then** it does not do its work, leaves the queue, and writes no failed-job record.
2. **Given** that job has a failure handler, **When** it is blocked, **Then** the handler runs, so an import ends failed and a voucher email ends failed rather than staying in progress.
3. **Given** a blocked proactive message, **When** the job is blocked, **Then** the message stays scheduled.
4. **Given** a job already running when its hotel is suspended, **When** the suspension happens, **Then** that job finishes. The check runs only when a job starts.
5. **Given** a job for a global knowledge document or article, with no hotel, **When** it runs, **Then** it is not blocked.
6. **Given** scheduled work that loops over hotels, **When** it runs, **Then** it skips suspended hotels and every hotel of a suspended account.
7. **Given** guest memory retries that fall due during a suspension, **When** the hotel is reactivated, **Then** they run on the next retry sweep.
8. **Given** a knowledge document deleted more than 30 days ago in a suspended hotel, **When** the purge runs, **Then** the document is kept. Global documents and those of soft-deleted hotels still purge.
9. **Given** a platform knowledge rebuild, **When** it reaches a suspended hotel's items, **Then** they count as failed, and the rebuild still finishes. **[V9]**

---

### User Story 6 - Nothing about suspension leaks or can be undone by a tenant (Priority: P2)

Only a super admin can change suspension. Only someone who really has access to a hotel, and has proved it, learns that it is suspended. The reason is never shown to hotel staff.

**Why this priority**: The suspension is worthless if a hotel admin can clear it, and a reason can be commercially sensitive.

**Independent Test**: Try to write the suspension fields through tenant update requests. Request a suspended hotel as a stranger, and read tenant responses for any suspension field.

**Acceptance Scenarios**:

1. **Given** a hotel admin, **When** they send suspension fields to `PUT /api/hotel/{id}`, **Then** the fields do not change.
2. **Given** a suspended hotel, **When** a user with no link to it requests `GET /api/hotel/{id}` or sends its id in `X-Hotel-Id`, **Then** they get the normal answer for a hotel they can't reach, never "suspended".
3. **Given** a hotel owner who also has an active hotel, **When** they request their suspended hotel with `GET` or `PUT /api/hotel/{id}`, **Then** they get the suspended `403` after normal authorization.
4. **Given** an active hotel, **When** its owner requests it, **Then** the response is `200`, even if the owner has no membership row for it.
5. **Given** any tenant response, **When** it includes a hotel or account, **Then** it never includes who suspended it, when or why. **[V1]**
6. **Given** a user with no hotels, in no suspended account, **When** they make a request, **Then** they get today's behaviour and are not told they are suspended.
7. **Given** an account owner with no hotels, in a suspended account, **When** they make a request, **Then** they get the suspended `403` with scope `account`.
8. **Given** a super admin, **When** they use any endpoint, including `GET` or `PUT /api/hotel/{id}` on a suspended hotel, **Then** suspension does not refuse them. **[V2]**
9. **Given** a non-super-admin, **When** they call a suspend or reactivate endpoint, **Then** they get `403`.

---

### User Story 7 - Staff can list their own hotels (Priority: P2)

A user with several hotels needs to see the ones they can work in. Today that list is open to super admins only, so CUJ 3 can't be met without opening it.

**Why this priority**: It supports multi-hotel users, but suspension's core works without it.

**Independent Test**: Call `GET /api/hotel` as an admin, an employee with and without `hotels.view`, and users in several hotels.

**Acceptance Scenarios**:

1. **Given** an admin, **When** they call `GET /api/hotel`, **Then** they get their reachable, non-suspended hotels only. **[V4]**
2. **Given** an employee with a role granting `hotels.view`, **When** they call it, **Then** they get their reachable, non-suspended hotels only, each as a short record of `id`, `name`, `slug`, `timezone`, `currency` and `city`.
3. **Given** an employee without it, including one with no role, **When** they call it, **Then** they get `403`.
4. **Given** an employee holding `hotels.view`, **When** they call `GET` or `PUT /api/hotel/{id}`, **Then** the rules are unchanged: only the owning admin passes.
5. **Given** a hotel from another customer, **When** anyone but a super admin lists hotels, **Then** it does not appear.

---

### User Story 8 - The operator can see and find what is suspended (Priority: P3)

The super admin needs to find suspended customers, and to see why each was stopped.

**Why this priority**: Suspension works without it, but operating it across customers would need SQL.

**Independent Test**: Suspend one account and another account's hotel, then filter both lists and read the records.

**Acceptance Scenarios**:

1. **Given** suspended and active accounts, **When** a super admin filters with `filter[suspended]=true` or `false`, **Then** they get the matching accounts.
2. **Given** a hotel in a suspended account, **When** hotels are filtered with `filter[suspended]=true`, **Then** that hotel is included, and its record shows it counts as suspended even though its own suspension fields are empty. **[V5]**
3. **Given** a non-boolean `filter[suspended]` value, **When** it is sent, **Then** it gets `422`. **[V5]**

---

### Edge Cases

- **Suspending a hotel in an already suspended account**: allowed, and recorded on the hotel, so it stays suspended after the account returns. **[V6]**
- **Reactivating a hotel in a suspended account**: clears the hotel's own fields, and the hotel still counts as suspended, with scope `account`. **[V6]**
- **Reactivating something not suspended in its own right**: `422`, with no audit event. **[V6]**
- **A soft-deleted account or hotel in the path**: `404`, as on the other super admin endpoints.
- **Restoring a soft-deleted suspended hotel, or moving a hotel**: a restored hotel keeps its suspension. A hotel moved into a suspended account counts as suspended, and one moved out keeps only its own suspension.
- **Creating a hotel or user in a suspended account**: allowed. They count as suspended from the start.
- **A soft-deleted account that was suspended**: still counts as suspended for anyone it can still reach. **[V11]**
- **A failure handler that itself fails**: the error is reported, the job still leaves the queue, and no failed-job record is written. **[V7]**
- **A job whose record has since been deleted**: runs as today, and finds nothing to do.
- **Message from a sender whose hotel can't be found but whose account is suspended**: gets the fixed reply, with no AI run. **[V10]**
- **The rate-limit notice on WhatsApp**: a sender over the per-minute limit may still get that fixed notice, which involves no AI and no hotel check.
- **A user whose only link is a soft-deleted default hotel in a suspended account**: they are told the account is suspended.
- **Invalid body on `PUT /api/hotel/{id}` for a suspended hotel**: the validation `422` may come first, and reveals nothing about suspension.
- **Concurrent suspensions of one record**: only one succeeds; the other gets the already-suspended `422`.

## Requirements *(mandatory)*

### Functional Requirements

**Suspending and reactivating**

- **FR-001**: The system MUST let a super admin suspend and reactivate an account, and suspend and reactivate a single hotel.
- **FR-002**: Suspending MUST require a reason, and MUST record who, when and why on the account or hotel.
- **FR-003**: Suspending something already suspended in its own right MUST return `422` and leave the recorded details unchanged. Reactivating something not suspended in its own right MUST return `422`. **[V6]**
- **FR-004**: Reactivating MUST clear the recorded details, and data of any kind MUST be kept across suspension and reactivation.
- **FR-005**: Account and hotel suspensions MUST be independent. Changing one MUST NOT change the other's recorded details. **[V6]**
- **FR-006**: Suspension state MUST be stored separately from `is_active`, MUST NOT be writable through any tenant request, and MUST be written by a single platform service only.
- **FR-007**: Each suspend and reactivate MUST write a platform audit event (`platform.account.suspended`, `platform.account.reactivated`, `platform.hotel.suspended`, `platform.hotel.reactivated`) with no hotel attached, naming the super admin. The suspend event MUST include the reason. **[V8]**
- **FR-008**: A hotel MUST count as suspended when it or its account is suspended. This one rule MUST decide every check below.
- **FR-009**: Suspension state MUST NOT be cached. A change MUST apply from the next API request or job start.

**Login and API access**

- **FR-010**: Login MUST check the password first, and MUST refuse with the suspended response and issue no token when the user's account is suspended, or when they have at least one hotel and all are suspended. Super admins MUST NOT be checked.
- **FR-011**: When a login succeeds and the user's default hotel is suspended, the `hotel` in the response MUST be an active hotel of theirs.
- **FR-012**: Every hotel API route MUST apply the suspension check, except `POST /api/logout` and the super admin routes. `GET /api/user` MUST apply it, and MUST return the same current hotel as login.
- **FR-013**: The suspended response MUST be `403` with `body.reason = "suspended"` and `body.scope` of `account` or `hotel`, with the message "This account has been suspended." or "This hotel has been suspended." All three places that return it MUST produce the same shape.
- **FR-014**: A request MUST get the suspended response when the user's account is suspended, when suspended hotels were removed from their hotels and none remain, or when `X-Hotel-Id` names a suspended hotel they can reach. `scope` MUST be `account` when the account is suspended, and `hotel` otherwise.
- **FR-015**: An `X-Hotel-Id` naming a hotel the user can't reach MUST behave as it does today.
- **FR-016**: Suspended hotels MUST be left out of what the user can reach for the rest of the request. If the current hotel is removed, the first remaining hotel MUST become current.
- **FR-017**: When a user's default hotel is suspended, every part of the request MUST treat their hotel as the current one, without changing their stored default. **[V12]**
- **FR-018**: An employee whose default hotel is suspended MUST have no access to the account's other hotels, unless they were explicitly assigned to that hotel. An assigned employee MUST keep the permissions of their staff role there, even though the role belongs to the suspended hotel. **[V3]**
- **FR-019**: A user with no hotels MUST get today's behaviour, unless their account is suspended. **[V11]**
- **FR-020**: Super admins MUST NOT be affected by any suspension check on any route, and MUST be able to read any hotel, suspended or not. **[V2]**
- **FR-021**: The check MUST run before a record in the URL is looked up, so a suspended hotel's record id is not found.
- **FR-022**: The check MUST include a branch that lets an impersonation session read suspended hotels and write only to active ones, which stays inactive until impersonation exists (stage 5), and MUST NOT fail in the meantime.

**A hotel's own routes**

- **FR-023**: `GET` and `PUT /api/hotel/{id}` MUST return the suspended `403` only when the hotel is really suspended and the caller passed normal authorization. Any other caller MUST get the normal answer. **[V2]**

**Hotel list**

- **FR-024**: `GET /api/hotel` MUST be gated by a new `hotels.view` permission. Admins hold it automatically. Employees get it only through a staff role, and it MUST NOT be an employee default. **[V4]**
- **FR-025**: For anyone but a super admin, the list MUST contain only the hotels the caller can reach and that are not suspended.
- **FR-026**: `hotels.view` MUST open only the list. It MUST NOT change who can view or update a single hotel.
- **FR-026a**: An employee MUST see each listed hotel as a short record of `id`, `name`, `slug`, `timezone`, `currency` and `city`. Admins MUST see the full record. **[V16]**
- **FR-026b**: An employee's search, filter and sort on the list MUST be limited to the short record's fields, so hidden settings can't be worked out from the results. **[V16]**
- **FR-026c**: Super admin and employee APIs MUST stay separate. Super admins read hotels and suspension details through `api/superadmin`, and no super admin feature MUST be added to the tenant routes. Their existing access to those routes stays until stage 6 removes it.
- **FR-027**: The new permission MUST appear in the permission reference and the role editor's list, and the texts that call hotel settings admin-only MUST name this read as the exception. **[V4]**

**Background work**

- **FR-028**: Every job that acts on one hotel MUST check suspension when it starts, and a blocked job MUST NOT do its work, MUST leave the queue, and MUST NOT write a failed-job record or set off the repeated-failure alert.
- **FR-029**: A blocked job with a failure handler MUST have it run once, with an error identifying the suspension. A handler that fails MUST NOT change the outcome in FR-028. **[V7]**
- **FR-030**: A job whose hotel is empty MUST run.
- **FR-031**: Jobs that loop over hotels (proactive triggers, housekeeping day, recommendation expiry, recommendation outcomes) MUST skip suspended hotels, including when asked about one specific hotel. Guest memory retries and knowledge purges MUST leave out the rows of suspended hotels. Purges MUST still remove global documents and those of soft-deleted hotels.
- **FR-032**: Skipped work MUST NOT be replayed, and no catch-up MUST be built. A proactive message left scheduled MAY be sent late if it passes the normal send-time checks.
- **FR-033**: A knowledge rebuild MUST still finish when items of suspended hotels are blocked, counting them as failed. **[V9]**
- **FR-034**: Platform bookkeeping and cleanup jobs (AI cost alerts, usage counter rebuild, expired import preview removal) and the plain WhatsApp sender MUST NOT be gated, each for a stated reason.

**WhatsApp**

- **FR-035**: An inbound message for a suspended hotel, from a guest or an admin, MUST get the fixed reply after the not-paired and opt-out checks and before any AI run. No AI run or AI usage MUST be recorded. **[V10]**
- **FR-035a**: The WhatsApp advisor MUST NOT move to another hotel. An admin whose default hotel is suspended MUST get the fixed reply, even with other active hotels, until that hotel is reactivated. **[V10]**
- **FR-035b**: A guest MUST get the fixed reply in their known language, English or Arabic, defaulting to English. An admin MUST get it in English. The English text MUST stay the same as the existing unavailable reply. **[V17]**
- **FR-036**: The fixed reply MUST be sent at most once per 24 hours per hotel and phone number. Later messages MUST stay stored, with no reply. A retried send of a reply already decided MUST still go out.
- **FR-037**: STOP and other contact-preference keywords MUST keep working during a suspension.

**Super admin visibility**

- **FR-038**: The super admin account and hotel responses MUST include who suspended the record, when and why. These fields MUST NOT appear on any tenant response. **[V1]**
- **FR-039**: A super admin's hotel response MUST also show whether the hotel counts as suspended, including through its account. **[V5]**
- **FR-040**: The account and hotel lists MUST accept a boolean `filter[suspended]`. For hotels it MUST follow FR-008. **[V5]**

**Protection against drift**

- **FR-041**: A route test MUST fail when any authenticated route, other than the super admin routes and the two logout routes, lacks the suspension check. **[V13]**
- **FR-042**: A job inventory test MUST fail when a queued job is not gated, not a hotel-looping job that filters suspended hotels, or not on an exempt list with a stated reason.
- **FR-043**: Error monitoring events from a blocked request or job MUST carry `suspended=true`, and errors in a request MUST name the hotel it actually ran in. **[V14]**

**Documentation**

- **FR-044**: The API documentation MUST cover the new endpoints, the suspended response on login and hotel routes, the hotel list and `hotels.view`, and the fixed reply. The new `403` shape and the list's new audience MUST be listed as changes every client must handle. The response's `hotel_id` can differ from `hotel.id` and this MUST be documented. **[V12]**

### Endpoint Map

| Method and path | Who | Result |
| --- | --- | --- |
| `POST /api/superadmin/accounts/{account}/suspend` | Super admin | `200` with the account, `422` if no reason or already suspended |
| `POST /api/superadmin/accounts/{account}/reactivate` | Super admin | `200` with the account, `422` if not suspended |
| `POST /api/superadmin/hotels/{hotel}/suspend` | Super admin | `200` with the hotel, `422` if no reason or already suspended in its own right |
| `POST /api/superadmin/hotels/{hotel}/reactivate` | Super admin | `200` with the hotel, `422` if not suspended in its own right |
| `GET /api/superadmin/accounts`, `GET /api/superadmin/hotels` | Super admin | Adds `filter[suspended]` and the suspension fields |
| `POST /api/login` | Anyone | Adds the suspended `403`, after the password check |
| `GET /api/hotel` | Holders of `hotels.view` | Changes: was super admin only, now limited to the caller's non-suspended hotels. Employees get a short record |
| `GET`, `PUT /api/hotel/{id}` | Unchanged | Adds the suspended `403`, after authorization |
| Every other hotel API route | Unchanged | Adds the suspended `403`, except `POST /api/logout` |

### Key Entities

- **Account suspension**: on an account, who suspended it, when and why. Empty means the account is not suspended in its own right.
- **Hotel suspension**: the same details on a hotel. A hotel counts as suspended through its own details or its account's.
- **Suspended response**: the fixed refusal, carrying a reason and a scope of account or hotel.
- **Platform audit event**: the existing record of super admin actions, with no hotel attached, so hotel staff can't see it.
- **`hotels.view` permission**: lets an employee, through a staff role, list their hotels.
- **Fixed-reply limit**: a 24-hour marker per hotel and phone number, so a guest is told once a day.
- **Default hotel**: the one hotel stored on a user, which is where they work unless they choose another.
- **Assigned hotel**: a further hotel a user has been explicitly given access to, by a membership record.

## Success Criteria *(mandatory)*

### Measurable Outcomes

- **SC-001**: From the moment of suspension, 100% of hotel API requests from affected users, other than logout, are refused on their next call, with no waiting period.
- **SC-002**: During a suspension, a suspended hotel records zero AI runs from guest messages, staff messages or background work started after the suspension.
- **SC-003**: Blocked jobs add zero failed-job records and zero repeated-failure alerts.
- **SC-004**: A multi-hotel user with one suspended hotel can complete their normal work in an active hotel with no change on their side, including when the suspended hotel is their default.
- **SC-005**: After reactivation, 100% of the customer's records are present and existing tokens work on the next request, with no operator step beyond the reactivate call.
- **SC-006**: A guest gets at most one fixed reply per 24 hours per hotel, and 100% of opt-out requests are honored during a suspension.
- **SC-007**: A user with no link to a suspended hotel can't tell that it is suspended from any response.
- **SC-008**: A new route or job that misses the suspension check fails the test suite, by name.
- **SC-009**: A super admin can suspend or reactivate a customer in one request, with no database access.

## Assumptions

- **Dev stage**: test users only, so rollback, backfills and deploy coordination are out of scope.
- **Out of scope**: the dashboard (stage 4); impersonation endpoints and tokens (stage 5); removing the super admin bypasses (stage 6); and gating queued notifications and pairing codes, which are accepted risks R11 and R12. A suspended customer registering a new, empty account is accepted risk R7.
- **Who can assign an employee to another hotel**: today only a super admin can, through `PUT /api/superadmin/users/{user}/access`. Hotel admins have no endpoint for it, and this feature adds none.
- **One account per user**: stage 2 refuses links that reach across accounts, so a user's hotels belong to one account.
- **Usage figures**: `GET /api/usage` is unchanged. Its totals and hotel count include a suspended hotel, and it names no hotel. **[V15]**
- **API message language**: the suspended API messages are English only, because API messages have no language selection today. Clients can show their own translation by reading `body.reason` and `body.scope`.
- **Expired import previews** of a suspended hotel are still removed, since they are temporary uploads holding guest data rather than kept records.
- **Frontend**: its changes follow this work, from a changes document, and it must handle the new `403` shape.
