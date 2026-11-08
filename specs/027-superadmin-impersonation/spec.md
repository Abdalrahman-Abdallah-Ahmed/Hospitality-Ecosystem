# Feature Specification: Super Admin Impersonation

**Feature Branch**: `027-superadmin-impersonation`

**Created**: 2026-11-08

**Status**: Draft

**Input**: User description: "Stage 5 of docs/designs/super-admin-platform.md: time-limited impersonation of a hotel user by a super admin, with every action attributed to the real super admin. A start endpoint issues a 30-minute token for the target user, refuses a super admin target, and cannot be extended. An end endpoint and logout end the session and record an end event; expiry writes no event, and expired tokens are pruned hourly. GET /api/user reports the impersonation. Audited writes in the request record the super admin as actor with the user in context; jobs the request dispatched stay recorded as the hotel user (accepted risk R8). Sentry is tagged. A new not_impersonating middleware refuses POST /api/connect and every write to /api/users and /api/staff-roles, before route-model binding. In a suspended hotel the token may GET and HEAD, keeping its scope, and otherwise follows the normal-user path, reusing ImpersonatedRead. Removing the super admin bypasses, carrying the impersonator into queued jobs, extending sessions, frontend work, rollback, backfills and deploy coordination are out of scope. The Stage 5 plan must be verified against the code, and any mismatch reported."

## Problem and Need

When a hotel reports a problem, a super admin cannot see what that hotel's user sees. Today they use the super admin bypasses, which show a different view from the user's and record the write as their own with nothing naming the user they were helping. Stage 6 removes those bypasses, so support needs a proper replacement first.

This feature lets a super admin act as a hotel user for at most 30 minutes, with that user's permissions and hotels. The audit trail names the real super admin, and the session cannot be used to gain lasting access to the user's account.

**Actors**:

- **Super admin**: the platform operator, who starts and ends sessions. Any super admin may end any session.
- **Impersonated user**: any user who is not a super admin. They take no part in the session.
- **Impersonation session**: what the super admin holds. It acts as the user, with the limits below.
- **Frontend application**: the API client. It shows a banner from the session details in `GET /api/user`.

Requirements tagged **[V1]** to **[V24]** settle a point where the design's Stage 5 plan disagrees with the code or leaves a gap. The evidence and the chosen default for each are in [plan-verification.md](plan-verification.md).

## Clarifications

### Session 2026-11-08

- Q: When a super admin changes a record through a session and that record type isn't audited, what should happen? → A: During a session only, any write to hotel settings, teams, task categories, activity categories or hotel policies records one event naming the super admin, the user and the record, with the changed field names but not their values.
- Q: Should a session be refused when it tries to add a user to a team through `POST /api/team/{team}/members`? → A: Yes, with `403`, like writes to `/api/users`. A super admin sets a user's team through `PUT /api/superadmin/users/{user}/access`.
- Q: When a session changes a hotel's records, what should that hotel's staff see about the super admin in the record's history? → A: Only the super admin's id and name, with the IP left out of the context. The stored row is unchanged.
- Q: Should a session be able to use the AI advisor, given that its conversation and pending confirmations belong to the target user? → A: No. `POST /api/ai-advisor/chat` is refused with `403` during a session.
- Q: How should a super admin who didn't start a session end it without unpairing the user's WhatsApp device? → A: Add no listing, and store `token_id` on both events. A new `POST /api/superadmin/users/{user}/end-impersonation` ends only that user's impersonation tokens, with one end event per session. Login tokens and the device stay, and `revoke-tokens` doesn't change.

## User Scenarios & Testing *(mandatory)*

### User Story 1 - Start a session and see what the user sees (Priority: P1)

A super admin starts a session for a hotel user and uses the hotel endpoints with that user's permissions and hotels.

**Why this priority**: This is the feature's purpose, and stage 6 depends on it.

**Independent Test**: Start a session for an employee with a known staff role, then call an endpoint the role allows and one it doesn't.

**Acceptance Scenarios**:

