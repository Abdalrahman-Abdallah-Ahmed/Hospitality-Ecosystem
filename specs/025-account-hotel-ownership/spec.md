# Feature Specification: Account-Level Hotel Ownership

**Feature Branch**: `025-account-hotel-ownership`

**Created**: 2026-11-07

**Status**: Draft

**Input**: User description: "Move hotel ownership from the hotel to the account, so an account owner has every hotel in their group and no per-hotel owner exists. Follows spec 024 and its review finding 3: an account owner gets 403 on their own hotel B, while the hotel list shows it. Remove the hotel's owner field and relation. GET, PUT and PATCH on a hotel allow any admin who can reach it; employees stay refused. An account has one owner, the only group role is owner, and a second owner is refused. Registration makes the new user the owner of the account created for their hotel. Suspension, super admin bypasses, impersonation, employees receiving the full hotel record, rollback and data backfills are out of scope. Verify each item against the code and report any mismatch."

## Problem and Need

Ownership is recorded in two places that disagree. A hotel names one owner, and a user can separately hold an account-wide role. Only the hotel's owner can open or edit a hotel's record, so an account owner is refused on the second hotel of their own account, even though their hotel list shows it. A second admin at the same hotel is refused too. Every other module has the same gap: its lists show records from all of a user's hotels, but opening, changing or creating one works only in their home hotel.

This feature makes the account the only place ownership lives. Each account has at most one owner, who works across all modules in every hotel in it. Any other admin can do the same in the hotels they are assigned to.

Moving ownership also raises what an owner is worth: one user now holds a whole account. So the ways an owner could be duplicated, taken over or left half-demoted have to close at the same time. See [plan-verification.md](plan-verification.md) for the code evidence behind each decision.

**Actors**:

- **Account owner**: the one admin who reaches every hotel in an account.
- **Hotel admin**: an admin who reaches only their home hotel and the hotels they are a member of.
- **Employee**: hotel staff. Never opens a hotel's full record through these routes.
- **Super admin**: the platform operator, who assigns and removes owners and moves hotels.
- **Registering customer**: someone signing up with their first hotel.

## Clarifications

### Session 2026-11-07

- Q: Who should be able to change or delete an account owner through the tenant user endpoint? → A: Other admins get `403` on update and delete, and can still view the owner. The owner can edit their own details, but can't change their own role or delete themselves. A super admin can still do both, and a role change ends the ownership.
- Q: Should this spec give admins access to every module across their hotels, or only the hotel record? → A: Every module. The account owner works across all modules in every hotel of the account. Other admins have the same access level only in the hotels they're assigned to, meaning their home hotel and member hotels, and can't change or delete the owner.
- Q: Can an admin edit another admin's account? → A: No, only the account owner can. This replaces the narrower rule protecting only the owner. Update and delete of another admin are refused for every admin but the owner, and each admin can still edit their own account.
- Q: Can an admin create another admin or promote an employee to admin? → A: No, only the account owner can. Super admins aren't affected.
- Q: Does an admin work in one hotel at a time, or across all their hotels at once? → A: One hotel per request: the hotel chosen with `X-Hotel-Id`, or their default hotel without it. Lists, reports and new records all follow it. Only the hotel list and the hotel record reach every hotel they can reach.
- Q: Should an employee assigned to more than one hotel be able to work in all of them, using the permissions from their staff role? → A: No. Their permissions apply to records in their home hotel only, and they still get `403` on records in member hotels.
- Q: What should happen when a request's `X-Hotel-Id` names a hotel the user can't reach? → A: Refuse every request carrying that header with a plain `403`, for reads and writes alike. Super admins aren't affected.
- Q: Should super admins lose the ability to set a user's `hotel_group_id` and `group_role` through the tenant endpoint as part of this spec? → A: Yes. Both fields are ignored there for every caller. All other super admin access through that endpoint stays until stage 6.

## User Scenarios & Testing *(mandatory)*

### User Story 1 - Admins open the hotels they can reach (Priority: P1)

An account owner opens and edits any hotel in their account. A hotel admin does the same for the hotels they work in. Everyone else is refused, and suspension behaves as it does today.

**Why this priority**: This is the defect that started the work. The hotel list and the hotel record currently disagree.

