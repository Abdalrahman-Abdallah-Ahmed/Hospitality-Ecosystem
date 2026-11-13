# Feature Specification: Super Admins Lose Direct Hotel Access

**Feature Branch**: `028-superadmin-hotel-lockout`

**Created**: 2026-11-09

**Status**: Draft

## Clarifications

### Session 2026-11-09

- Q: How does a super admin delete a user once `DELETE /api/users/{id}` is closed to them? → A: Add `DELETE /api/superadmin/users/{user}`, audited, refusing a super admin target, as the design's Stage 6 note requires.
- Q: Should the new super admin delete endpoint be allowed to delete an account owner? → A: No. It refuses with 409 and a message to transfer ownership first (through the existing access endpoint: clear this user's owner role, optionally make another user owner, then delete; a direct swap is refused because an account has at most one owner). An account is never left without an owner by this endpoint.

**Input**: User description: "Stage 6 of docs/designs/super-admin-platform.md, the last stage: super admins lose direct access to hotel endpoints, and impersonation becomes the only way into a hotel's operational screens. A new no_super_admin middleware on the tenant group returns 403 to a super admin's own token before route-model binding; impersonation tokens pass; POST /logout and GET /user stay reachable. Remove the policy before() bypasses and the isSuperAdmin() branches that only serve tenant routes; keep UserPolicy's refusal to manage a super admin. accessibleHotelIds(), EnsureSuperAdmin and permissions() do not change. A generic test walks every auth:sanctum route as a super admin and expects 403, and succeeds on one route with an impersonation token. First settle the Open Question, re-run the super admin inventory, and list every tenant endpoint the frontend still calls as a super admin, adding a super admin read endpoint for each one still needed. Update the dashboard and staff-roles docs and CLAUDE.md and add a latest-changes doc. Changing the tenant scope, new super admin features beyond those read endpoints, frontend changes, rollback, backfills and deploy coordination are out of scope. Verify the Stage 6 plan against the code and report any mismatch."

## Problem and Need

A super admin's own token still opens every hotel endpoint. About 45 bypass branches in policies, controllers, AI tools and resources give them a view no hotel user has, and their writes there are recorded as their own with no hotel user named. Stage 5 (spec 027) added time-limited impersonation, which shows the user's real view and records the super admin behind every write. Now that it exists, the direct path is a second, unaudited way in that support no longer needs.

This feature closes the direct path: a super admin works in the super admin area, and enters a hotel's operational screens only by impersonating one of its users.

**Actors**:

- **Super admin**: the platform operator. Signs in, uses the super admin area, and impersonates a hotel user to see a hotel.
- **Impersonation session**: a token of a hotel user held by a super admin (spec 027). It is that user, so it is not blocked.
- **Hotel users** (owner, admin, employee): unaffected, except where a test or doc used a super admin in their place.
- **Frontend application**: must stop calling hotel endpoints as a super admin. Its own changes follow from a changes doc and are out of scope.

Requirements tagged **[V1]**–**[V12]** settle a point where the design's Stage 6 plan disagrees with the code or leaves a gap. The evidence, the three pre-work results (Open Question, super admin inventory, frontend calls) and the decision per file are in [plan-verification.md](plan-verification.md).

## User Scenarios & Testing *(mandatory)*

### User Story 1 - A super admin's own token is refused on every hotel endpoint (Priority: P1)

A super admin signs in and calls any hotel endpoint (reservations, guests, users, staff roles, knowledge, AI advisor, WhatsApp device, usage, history…) with their own token. Every call is refused with 403 and a message pointing them to the super admin area, before any record is looked up.

**Why this priority**: This is the security outcome of the stage. Everything else is cleanup that follows from it.

**Independent Test**: Walk every authenticated hotel route as a super admin, with feature flags on, and confirm each returns 403, including with a record id that does not exist.

**Acceptance Scenarios**:

1. **Given** a signed-in super admin, **When** they request any authenticated hotel endpoint other than sign-out and "who am I", **Then** they get 403 with a message naming the super admin area.
2. **Given** a signed-in super admin, **When** they request a hotel endpoint for a record id that does not exist, **Then** they get 403, not 404, so the response reveals nothing about which records exist.
3. **Given** a signed-in super admin, **When** they request a hotel endpoint that sits behind a feature flag that is switched on, **Then** they get 403.
4. **Given** a signed-in super admin, **When** they write to a hotel endpoint (create, update, delete), **Then** nothing changes and they get 403.

---

### User Story 2 - A super admin still signs in, sees who they are, and signs out (Priority: P1)

A super admin signs in, the frontend asks "who am I" to route them to the super admin area, and later they sign out. All three keep working.

**Why this priority**: Without these the super admin cannot reach the super admin area at all.

**Independent Test**: Sign in as a super admin, fetch the current user, then sign out; all three succeed.

**Acceptance Scenarios**:

1. **Given** a super admin, **When** they sign in, **Then** they get a token, and the response still reports their role and permission list as before.
2. **Given** a signed-in super admin, **When** they request "who am I", **Then** they get 200 with their record, unchanged from today.
3. **Given** a signed-in super admin, **When** they sign out, **Then** their token is revoked and they get 200.

---

### User Story 3 - Impersonation is the way into a hotel (Priority: P1)

A super admin starts an impersonation session for a hotel user and uses that hotel's endpoints with the user's permissions, exactly as in spec 027.

**Why this priority**: It is the replacement for the access being removed. If it broke, support would lose all view of a hotel.

**Independent Test**: Start a session for a hotel admin, call a hotel endpoint with the session token, and get the same result that admin would.

**Acceptance Scenarios**:

1. **Given** an impersonation session for a hotel admin, **When** the session calls a hotel endpoint, **Then** it succeeds with that admin's view.
2. **Given** an impersonation session for an employee, **When** the session calls an endpoint the employee's permissions do not cover, **Then** it gets 403 as the employee would. No super admin bypass applies.
3. **Given** an impersonation session, **When** it makes a write the session is allowed, **Then** the audit trail still names the super admin as actor (spec 027 behaviour unchanged).

---

### User Story 4 - The super admin area keeps working, including the user access screen (Priority: P2)

A super admin uses every existing super admin screen: accounts, hotels, users, dashboard, usage, AI cost, global knowledge, rebuilds, guest memory extractions and impersonation. On the Users screen they open "Manage access" for a user, choose that user's staff role and team from the hotel's existing roles and teams, and save.

**Why this priority**: The super admin area is where super admins now work. The access screen today reads a hotel's roles and teams through hotel endpoints, which this stage closes.

**Independent Test**: Run the existing super admin feature tests (their only changes are the shared impersonation helper and the history case that now expects 403); list a hotel's staff roles and teams through the super admin area and assign one through the existing access endpoint.

**Acceptance Scenarios**:

1. **Given** a super admin, **When** they use any existing super admin endpoint, **Then** it behaves exactly as before this stage.
2. **Given** a super admin and a hotel with staff roles, **When** they list that hotel's staff roles through the super admin area, **Then** they get that hotel's roles and no other hotel's.
3. **Given** a super admin and a hotel with teams, **When** they list that hotel's teams through the super admin area, **Then** they get that hotel's teams and no other hotel's.
4. **Given** a super admin and a user who is not a super admin, **When** they delete that user through the super admin area, **Then** the user is deleted as the hotel user endpoint deletes them today, and the deletion is recorded in the platform audit trail.
5. **Given** a super admin, **When** they try to delete another super admin through the super admin area, **Then** the target is treated as not found (404), like every other super admin user endpoint.
6. **Given** a super admin and an account owner, **When** they try to delete the owner, **Then** they get 409 with a message to transfer ownership first, and nothing changes. **When** they first clear the owner role through the access endpoint and then delete the former owner, **Then** the delete succeeds.

---

### User Story 5 - Hotel users are unaffected by the bypass removal (Priority: P2)

Owners, admins and employees keep exactly the access they have today on every hotel endpoint.

**Why this priority**: Removing about 45 branches risks breaking a path nobody listed (risk R9).

**Independent Test**: The full test suite passes after tests that used a super admin as a hotel actor are moved to an admin.

**Acceptance Scenarios**:

1. **Given** a hotel admin, **When** they use any hotel endpoint they used before, **Then** the result is unchanged.
2. **Given** an employee with a staff role, **When** they use an endpoint their role grants, **Then** it succeeds; without the permission, it returns 403, as before.
3. **Given** hotel staff reading a record's history that includes a write made during an impersonation session, **Then** they still see only the super admin's id and name, not their contact details or IP.

---

### Edge Cases

- A super admin sends `X-Hotel-Id` for a hotel: still 403 on every hotel endpoint.
- A super admin calls a hotel endpoint whose feature flag is switched off: the flag's own refusal may answer first; the generic test runs with flags on so the block is what is proven.
- A super admin calls a hotel endpoint with an invalid body: 403, not a validation error, because the block runs first.
- A super admin who is also somehow linked to a hotel (not possible through the API, but possible by direct data change): still 403; the block is by role, not by hotel links.
- An impersonation session whose target is suspended: unchanged from spec 027 (reads only).
- An expired impersonation token: 401 as today, not 403.
- WhatsApp device endpoints: a super admin has no home hotel, so pairing never applied; they now get 403.
- Requests with no context at all (queue jobs, webhooks, schedulers) keep the unrestricted meaning of a null scope; nothing in this stage touches them.

## Requirements *(mandatory)*

### Functional Requirements

**The block**

- **FR-001**: Every authenticated hotel endpoint MUST return 403 to a super admin's own token, with a message telling them to use the super admin area. **[V5]** This covers the whole authenticated hotel group, whatever other middleware a route drops.
- **FR-002**: The refusal MUST happen before any record is looked up, so an unknown or other-hotel record id returns 403, not 404, and request validation does not run.
- **FR-003**: Sign-out and "who am I" MUST stay reachable to a super admin, with their current behaviour.
- **FR-004**: An impersonation token MUST pass the block, because it belongs to the hotel user.
- **FR-005**: The super admin area, sign-in, password reset, registration, pairing by code, health and the WhatsApp webhook MUST NOT be affected.

**Removing the bypasses**

- **FR-006**: The super admin bypass MUST be removed from the 27 policies that have one. The rule that an admin can never manage a super admin MUST stay.
- **FR-007**: Every other super admin branch that serves only hotel endpoints MUST be removed, as decided per file in [plan-verification.md](plan-verification.md). **[V2]** This includes files the design's list missed: the hotel list request and the event log resource (caller clause only). Branches that serve sign-in, the super admin area, or that protect against a super admin as a *target* or *role* MUST stay, unless only a super admin caller could ever reach them and `UserPolicy` already refuses every other caller (the tenant user update's super-admin-target block, which only a super admin editing themselves reached).
- **FR-008**: Hotel staff MUST keep seeing only a super admin's id and name in a record's history (spec 027 FR-019c); only the clause that showed a super admin caller the full details is removed.
- **FR-009**: A super admin's hotel reach MUST keep meaning "unrestricted", so the distinction between unrestricted and restricted-to-no-hotels stays intact. The tenant scope itself does not change. **[V3]** The null-scope branches in hotel-choice and suspension handling stay, because "who am I" and sign-out still pass through them.
- **FR-010**: Sign-in and "who am I" MUST keep returning a super admin's full permission list.
- **FR-011**: The super admin guard on the super admin area MUST NOT change.