1. **Given** a super admin and a hotel admin, **When** the super admin calls `POST /api/superadmin/users/{user}/impersonate`, **Then** the response gives `token`, `token_id` and `expires_at`, 30 minutes ahead.
2. **Given** a session for an employee whose role grants one permission, **When** the token calls an endpoint needing it and one needing another, **Then** the first succeeds and the second returns `403`.
3. **Given** a session for a user of hotel A, **When** the token reads a record of hotel B they cannot reach, **Then** the response is `404`.
4. **Given** a session for an account owner, **When** the token sends `X-Hotel-Id` naming any hotel of the account, **Then** the request works in that hotel. A hotel outside the account returns `403`.
5. **Given** a session for an admin who reaches two hotels, **When** the token sends no `X-Hotel-Id`, **Then** the request works in their default hotel, as it does for them.
6. **Given** a session for an employee who reaches two hotels, **When** the token lists records, **Then** they see both hotels, as they do themselves.
7. **Given** the target is a super admin, including the caller, **When** a session is requested, **Then** the response is `422` and no token exists.
8. **Given** the id of a user who does not exist, **When** a session is requested, **Then** the response is `404`.
9. **Given** an admin or employee, **When** they call any of the three super admin endpoints, **Then** the response is `403`.
10. **Given** an impersonation token, **When** it calls any `api/superadmin` route, including starting another session, **Then** the response is `403`.
11. **Given** a session is running, **When** `GET /api/user` is called with the token, **Then** the response is that user's record plus `impersonation: {impersonator: {id, name}, expires_at}`.
12. **Given** the user's own token, **When** `GET /api/user` is called, **Then** the response has no `impersonation` key.
13. **Given** a session is running, **When** a list of users that includes the impersonated user is read, **Then** no entry has an `impersonation` key.

---

### User Story 2 - Sessions end on time and on request (Priority: P1)

A session stops working after 30 minutes and can be ended sooner. It cannot be extended.

**Why this priority**: This time limit is the main defence if a token is leaked (risk R1).

**Independent Test**: Start a session, move time past 30 minutes and call an endpoint with the token. In a second session, end it and call again.

**Acceptance Scenarios**:

1. **Given** a session 29 minutes old, **When** the token is used, **Then** it works.
2. **Given** a session past 30 minutes old, **When** the token is used on any endpoint, **Then** the response is `401`, and no end event is written.
3. **Given** a running session, **When** any super admin calls `POST /api/superadmin/impersonation/end` with its `token_id`, **Then** the token is deleted, the token returns `401`, and `platform.impersonation.ended` is recorded.
4. **Given** the `token_id` of a login token, a WhatsApp pairing code, or no token at all, **When** the end endpoint is called, **Then** the response is `404`, and that token still exists.
5. **Given** the session has already ended, **When** the end endpoint is called again, **Then** the response is `404` and no second event is written.
6. **[V8]** **Given** a session past its expiry whose row is not pruned yet, **When** the end endpoint is called, **Then** the response is `404` and no event is written, because an expired session writes none.
7. **[V9]** **Given** a running session whose user was then deleted, **When** the end endpoint is called, **Then** the response is `404` with no event and no server error. The token already returns `401`.
8. **Given** a running session, **When** `POST /api/logout` is called with the token, **Then** the token is deleted and the same end event is recorded.
9. **Given** an expired token, **When** `POST /api/logout` is called with it, **Then** the response is `401` and no event is written.
10. **Given** a running session, **When** a new session is started for the same user, **Then** the new token has its own full 30 minutes, and the first token's expiry does not change.
11. **Given** tokens expired more than an hour ago, **When** the hourly prune runs, **Then** they are removed, whatever their kind, and unexpired tokens remain.
12. **[V10]** **Given** a user with two running sessions, a login token and a paired WhatsApp device, **When** any super admin calls `POST /api/superadmin/users/{user}/end-impersonation`, **Then** both sessions end, with one end event each naming the caller as actor, and the response gives `ended: 2`. The login token still works and the device stays paired.
13. **[V10]** **Given** a user with no running session, or only an expired one, **When** that endpoint is called, **Then** the response is `200` with `ended: 0`, and no event is written.
14. **[V10]** **Given** an unknown user or a super admin as the target, **When** that endpoint is called, **Then** the response is `404`, as on the other user administration endpoints.
15. **[V10]** **Given** a running session and a paired device, **When** a super admin calls `POST /api/superadmin/users/{user}/revoke-tokens`, **Then** all the user's tokens are deleted and the device is unpaired, as today.