**Independent Test**: Create an account with hotels A and B, an owner based at A, and a hotel admin based at A. Request both hotels as each user, and check the outcome against the scenarios below.

**Acceptance Scenarios**:

1. **Given** an account owner based at hotel A, **When** they send `GET`, `PUT` or `PATCH` to `/api/hotel/{B}`, **Then** the response is `200`.
2. **Given** an admin based at hotel A with no account-wide role, **When** they send those requests to hotel A, **Then** the response is `200`, whether or not they are the only admin there.
3. **Given** that same admin, **When** they send those requests to hotel B, **Then** the response is the normal `403`.
4. **Given** an admin who is a member of hotel B, **When** they send those requests to hotel B, **Then** the response is `200`.
5. **Given** an employee with every permission, including `hotels.view`, **When** they send those requests to their own hotel, **Then** the response is `403`.
6. **Given** an admin of another account, **When** they send those requests to hotel A, **Then** the response is the normal `403`, and nothing shows whether hotel A is suspended.
7. **Given** an owner whose hotel B is suspended while hotel A is active, **When** they send those requests to hotel B, **Then** the response is the suspended `403` with scope `hotel`, and hotel B doesn't change.
8. **Given** a super admin, **When** they send those requests to any hotel, suspended or not, **Then** the behaviour is unchanged.

---

### User Story 2 - A customer who registers owns their account (Priority: P1)

Someone who signs up with their first hotel becomes the owner of the account created for it, so any hotel added to that account later is theirs with no further step.

**Why this priority**: Every self-registered customer depends on it. Without it, they would lose access to their hotel's record once ownership leaves the hotel, and later hotels would be out of reach.

**Independent Test**: Register through the API, check the response, then add a second hotel to the new account and open it as the new user.

**Acceptance Scenarios**:

1. **Given** a new customer, **When** they register, **Then** the response shows their `hotel_group_id` as the new hotel's account and their `group_role` as `owner`.
2. **Given** a registered customer, **When** a super admin adds a hotel to their account, **Then** the customer can open it with no further change.
3. **Given** a registration that fails partway, **When** it ends, **Then** no user, hotel or account is left behind.

---

### User Story 3 - One owner per account (Priority: P1)

An account never has two owners, including when two requests arrive at the same moment. `owner` is the only account-wide role.

**Why this priority**: An owner reaches a whole customer's data, so a second one is a serious access grant. A rule checked only in application code would fail under concurrent requests.

**Independent Test**: Assign an owner, try to assign a second, and try to create a user as a second owner. Then send two owner assignments for an ownerless account at the same time.

**Acceptance Scenarios**:

1. **Given** an account with an owner, **When** a super admin makes another admin its owner through the access endpoint, **Then** the response is `422` and nothing changes.
2. **Given** an account with an owner, **When** a super admin creates an admin as its owner, **Then** the response is `422` and no user is created.
3. **Given** an ownerless account, **When** two owner assignments arrive at once, **Then** exactly one succeeds and the other gets the same `422`, not a server error.
4. **Given** a current owner, **When** a super admin sends `owner` for them again, **Then** the response is `200` with no change and no event.
5. **Given** an owner whose user was deleted, **When** a super admin assigns another admin as owner, **Then** it succeeds.
6. **Given** any request, **When** it sends `group_role: group_admin` to a super admin user endpoint, **Then** the response is `422`.

---

### User Story 4 - The operator decides who owns an account (Priority: P2)

Owners exist because someone chose them. Creating or promoting an admin never makes them an owner by accident. An operator can end an ownership, and an account can stay without an owner until a new one is assigned.

**Why this priority**: An automatic rule could hand a whole account to whoever happened to be created first. Replacing an owner has to be possible once a second owner is refused.

**Independent Test**: Create the first admin in a new account and confirm they have no account-wide reach. Assign them as owner, demote them, and confirm the account still works with no owner.

**Acceptance Scenarios**:

1. **Given** a new account with hotels A and B, **When** a super admin creates its first admin at hotel A without a group role, **Then** that admin reaches hotel A only.
2. **Given** an employee promoted to admin, **When** the change is saved, **Then** they aren't an owner of anything.
3. **Given** an owner, **When** a super admin demotes them to employee or clears their group role, **Then** on their next request they reach only their home hotel and memberships, and the account has no owner.
4. **Given** an ownerless account, **When** its hotel admins and staff work, **Then** nothing changes for them, and a super admin can assign a new owner.
5. **Given** an owner to be replaced, **When** a super admin clears the current owner and then assigns the new one, **Then** both steps succeed.
6. **Given** an owner's ownership ends through the access endpoint, **When** the change is saved, **Then** their direct link to the account is cleared as well, and it is recorded in the access event.

---

### User Story 5 - Only the owner manages other admins (Priority: P1)

An admin cannot change another admin's password, role or details, or delete them. Only the account owner can, and nobody can change the owner except themselves or a super admin. Nobody can gain or keep account-wide reach through the tenant user endpoint.

**Why this priority**: Today any admin at the same home hotel can do this. An admin who could reset a colleague's password could sign in as them and reach their other hotels, and since every registered customer will now be an owner, one compromised or unhappy hotel admin could take a whole account.

**Independent Test**: As an admin, try to change another admin's password and role, and to delete them, with that admin being first a colleague and then the owner. Repeat as the owner. As a super admin, send account attributes to the tenant user endpoint.

**Acceptance Scenarios**:

1. **Given** an admin other than the owner, **When** they update or delete another admin through `/api/users/{id}`, the owner included, **Then** the response is `403` and the target doesn't change. They can still view them.
2. **Given** an owner, **When** they update their own name, email, phone or password through the tenant endpoint, **Then** it succeeds.
3. **Given** an account owner, **When** they update or delete another admin in the account through the tenant endpoint, with that admin's home hotel chosen, **Then** it succeeds.
4. **Given** an admin other than the owner, **When** they update or delete their own account, or manage an employee of the hotel they're working in, **Then** it succeeds.
5. **Given** an admin other than the owner, **When** they create a user with the admin role or promote an employee to admin, **Then** the response is `403` and nothing changes. Creating an employee still works.
6. **Given** an account owner, **When** they create an admin or promote an employee to admin in a hotel of the account, **Then** it succeeds, and the new admin isn't an owner.
7. **Given** an owner, **When** they change their own role or delete themselves through the tenant endpoint, **Then** the response is `403` and nothing changes.
8. **Given** any caller, super admins included, **When** they send `hotel_group_id` or `group_role` to the tenant user endpoint, **Then** those fields are ignored and the rest of the request applies.
9. **Given** a super admin using the tenant endpoint, **When** they change an owner's role, **Then** the ownership and account link end with it, so no employee keeps account-wide reach.
10. **Given** a super admin, **When** they delete an owner through the tenant endpoint, **Then** it succeeds and the account has no owner.

---

### User Story 6 - Moving a hotel with an owner attached (Priority: P2)

Moving a hotel no longer depends on a per-hotel owner. A hotel whose home users are attached to its account, including a self-registered owner, is refused until they are detached, and detaching is possible.

**Why this priority**: Registered customers' hotels are the main thing that gets moved into a real group, and registration now attaches their owner. The operator needs a working path.

**Independent Test**: Register a customer, try to move their hotel, clear their group role, move the hotel again, then assign an owner at the destination if wanted.

**Acceptance Scenarios**:

1. **Given** a self-registered customer's hotel, **When** a super admin moves it, **Then** the response is `422` and nothing changes.
2. **Given** that customer's group role has been cleared, **When** a super admin moves the hotel, **Then** it moves, the customer follows through their home hotel, and they keep access throughout.
3. **Given** that hotel has moved, **When** the destination has no owner, **Then** the customer isn't its owner until a super admin assigns them.
4. **Given** a destination that has an owner, **When** a super admin assigns the moved customer as owner, **Then** the response is `422`.
5. **Given** a hotel with no home users of its own in an account with an owner, **When** it moves, **Then** the source owner stops reaching it and the destination owner starts reaching it, with no further step.

---

### User Story 7 - API clients see the new model (Priority: P2)

The hotel's owner disappears from every response, and the change is documented as breaking.

**Why this priority**: The frontend reads these responses. It has to know what changed before it can follow.

**Independent Test**: Read a hotel through each tenant and super admin path, including nested in a user, and confirm the field is gone. Read the breaking-changes document.