**The super admin area**

- **FR-012**: A super admin MUST be able to list a chosen hotel's staff roles and a chosen hotel's teams through the super admin area, read-only, so the user access screen can offer them. Each list returns only the named hotel's records, supports the usual filtering, search, sort and paging, and returns 404 for an unknown hotel.
- **FR-013**: Every existing super admin endpoint MUST keep working without change. **[V1]** The Open Question is settled: no super admin controller relies implicitly on the unrestricted scope.
- **FR-014**: **[V7]** A super admin MUST be able to delete a user through the super admin area, because the hotel user endpoint they use today is closed by this stage and an impersonation session is refused there. The deletion MUST have the same effect on the user's row, the account's seat count and the user's password links as the hotel endpoint, MUST also revoke all the user's tokens (impersonation tokens included), MUST treat a super admin target as not found (404), MUST refuse an account owner with 409 and a message to transfer ownership first, and MUST be recorded in the platform audit trail like every other super admin write. This is the one write endpoint added by this stage, as the design's Stage 6 note requires.
- **FR-015**: Each tenant endpoint the frontend still calls as a super admin MUST have either a super admin replacement in this stage or a recorded decision that it is no longer needed. The list and decisions are in [plan-verification.md](plan-verification.md) Task 3.

**Proof**

- **FR-016**: A generic test MUST walk every authenticated route defined for hotel users, with every feature flag on, except sign-out and "who am I", as a super admin, and expect 403, including with an unknown record id. **[V6]** It MUST exclude the super admin area, which is also authenticated. It MUST repeat on at least one hotel route with an impersonation token and expect success.
- **FR-017**: Every test that used a super admin as a hotel actor MUST move to an admin, or to an impersonation token where the super admin's view is the point. Tests that asserted "a super admin sees everything" MUST become 403 assertions. **[V4]** The plan MUST classify all 55 test files that mention a super admin, not the 27 the design lists, and update test names cited by the entry-point inventory.
- **FR-018**: The two new read lists MUST have tests proving one hotel's roles and teams never appear in another hotel's list, and that a non-super-admin is refused. The new delete MUST have tests for a successful delete with its audit event, a super admin target (404), an account owner (409, then success once the owner role is cleared), and a non-super-admin caller (403).