---

### User Story 3 - The audit trail names the real super admin (Priority: P1)

Every audited write in a session's request records the super admin as actor and names the impersonated user, so nobody mistakes it for the user's own work.

**Why this priority**: The design rejects impersonation without this (Alternative I).

**Independent Test**: Start a session, create an audited record with the token, and read its event row.

**Acceptance Scenarios**:

1. **Given** a session, **When** the token creates a guest, **Then** the row's actor is the super admin, `actor_kind` is `user`, `context.impersonated_user_id` is the user, and `hotel_id` is the guest's hotel.
2. **Given** the user's own token, **When** they create a guest, **Then** the actor is the user and `context` has no `impersonated_user_id`.
3. **[V1]** **Given** a session, **When** a platform event is recorded in a request with the token, **Then** it follows the same actor rule as a hotel event, with `hotel_id = null`.
4. **Given** a session, **When** an AI tool writes during its request, **Then** the row's actor is the super admin, `actor_kind` stays `ai_agent`, and `context.impersonated_user_id` is set.
5. **Given** a session is started, **Then** `platform.impersonation.started` is recorded with the super admin as actor, the target user as subject, `hotel_id = null`, and `context` holding `impersonated_user_id`, `expires_at` and `token_id`, but never the token.
6. **Given** a session is ended by any super admin through the end endpoint, **Then** `platform.impersonation.ended` names that super admin as actor, the target user as subject, `hotel_id = null`, and `context` holds `impersonated_user_id` and `token_id`.
7. **Given** a session is ended by logout, in a request where a current hotel is set, **Then** the end event has `hotel_id = null`, the super admin as actor, the target user as subject, and the same `context`.
8. **Given** any start or end event, **When** a hotel's staff read their history endpoints, **Then** the event does not appear.
9. **Given** a session starts a job that writes after the request ended, **Then** that write is recorded as the hotel user (accepted risk R8).
10. **Given** a session, **When** an error is reported to Sentry with a DSN configured, **Then** the scope has `impersonating = 'true'` and `impersonator_id`.
11. **[V13]** **Given** a process that served a session's request, **When** it then serves a normal request, **Then** neither tag is left on the scope.
12. **Given** no DSN is configured, **When** a session is used, **Then** nothing is tagged and nothing fails.
13. **[V6]** **Given** a session, **When** the token updates hotel settings, or creates, updates or deletes a team, task category, activity category or hotel policy, **Then** one event is recorded with the super admin as actor, `context.impersonated_user_id` set, the record as subject, its hotel as `hotel_id`, and the changed field names but not their values.
14. **[V6]** **Given** the user's own token, **When** they make the same write, **Then** no such event is recorded.
15. **[V6]** **Given** a session, **When** a write to one of those records changes nothing or is refused, **Then** no event is recorded.
16. **[V7]** **Given** a history row whose actor is a super admin, **When** a reader who is not a super admin reads it, including through an impersonation token, **Then** the actor shows only `id` and `name`, and `context` has no `ip`. `context.impersonated_user_id` is still shown.
17. **[V7]** **Given** the same row, **When** a super admin reads it with their own token, or the stored row is inspected, **Then** it is complete, with the IP.
17a. **[V24]** **Given** a session, **When** the token deletes a team or task category that is one of the hotel's operational defaults, **Then** a `hotel.updated` event also names the cleared setting by field name.
18. **[V7]** **Given** a history row whose actor is a hotel user, **When** hotel staff read it, **Then** it is unchanged.

---

### User Story 4 - A session cannot gain lasting access (Priority: P1)

A session cannot set a password, change an email, change a role or membership, issue another credential or pair a WhatsApp device.

**Why this priority**: Without this, 30 minutes could become permanent access to the user's account (risk R1).

**Independent Test**: With an owner's session, send every refused method to every refused route, including a password change, and check that nothing changed.

**Acceptance Scenarios**:

1. **Given** a session, **When** the token calls `POST /api/connect`, **Then** the response is `403` and no pairing code exists.
2. **Given** a session for an admin, **When** the token calls `PUT /api/users/{self}` with `password`, **Then** the response is `403` and the user's password still works.
3. **Given** a session for an account owner, **When** the token calls `PUT /api/users/{staff}` with `password` or `email`, **Then** the response is `403` and the staff member is unchanged.
4. **Given** a session, **When** the token calls `POST`, `PUT`, `PATCH` or `DELETE` on `/api/users`, **Then** every response is `403`.
5. **Given** a session, **When** the token calls `POST`, `PUT`, `PATCH` or `DELETE` on `/api/staff-roles`, **Then** every response is `403`.
6. **[V5]** **Given** a session, **When** the token calls `POST /api/team/{team}/members`, **Then** the response is `403` and the user's team is unchanged.
6a. **[V15]** **Given** a session, **When** the token calls `POST /api/ai-advisor/chat`, **Then** the response is `403`, and no message or pending confirmation is stored in the user's conversation.
7. **Given** a session, **When** a refused write names an id that does not exist or belongs to another hotel, **Then** the response is still `403`, because the check runs before the record is looked up.
8. **Given** a session for an admin, **When** the token reads `/api/users` or `/api/staff-roles`, **Then** the reads work as they do for the admin.
9. **Given** the admin's own token, **When** they make any of these writes, **Then** their usual rules apply and nothing is refused because of this feature.
10. **Given** a super admin's own token, **When** they use these routes, **Then** behaviour is unchanged until stage 6.

---

### User Story 5 - Support a suspended hotel without changing it (Priority: P2)

A super admin can read a suspended hotel's data through a session, and cannot change it.

**Why this priority**: Support often has to look into a suspended customer's data, but suspension must still stop every write.

**Independent Test**: Suspend a hotel, then send a `GET` and a `POST` with a session for its user.

**Acceptance Scenarios**:

1. **Given** a session for a user whose only hotel is suspended, **When** the token sends a `GET` or `HEAD`, **Then** the hotel's data is returned.
2. **Given** the same session, **When** the token sends any other method, **Then** the response is the suspended `403`.
3. **Given** a session for a user of a suspended account, **When** the token sends a `GET`, **Then** data is returned. **When** it sends a `POST`, **Then** the response is the suspended `403` with `body.scope = "account"`.
4. **Given** a session in a suspended hotel, **When** the token calls `GET /api/hotel/{hotel}` for it, **Then** the hotel is returned.
5. **Given** a session, **When** the token calls `POST /api/logout` in a suspended hotel, **Then** the session ends and the event is recorded.

For a target with default hotel A suspended and hotel B active:

6. **Given** no header, **When** the token sends a `GET`, **Then** it reads A, because a read keeps the whole scope.
7. **[V2]** **Given** no header, **When** the token sends a `POST`, **Then** the record is created in B and A is unchanged, as for a normal user.
8. **Given** `X-Hotel-Id: A`, **When** the token sends a `GET`, **Then** it reads A. **When** it sends a `POST`, **Then** the response is the suspended `403` with `body.scope = "hotel"`.
9. **Given** `X-Hotel-Id: B`, **When** the token sends a `POST`, **Then** it succeeds in B.
10. **[V3]** **Given** `X-Hotel-Id: B`, **When** the token sends a `PUT` for a record of A, **Then** the response is `404` and the record is unchanged.
11. **[V3]** **Given** `X-Hotel-Id: B`, **When** the token sends `PUT /api/hotel/{A}`, **Then** the response is the suspended `403` and the hotel is unchanged.

---

### Edge Cases

- **A target with no hotels**: a session can start. Hotel lists are empty, and a write gets what the user would get, including the suspended `403` when their account is suspended.
- **Several sessions at once**: allowed, for the same user and from different super admins. Each has its own token and events.
- **A session in progress when a hotel is suspended**: reads go on working and writes stop on the next request.
- **The super admin who started a session is deleted**: the token goes with them, and no end event is written.
- **The impersonated user's tokens are revoked, or they reset their password**: the session ends at once. No `platform.impersonation.ended` is written ([V11]).
- **A refused write while the hotel is suspended, or with an unreachable `X-Hotel-Id`**: those checks answer first with their own `403`. The write is refused either way ([V14]).
- **Revoking a super admin's own tokens**: does not end sessions they started, which run until they expire or are ended ([V12]).
- **A token with an `expires_at` and a lifetime limit**: the earlier limit wins. 30 minutes is well under the platform's token lifetime.
- **An impersonation token presented as a WhatsApp pairing code**: refused, because only pairing codes are redeemable.