**Acceptance Scenarios**:

1. **Given** any hotel response, tenant or super admin, listed, single, or nested in login, `GET /api/user`, the user endpoints or registration, **When** it is read, **Then** it has no `owner_id` and no `owner`.
2. **Given** a hotel list request, **When** it uses `filter[owner_id]` or `sort=owner_id`, **Then** the response is `422`.
3. **Given** a hotel write, **When** it sends `owner_id`, **Then** it is ignored.
4. **Given** the breaking-changes document, **When** a frontend developer reads it, **Then** it lists each change in this spec and what they need to do.

---

### User Story 8 - Admins work in every module of their hotels (Priority: P1)

An admin works in one hotel per request, across every module: the hotel they choose with `X-Hotel-Id`, or their default hotel without it. An account owner can choose any hotel of the account. Another admin can choose their home hotel and member hotels, and none of the account's other hotels.

**Why this priority**: Fixing only the hotel record would leave a customer with two hotels unable to run the second one. Keeping every request to one hotel means lists, reports and new records can't mix hotels or land in the wrong one.

**Independent Test**: Create an account with hotels A, B and C, an owner based at A, and an admin based at A who is a member of B. As each user, choose each hotel, then list, open, change and create rooms and reservations, read the dashboard, and manage staff.

**Acceptance Scenarios**:

1. **Given** an owner based at hotel A with hotel B chosen, **When** they list, open, change or create records, **Then** it succeeds, and all of it is hotel B's. New records belong to hotel B.
2. **Given** an owner based at hotel A with no hotel chosen, **When** they list records, read the dashboard or create a record, **Then** all of it is hotel A's, and a record in hotel B returns "not found".
3. **Given** an admin based at A and a member of B, **When** they choose A or B, **Then** they can work there as in scenario 1.
4. **Given** that same admin, **When** they open a record in hotel C, **Then** it isn't found. **When** they send a request with `X-Hotel-Id` set to hotel C, **Then** the response is a plain `403` and nothing is created anywhere.
5. **Given** an owner with a hotel chosen, **When** they list, create, update or delete staff, staff roles or teams there, **Then** it succeeds.
6. **Given** an admin with one of their hotels chosen, **When** they manage employees there, **Then** it succeeds, but updating or deleting another admin, or the account owner, is `403`.
7. **Given** a record in the chosen hotel, **When** an admin links a team, category or other record from another hotel, **Then** the link is refused.
8. **Given** an owner whose hotel B is suspended, **When** they request a record in hotel B, **Then** the behaviour is the same as spec 024: the record isn't found, and choosing hotel B gets the suspended `403`.
9. **Given** an admin of another account, **When** they request a record in any of these hotels, **Then** nothing is shown or changed.
10. **Given** an employee based at hotel A and a member of hotel B, **When** they open or change a record in hotel B, **Then** the response is `403`, whatever their permissions.
11. **Given** an admin, **When** they edit a record, **Then** it stays in its own hotel.
12. **Given** an advisor conversation started in hotel A with an action waiting for confirmation, **When** the admin confirms it with hotel B chosen, **Then** the response is "not found" and the action doesn't run.
13. **Given** an owner with hotel A chosen, **When** they list their hotels, or open or edit hotel B's own record, **Then** it succeeds, and any team or category linked on hotel B's record must be hotel B's.

### Edge Cases

- **An owner with no home hotel**: possible only for a user created before home hotels were required, or by a super admin through the tenant endpoint. They still reach every hotel in the account. If their ownership ends, they no longer resolve to any account (see Known Limits).
- **A soft-deleted hotel**: it stays out of reach and returns `404`, as today.
- **An owner's home hotel soft-deleted**: they still reach the account's other hotels.
- **A soft-deleted account**: it can't be chosen for a new owner, and its owner row doesn't affect any other account.
- **A record in another of the admin's hotels**: without that hotel chosen it isn't found, even though the admin can reach it. Clients choose the hotel first.
- **A second hotel admin at one hotel**: both can now edit its settings. Today only the recorded owner can.
- **A user holding a direct account link with no group role**: they keep reaching only their home hotel and memberships. Their link still blocks moving that hotel, until a super admin sends `group_role: null` for them.
- **An account with no owner**: only a super admin can create, promote, edit or delete its admins, through the existing super admin endpoints. A deleted admin's row is removed through the tenant endpoint, which a super admin can still use.
- **A stale hotel choice**: an admin removed from a hotel with its id still in their `X-Hotel-Id` gets `403` on every request until the client clears it. They can still log out.
- **An owner whose home hotel is missing or soft-deleted**: they work in another hotel of the account, rather than being told they have no hotel.
- **A failed unique check for a different reason**: a duplicate email during user creation keeps its own response. It is not reported as a second owner.