**Documentation**

- **FR-019**: The dashboard and staff roles API docs and `CLAUDE.md` (Tenancy, and Access control including "admin and super_admin hold every permission") MUST be updated. **[V9]** **[V8]** Every other tenant API doc that describes super admin behaviour on a hotel endpoint MUST be updated the same way.
- **FR-020**: A `docs/latest-changes-<date>.md` MUST describe the breaking change and map each frontend call from Task 3 to its replacement or removal. The new read endpoints MUST be documented with the super admin user administration docs.
- **FR-021**: **[V11]** The design's Open Question MUST be marked settled, citing the result in plan-verification.md.

### Key Entities

- **Super admin's own token**: a sign-in token of a user with the super admin role and no impersonation link. Refused on hotel endpoints.
- **Impersonation token**: a token of a hotel user with an impersonator recorded (spec 027). Allowed, as that user.
- **Staff role, Team**: existing hotel records, now also readable per hotel through the super admin area.

## Success Criteria *(mandatory)*

### Measurable Outcomes

- **SC-001**: 100% of authenticated hotel endpoints, except sign-out and "who am I", refuse a super admin's own token, as proven by the generic walk, including for unknown record ids.
- **SC-002**: 0 super admin bypass branches remain on hotel-only paths; each remaining reference is listed as *keep* with a reason in plan-verification.md.
- **SC-003**: A super admin completes sign-in → user access change → impersonation → sign-out entirely through the super admin area and their session, with no hotel endpoint call made with their own token.
- **SC-004**: The full test suite passes, with every existing super admin area test unchanged in behaviour.
- **SC-005**: Every frontend call listed in plan-verification.md Task 3 has a documented replacement or a documented reason it is no longer needed.

## Assumptions

- The project is in development with test users only; rollback, data backfills and deploy coordination are out of scope.
- The frontend's own changes (hiding hotel screens and the WhatsApp tab from super admins, switching the access screen to the new lists) follow from a changes doc and are out of scope here.
- Super admins are created by seeder or CLI and are never edited through the API, so losing the hotel user endpoint does not remove a needed way to edit a super admin's own profile (the super admin user list already excludes super admins).
- The block is by role on the token's user, so it needs no data change.
- No new super admin features are added beyond the read lists in FR-012 and the user delete in FR-014.
- Staff role definitions remain editable only by a hotel's own admins (design Non-Goal), so the super admin needs no access to the permission catalogue.