## Requirements *(mandatory)*

### Functional Requirements

**Starting a session**

- **FR-001**: A super admin MUST be able to start a session for any user who is not a super admin through `POST /api/superadmin/users/{user}/impersonate`. It returns `token`, `token_id` and `expires_at`.
- **FR-002**: The session's token MUST belong to the target user, be named `impersonation`, name the super admin who started it, and expire 30 minutes after it is issued.
- **FR-003**: A super admin target MUST be refused with `422`, and no token or event created.
- **FR-004**: The token MUST NOT be stored anywhere readable, and MUST NOT appear in any event, log or error tag. It is returned once.
- **FR-005**: There MUST be no way to extend a session. More time means starting a new session.
- **FR-006**: The three super admin endpoints MUST refuse everyone else with `403`, an impersonation token included.

**Acting as the user**

- **FR-007**: While a session runs, hotel endpoints MUST apply the target user's permissions, hotels and `X-Hotel-Id` rules, exactly as for their own token, except as FR-020 to FR-025 state.
- **FR-008**: `GET /api/user` MUST add `impersonation: {impersonator: {id, name}, expires_at}` when the current token is an impersonation token, and MUST NOT include the key otherwise.
- **FR-009**: The `impersonation` key MUST appear on no other response. The shared user record does not change.

**Ending a session**

- **FR-010**: Any super admin MUST be able to end a running session through `POST /api/superadmin/impersonation/end` with `token_id`. This deletes the token and records the end event.
- **FR-011 [V8] [V9]**: The end endpoint MUST return `404`, with no event and no change, for an id that is not a running impersonation session: unknown, not an impersonation token, already expired, or for a user who no longer exists.
- **FR-011a [V10]**: Any super admin MUST be able to end all of one user's running sessions, without knowing a `token_id`, through `POST /api/superadmin/users/{user}/end-impersonation`. It records one end event per session and returns the number ended. It MUST NOT touch that user's other tokens or WhatsApp device, and MUST write nothing for an expired session. `revoke-tokens` MUST NOT change.
- **FR-012**: `POST /api/logout` with an impersonation token MUST delete it and record the same end event. Logout with any other token is unchanged and records nothing.
- **FR-013**: A token past its expiry MUST return `401` on every endpoint. Expiry MUST write no event.
- **FR-014**: Expired tokens of every kind MUST be pruned hourly, one hour after they expire, and the scheduled task listed in the entry-point inventory.

**Attribution**

- **FR-015 [V1]**: While the current token is an impersonation token, every event recorded in the request, hotel or platform, MUST have the super admin as actor and `context.impersonated_user_id` set to the target user. This is decided in the one place events are written, so no caller changes.
- **FR-016**: The events MUST be `platform.impersonation.started` and `platform.impersonation.ended`, with the target user as subject and `hotel_id = null`, whichever hotel the request works in.
- **FR-017 [V10]**: Both events MUST carry `context.impersonated_user_id` and `context.token_id`. The start event MUST also carry `context.expires_at`. These are set explicitly, because the super admin endpoints run on the super admin's own token.
- **FR-018**: `actor_kind` MUST be unchanged: `user` for a person's request and `ai_agent` for an AI write.
- **FR-019**: Writes made later by jobs the request dispatched MUST stay recorded as the hotel user. The impersonator is not carried into queued jobs (accepted risk R8).
- **FR-019a [V6]**: Attribution applies to what is recorded as an event. Other "who did this" fields, such as a task's creator or an import's uploader, keep the target user. See Known Limits.
- **FR-019b [V6]**: During a session, each successful write that changes hotel settings, a team, a task category, an activity category or a hotel policy MUST record one event following FR-015, besides any named event that write already records, with the record as subject, its hotel as `hotel_id`, and the changed field names but not their values. When a session deletes a team or task category that a hotel setting points at, the database clears that setting, and a `hotel.updated` event MUST name it. The same writes by any other token MUST record nothing new.
- **FR-019c [V7]**: When a reader who is not a super admin reads an event whose actor is a super admin, the response MUST show only the actor's `id` and `name` and MUST leave `ip` out of `context`. This covers every tenant endpoint that returns event rows. Stored rows, and what a super admin's own token reads, MUST NOT change.