## Requirements *(mandatory)*

### Functional Requirements

**Hotel access**

- **FR-001**: A hotel MUST belong to its account only. No hotel records an owner.
- **FR-002**: `GET`, `PUT` and `PATCH /api/hotel/{id}` MUST allow an admin who can reach the hotel. An account owner reaches every live hotel in their account. Any other admin reaches their home hotel and the hotels they are a member of.
- **FR-003**: Whether a user can reach a hotel MUST be decided from every hotel they may reach, before suspended hotels are taken out of the request.
- **FR-004**: Employees MUST get `403` on those routes, whatever their permissions or reach.
- **FR-005**: A caller who can't reach a hotel MUST get the normal `403`, which reveals nothing about suspension.
- **FR-006**: A caller who can reach a suspended hotel MUST get the existing suspended `403`, after authorization. Super admins MUST NOT be affected.
- **FR-007**: Hotel settings MUST stay outside the permission system, so no staff role can grant them.

**One owner**

- **FR-008**: `owner` MUST be the only account-wide role. `group_admin` MUST be refused with `422` on the super admin user endpoints.
- **FR-009**: An account MUST never have more than one owner, including under simultaneous requests. The guarantee MUST hold in storage, not only in application checks.
- **FR-010**: A request that would create a second owner MUST get `422`, change nothing and record no event. That includes the request that loses a simultaneous race, which MUST NOT get a server error.
- **FR-011**: Other storage conflicts, such as a duplicate email, MUST NOT be reported as a second owner.
- **FR-012**: Sending `owner` for the current owner MUST succeed with no change. Deleting an owner MUST free the account for a new one immediately.
- **FR-013**: An owner MUST be an admin who belongs to the account. These rules exist today and MUST stay.

**Registration**

- **FR-014**: `POST /api/register` MUST make the new user the owner of the account created for their hotel, setting `hotel_group_id` and `group_role` in the same all-or-nothing step, and MUST return both.

**Assigning and ending ownership**

- **FR-015**: Creating or promoting an admin MUST NOT make them an owner, including the first admin of an account. Apart from registration, ownership MUST come only from an explicit `group_role`, on user creation or the access endpoint.
- **FR-016**: An account with no owner MUST be a valid state, in which its hotels, admins, staff and usage reporting keep working.
- **FR-017**: A super admin MUST be able to end an ownership by clearing the group role, demoting the user or deleting them. Being the account's only owner MUST NOT block this.
- **FR-018**: Demoting an owner or sending `group_role: null` MUST also clear the user's direct account link. They stay attached through their home hotel and memberships.
- **FR-019**: A user whose ownership ended MUST lose reach to other hotels from their next request.
- **FR-020**: Ownership changes made through the super admin endpoints MUST be recorded in the existing access and creation events, including the account link.

**Tenant user endpoint**

- **FR-021**: `/api/users` MUST ignore `hotel_group_id` and `group_role` on create and update for every caller, and apply the rest of the request. A malformed value still gets `422` from validation. The existing `403` for linking a super admin target stays.
- **FR-022**: An admin other than the account owner MUST get `403` when updating or deleting another admin through `/api/users/{id}`, the owner included. The owner MAY update or delete other admins in the account. An admin other than the owner MAY update or delete their own account. Viewing stays allowed.
- **FR-022a**: An admin other than the account owner MUST get `403` when creating a user with the admin role or changing a user's role to admin through `/api/users`, and nothing MUST change. Super admins MUST NOT be affected.
- **FR-023**: An owner MUST be able to edit their own details there, and MUST get `403` when changing their own role or deleting themselves.
- **FR-024**: A super admin changing an owner's role there MUST end the ownership and account link with it. A super admin MAY delete an owner there, because the super admin API has no delete route.