**What a session cannot do**

- **FR-020**: An impersonation token MUST get `403` on `POST /api/connect`.
- **FR-021**: An impersonation token MUST get `403` on every write method of `/api/users` and `/api/staff-roles`: `POST`, `PUT`, `PATCH` and `DELETE`.
- **FR-022 [V5]**: An impersonation token MUST get `403` on `POST /api/team/{team}/members`, which edits a user.
- **FR-022a [V15]**: An impersonation token MUST get `403` on `POST /api/ai-advisor/chat`, because the conversation and any write waiting for confirmation belong to the user.
- **FR-022b [V20]**: During a session, any attempt to create, change or delete a user, or to issue a token, MUST be refused wherever it comes from, including a route or tool without the refusal above.
- **FR-023**: These refusals MUST be decided before the addressed record is looked up, so the answer doesn't depend on whether the id exists.
- **FR-024 [V4]**: These refusals MUST apply to every target, whatever that user's own rights. A request with `password` is refused without being read.
- **FR-025**: Reads of those routes MUST keep working under the target user's permissions. Every other token MUST be unaffected.

**Suspended hotels**

- **FR-026**: In a suspended hotel or account, an impersonation token MUST be able to `GET` and `HEAD`, keeping its full scope, suspended hotels included. The existing read exception (`ImpersonatedRead`) MUST be the only check, with no second one added.
- **FR-027**: Any other method MUST follow the normal user's path: the suspended `403` when the named or only hotel is suspended, otherwise the request narrows to the active hotels. A token can write only to active hotels.
- **FR-028 [V3]**: A write addressed to a suspended hotel's record while the request works in an active hotel MUST leave it unchanged: `404` for a hotel-owned record, and the suspended `403` for the hotel's own record.

**Observability and documentation**

- **FR-029 [V13]**: Requests with an impersonation token MUST tag the Sentry scope with `impersonating = 'true'` and `impersonator_id`, through the existing tagging helper. Other requests that reach the tenant middleware MUST clear both, so a long-running process does not carry them over. Requests that don't reach it, such as login, are left as they are, the same as the existing `hotel_id` tag.
- **FR-030**: Every API change MUST update the matching docs: a new `docs/superadmin-impersonation-api-documentation.md`, plus `docs/auth-api-documentation.md`, `docs/whatsapp-device-api-documentation.md`, `docs/user-management-api-documentation.md` and `docs/staff-roles-api-documentation.md`. The team endpoint's documentation gets the same note.
- **FR-031**: A `docs/latest-changes-<date>.md` entry MUST list what the frontend needs, because the frontend work follows from it.

**Tests**

- **FR-032**: Tests MUST cover every acceptance scenario, specifically: expiry by moving time; every refused write, including `PUT /users/{id}` with `password`; actor and `impersonated_user_id` on a write; the logout end event with `hotel_id = null`; a target with suspended default hotel A and active hotel B; and the Sentry tags with a DSN configured.
- **FR-033**: Each new tenant-reachable route, scheduled task and refusal MUST satisfy the entry-point inventory and the super admin route guard tests.

### Endpoint Map

| Method | Path | Who | Result |
| --- | --- | --- | --- |
| POST | `/api/superadmin/users/{user}/impersonate` | Super admin | `200` with `token`, `token_id`, `expires_at`. `422` for a super admin target. `404` for an unknown user. |
| POST | `/api/superadmin/impersonation/end` | Super admin | Body `{token_id}`. `200`, or `404` if it isn't a running session. |
| POST | `/api/superadmin/users/{user}/end-impersonation` | Super admin | `200` with `ended`, the number of sessions ended, which may be `0`. `404` for an unknown user or a super admin. |
| POST | `/api/logout` | Any token | Unchanged, plus the end event for an impersonation token. |
| GET | `/api/user` | Any token | Unchanged, plus `impersonation` for an impersonation token. |
| POST | `/api/connect` | Impersonation token | `403`. |
| POST, PUT, PATCH, DELETE | `/api/users`, `/api/staff-roles` | Impersonation token | `403`. |
| POST | `/api/team/{team}/members` | Impersonation token | `403`. |
| POST | `/api/ai-advisor/chat` | Impersonation token | `403`. |