**Moving hotels**

- **FR-025**: A hotel move MUST NOT depend on a per-hotel owner. The other refusals stay as they are: memberships in the hotel, home users attached to the account, and home users with memberships elsewhere.
- **FR-026**: After a super admin detaches a hotel's home users (FR-018), the move MUST succeed. Home users follow the hotel, and none becomes the destination's owner automatically.
- **FR-027**: An account owner's reach MUST follow a hotel's current account, in both directions, with no further step.

**API and documentation**

- **FR-028**: `owner_id` and `owner` MUST be removed from every hotel representation, tenant and super admin, including hotels nested in user responses and registration.
- **FR-029**: On hotel lists, `filter[owner_id]` and `sort=owner_id` MUST return `422`. A sent `owner_id` on a hotel write MUST be ignored.
- **FR-030**: Each affected API document MUST be updated, and all breaking changes MUST be summarized in `docs/latest-changes-<date>.md`, with what the frontend needs to do.
- **FR-031**: A test MUST prove that an admin of another account can't open or edit a hotel, including for an account owner.

**Seed data**

- **FR-032**: Seeders and the benchmark command MUST work with no hotel owner. The demo account has no owner, because its demo hotel belongs to the super admin, who can't be linked to an account.

**Access across modules**

- **FR-033**: An admin MUST be able to choose any hotel they can reach (FR-002), and work there in every tenant module, subject to the same rules as in their home hotel. Admin-only modules are included: users, staff roles, the AI advisor and proactive messages.
- **FR-033a**: Each admin request MUST work in exactly one hotel: the one chosen with `X-Hotel-Id`, or their default hotel without it. Records in any other hotel MUST NOT be found. Only `GET /api/hotel` and `GET`, `PUT`, `PATCH /api/hotel/{id}` reach further (FR-002), and WhatsApp device routes keep the user's own device.
- **FR-034**: An admin MUST get no access to records in a hotel they can't reach, including other hotels in their own account.
- **FR-035**: An admin's new record MUST be created in the hotel the request works in. A client-sent `hotel_id` MUST stay ignored for these users.
- **FR-035a**: A request whose `X-Hotel-Id` names a hotel the user can't reach, including one that doesn't exist or is soft-deleted, MUST get the same plain `403`, for reads and writes alike. It applies to admins and employees, on every tenant route except logout, the hotel list and WhatsApp device routes. A reachable suspended hotel keeps the suspended `403` from spec 024. Super admins MUST NOT be affected.
- **FR-036**: Links to other records, such as teams and categories, MUST be checked against the hotel of the record being written, not the user's home hotel. That includes team and category settings on `PUT /api/hotel/{id}`.
- **FR-037**: For an admin, lists, the dashboard and other reports MUST cover only the hotel the request works in.
- **FR-038**: Admins MUST be able to list users whose home hotel is the one the request works in, and manage the employees among them. FR-022 to FR-024 limit who can change an admin, and new users MUST NOT gain reach beyond what the existing rules allow.
- **FR-039**: A WhatsApp sender MUST stay tied to their default hotel, as decided in spec 024, because a message can't choose a hotel.
- **FR-040**: Suspended hotels MUST behave as in spec 024: they are taken out of the request, so their records aren't found, and super admins MUST NOT be affected.
- **FR-041**: Employees' access MUST NOT change. Their permissions apply to records in their home hotel only, and records in a member hotel stay `403`.
- **FR-042**: Updating a record MUST NOT move it to another hotel, whichever hotel the admin has chosen.
- **FR-043**: An advisor conversation MUST stay in the hotel it started in. Continuing it or confirming its waiting actions with another hotel chosen MUST return the existing "not found" response, and nothing MUST run.

### Endpoint Map

| Endpoint | Change |
| --- | --- |
| `GET`, `PUT`, `PATCH /api/hotel/{id}` | Any admin who can reach the hotel. No `owner_id` in the response |
| `GET /api/hotel`, `GET /api/superadmin/hotels` | No `owner_id`. `filter[owner_id]` and `sort=owner_id` are `422` |
| `POST /api/register` | User is the new account's owner |
| `POST /api/login`, `GET /api/user`, `/api/users` | Nested hotel has no `owner_id` |
| `POST /api/users`, `PUT /api/users/{id}` | Ignores `hotel_group_id` and `group_role` for every caller. Only the owner can create or promote an admin, or update another admin |
| `DELETE /api/users/{id}` | Only the owner can delete another admin. An owner can be deleted only by a super admin |
| `POST /api/superadmin/users` | `group_role` accepts only `owner`. Second owner is `422`. No automatic owner |
| `PUT /api/superadmin/users/{id}/access` | Same rules. Ending ownership also clears the account link |
| `PUT /api/superadmin/hotels/{id}` | No owner check on a move. Other refusals stay |
| Every other tenant module | An admin works in one hotel per request, chosen with `X-Hotel-Id` from those they can reach, or their default hotel |

### Key Entities

- **Account (hotel group)**: the customer. Has hotels and at most one owner.
- **Hotel**: belongs to one account, and has no owner of its own.
- **User**: a home hotel, memberships, and optionally a direct account link and the `owner` role. An owner needs the link. The link alone gives no extra reach. Their **default hotel** is their home hotel, or another hotel they can reach when their home hotel is missing, deleted or suspended.

## Success Criteria *(mandatory)*

### Measurable Outcomes

- **SC-001**: For every hotel in a user's hotel list, an admin's request for that hotel's record succeeds, with no case where the list shows a hotel and its record refuses them, except a suspended hotel's own `403`.
- **SC-002**: 100% of newly registered customers own their account, and reach a hotel added to it with no extra step.
- **SC-003**: The stored data can't hold two owners for one account, whatever the order of requests, and the request refused by that rule gets a `422`, not a server error, confirmed by tests of the rule and of the refusal path that a race takes.
- **SC-004**: No user other than a super admin or the owner can change an owner's password, role or existence through the API, and no admin but the account owner or a super admin can change another admin's, or create or promote an admin, confirmed by test.
- **SC-005**: No hotel response from any endpoint contains `owner_id` or `owner`, confirmed by test.
- **SC-006**: A super admin can move a self-registered customer's hotel to another account in 2 requests, and assign an owner at the destination in a third, using only the API.
- **SC-007**: An admin of another account gets `403` on 100% of cross-account hotel requests, with no suspension detail in the response.
- **SC-008**: The whole test suite passes, with no test left relying on a removed field or role.
- **SC-009**: For an account with 3 hotels, an owner choosing any of the 3 can read that hotel's records on every enabled tenant module, and an admin assigned to 2 can do so in those 2 and is refused the third, confirmed by a test that runs across the tenant routes.
- **SC-010**: With a hotel chosen, no list, report or new record contains or belongs to another hotel, confirmed by test.

## Assumptions

- **Out of scope, per the request**: suspension behaviour, removing the super admin bypasses (stage 6), impersonation (stage 5), employees receiving the full hotel record through login and `GET /api/user`, rollback and data backfills. The project is in development with test users, so existing databases are freshly migrated. FR-021 is the one exception on bypasses: it closes a single super admin write path early, as clarified.
- Frontend changes follow from a handoff after the backend is done, as for specs 023 and 024. The list is in [plan-verification.md](plan-verification.md).
- No endpoint is added. A super admin finds an account's owner with the existing user list filters, and swaps owners in two requests.
- No new permission is needed, because hotel settings and account ownership stay admin-only.

### Known Limits

- **No combined view across hotels**: an owner sees one hotel at a time, in lists and on the dashboard. A combined view would be a dedicated report, and needs its own spec.
- **Account usage**: `GET /api/usage` still gives any admin the account's totals. They name no hotel, as decided in spec 024.
- **Employees in member hotels**: they can see a member hotel's records in lists but get `403` opening them, because a staff role belongs to one hotel. Fixing this needs per-hotel staff roles and its own spec.
- **No second account-wide person**: with `group_admin` gone, a regional manager needs a membership per hotel, and doesn't get new hotels automatically.
- **No one-step owner transfer**: the account has no owner between the two requests. That's safe, because nobody loses their own hotels.
- **An owner with no home hotel**: when their ownership ends, they no longer resolve to an account until reassigned.