### Key Entities

- **Impersonation session**: a token belonging to the target user, named `impersonation`, that also records the super admin who started it and its expiry. It is what makes a request an impersonated one. No separate session record exists.
- **Impersonator link**: an optional reference on every token to a user, empty except on impersonation tokens. If that user is deleted, their tokens go with them.
- **Impersonation event**: an audit row with the target user as subject and no hotel. The token's id cannot be the subject because it is a different type of id, so it is kept in `context`.

## Success Criteria *(mandatory)*

### Measurable Outcomes

- **SC-001**: 100% of session tokens stop working at 30 minutes, and none can be extended.
- **SC-002**: 100% of audited writes made during a session's requests, and of writes to the five record types in FR-019b, name the real super admin and the impersonated user.
- **SC-003**: After any session, the target user's password, email, role, memberships, team and staff role are unchanged, and the session has created no other credential.
- **SC-004**: No write through a session changes a suspended hotel or account.
- **SC-005**: No start or end event appears on any hotel's history or event list.
- **SC-006**: Every session that starts has a start event, and every session ended through the end endpoint or logout has exactly one end event.
- **SC-007**: The target user's permissions and hotels decide what a session can reach, with no difference from their own token apart from the listed refusals.

## Assumptions

- The project is in development with test users only, so rollback, backfills and deploy coordination are out of scope.
- Any super admin may impersonate any non-super-admin user, with no reason required and no notice to the user or hotel.
- Frontend work follows from the changes doc after implementation.
- A request's attribution comes from its current token, so the response and error envelopes are unchanged.
- Stage 6 removes the super admin bypasses. Until then a super admin's own token keeps its hotel access.
- To change users or assign a staff role, a super admin uses `api/superadmin`. Staff role definitions are changed only by the hotel's own admins. Deleting a user stays on `DELETE /api/users/{id}` with the super admin's own token, until stage 6 adds a route.

### Out of Scope

- Removing the super admin bypasses (stage 6).
- Carrying the impersonator into queued jobs.
- Extending sessions.
- Any frontend work.
- Listing running sessions, or an endpoint to read platform events ([V10]).

### Known Limits

- **Attribution covers event rows only** ([V6]). Fields such as a task's creator keep the target user, and AI usage from jobs a session starts counts against the hotel. A session's writes to the five record types in FR-019b are recorded by field name only, so their old and new values can't be recovered from the log. None of the five is a history record type, so no endpoint shows these rows yet, and they can only be read in the database.
- **Other unaudited writes are not recorded** ([V6]). The five record types in FR-019b are not every unaudited write a session can make. Creating or deleting a reservation import preview, saving the hotel's import column mapping, and starting an import record no event during a session, as for any other caller. Previews expire on their own, and a finished import records a summary event as the uploader. A route audit found no other unaudited hotel record a session can write ([V6]).
- **A session shares the user's limits** ([V16]). The API and export rate limits, and the hourly voucher email cap, are counted per user, so support work through a session uses up the user's budget.
- **Jobs a session starts name the hotel user** (R8). This is a recorded deviation from the constitution's Principle VI: see the plan's Complexity Tracking.
- **Hotel staff can see who acted** ([V7]). A history row names the super admin who made the change, by id and name only.
- **Reads and writes can land in different hotels** ([V2]). With a suspended default hotel and no header, a `GET` reads A and a `POST` writes to B. A client should send `X-Hotel-Id` throughout a session.
- **`expires_at` is the latest a session could have ended, not always when it did** ([V11]). Revoking tokens, a password reset and deleting either user all end it with no end event.
- **Running sessions can't be listed** ([V10]). `token_id` is returned at start and stored on the event, but no endpoint shows it. Another super admin ends sessions per user through `end-impersonation`, so one session can't be picked out from several without its id.
- **Revoking a compromised super admin's tokens leaves their sessions running for up to 30 minutes** ([V12]). Deleting that super admin ends them.
