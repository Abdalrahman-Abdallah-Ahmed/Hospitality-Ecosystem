# Feature Specification: Super Admin Account, Hotel and User Administration

**Feature Branch**: `023-superadmin-administration`

**Created**: 2026-11-06

**Status**: Draft

**Input**: User description: "Stage 2 of docs/designs/super-admin-platform.md: account, hotel and user administration plus reset access under api/superadmin, so a super admin can onboard a customer without SQL. Super admins have no hotel links, and no API request can grant super_admin. A new user gets a random password and a reset email. Hotel creation and deletion move out of the tenant HotelController. Restoring a soft-deleted hotel with the same slug happens only within the same account. Contract fields are not exposed or accepted in v1. Platform audit rows always have hotel_id = null. Suspension, the dashboard, impersonation and removing the super admin bypasses are out of scope, as are rollback and data backfills."

## Problem and Need

Onboarding a customer needs direct database work today. The API has no way to create an account, a hotel can only be created through a tenant endpoint that works because super admins bypass its policy, and the only way to give a first admin a login is to choose a password for them. A compromised user cannot be locked out without database access either.

This feature gives the platform operator an API to create and edit accounts, hotels and users, and to cut off or restore a user's access. The operator never learns a customer's password, and every action is recorded where hotel staff cannot see it.

**Actors**:

- **Super admin**: the platform operator, and the only user who can call the new endpoints. Created only by seeder, and linked to no hotel or account.
- **Account owner, hotel admin and employee**: the users being administered. They cannot call the new endpoints.
- **New or locked-out user**: holds a reset link and sets their own password through a public endpoint.
- **Frontend application**: the API client, which also hosts the page that reset links open.

Requirements tagged **[V1]** to **[V13]** settle a point where the design's Stage 2 plan disagrees with the code or leaves a gap. The evidence and the chosen default for each are in [plan-verification.md](plan-verification.md).

## Clarifications

### Session 2026-11-06

- Q: How long should the emailed link stay valid for a brand-new user setting their first password? → A: 72 hours for a new user's first link, and 60 minutes for all other resets.
- Q: Should a super admin be able to change a user's home hotel to another hotel in the same account? → A: No, not in v1. Moving a user off their home hotel still needs direct database work.
- Q: If the password email can't be sent, how should the super admin find out? → A: The email is sent during the request. Creating a user still returns `201`, with `reset_email_sent: false` if the send failed. A failed resend returns `503` and records no event. Failures also go to error monitoring.
- Q: When a super admin deletes a hotel, should its staff lose access to that hotel's data through the API? → A: Yes. Soft-deleted hotels drop out of what a user can reach through the API. Links and tokens are kept, so access returns if the hotel is restored. WhatsApp and scheduled work are not affected.
- Q: Should every new user have to be given a home hotel when they're created? → A: Yes. `hotel_id` is required for every new user, and a request without it returns `422`. `hotel_group_id` and `group_role` stay optional.

## User Scenarios & Testing *(mandatory)*

### User Story 1 - Onboard a new customer without SQL (Priority: P1)

A super admin creates an account, adds its hotels, and creates the first admin. The admin receives an email, sets their own password, logs in and can open their hotel.

**Why this priority**: This is the purpose of the feature. Nothing else here is worth much while a new customer still needs database work.

**Independent Test**: Run the whole journey through the API, starting with no account, using no seeder or database step, and finish by calling a hotel endpoint as the new admin.

**Acceptance Scenarios**:

1. **Given** no account exists for the customer, **When** a super admin creates an account, then a hotel under it, then a user with that hotel and role `admin`, **Then** all three exist, and the admin owns the hotel.
2. **Given** the admin was just created, **When** they use the emailed link to set a password and then log in, **Then** login succeeds and they can open their hotel.
3. **Given** the admin was just created, **When** anyone reads the create response, the audit trail or the email, **Then** no password appears in any of them, and the super admin has no way to log in as the admin.
4. **Given** each of the three creations, **When** the audit trail is read, **Then** each wrote exactly one platform event naming the super admin as actor.
5. **Given** a hotel created this way, **When** it is inspected, **Then** it has the same default teams, categories and settings as any other new hotel.
6. **Given** a new hotel request that omits currency or timezone, **When** it is created, **Then** it takes the account's default currency and timezone. **[V12]**

---

### User Story 2 - No request can create a super admin (Priority: P1)

Super admins exist only through the seeder. No API request can grant the role or give a super admin a hotel or account link, whoever sends it.

**Why this priority**: A super admin can read and change every customer's data. Today one super admin can promote another user through the tenant endpoint, so this story closes an existing path.

**Independent Test**: Attempt the grant through every endpoint that writes a role, as each kind of caller, and confirm the role never changes.

**Acceptance Scenarios**:

1. **Given** any caller, including a super admin, **When** they send `role=super_admin` in an otherwise valid request to `POST /api/users`, `PUT /api/users/{user}`, `POST /api/superadmin/users` or `PUT /api/superadmin/users/{user}/access`, **Then** they get `403` with the message "The super admin role cannot be assigned through the API." and nothing is written. **[V1]**
2. **Given** a super admin as the target, **When** any `/api/superadmin/users/{user}` endpoint is called, **Then** it returns `404`.
3. **Given** a super admin as the target, **When** a super admin sends account or hotel links to the tenant `PUT /api/users/{user}`, **Then** it returns `403` and no link is written. **[V11]**
4. **Given** the user list, **When** a super admin lists or filters users in any way, including `role=super_admin`, **Then** no super admin appears.

---

### User Story 3 - Lock out a compromised user (Priority: P1)

A user's password may have leaked. A super admin revokes everything the user holds and sends them a reset email. The user chooses a new password, and nothing issued before the reset still works.

**Why this priority**: This is the operator's security response. Without it, a leaked credential stays usable until someone edits the database.

**Independent Test**: Give a user an API token, an unredeemed pairing code and a paired WhatsApp device. Revoke access, then confirm that the token and code are refused and that the WhatsApp number gets the not-paired reply instead of the Admin Advisor.

**Acceptance Scenarios**:

1. **Given** a user with API tokens, a pairing code and a paired device, **When** a super admin revokes access, **Then** all the tokens and the code stop working, the device is unpaired, and one platform event is recorded.
2. **Given** a user whose device was unpaired, **When** they message on WhatsApp, **Then** the Admin Advisor does not answer them.
3. **Given** any user, **When** a super admin requests a password reset, **Then** the user receives a reset email linking to the frontend's reset page, and one platform event is recorded.
4. **Given** a valid reset link, **When** the user submits a new password, **Then** the password changes, the link stops working, all their tokens are revoked and their device is unpaired, and no platform event is recorded, because no super admin acted.
5. **Given** an invalid or expired token, or an unknown email, **When** a reset is submitted, **Then** the response is the same `422` in every case, and nothing changes.
6. **Given** a reset email was sent under a minute ago, **When** a super admin requests another for that user, **Then** they get `429`, no email is sent and no event is recorded. **[V3]**

---

### User Story 4 - Administer users across the platform (Priority: P2)

A super admin finds any user by account, hotel or role, edits their details, and changes what they can reach: role, account-wide role, hotel memberships and staff role.

**Why this priority**: Onboarding is only the start. Customers change staff, and without this each change would need database work again.

**Independent Test**: Create a user in one account, change each kind of access, and confirm what the user can reach after each change, including that another account's hotel is refused.

**Acceptance Scenarios**:

1. **Given** users attached by account, by home hotel and by hotel membership, **When** a super admin filters by that account or hotel, **Then** all of them are returned, once each.
2. **Given** a user in account A, **When** a super admin adds a membership in a hotel of account B, **Then** it returns `422` and nothing changes.
3. **Given** a user, **When** a super admin sets `hotel_ids`, **Then** the user's memberships become exactly that list, and their home hotel stays reachable whether or not it is listed. **[V8]**
4. **Given** a user with a role change or memberships, **When** a super admin reads the response, **Then** it shows the user's memberships as well as their role, home hotel, account and staff role. **[V9]**
5. **Given** an account-wide role being set on an admin, **When** the change is saved, **Then** the user is attached to the account they already belong to, and can reach all of its hotels.
6. **Given** an employee, **When** a super admin assigns a staff role from the user's home hotel, **Then** it is saved. A staff role from another hotel, or for a user with no home hotel, returns `403`.
7. **Given** an employee with a staff role, **When** they become an admin, **Then** the staff role is removed.
8. **Given** an employee being made an admin at a hotel with no owner, **When** the change is saved, **Then** they become the owner of that hotel. **[V10]**
9. **Given** a hotel's owner being made an employee, **When** the change is saved, **Then** the hotel has no owner, so the next admin added can own it. **[V10]**
10. **Given** a user with an account-wide role being made an employee, **When** the change is saved, **Then** the account-wide role is removed. An employee cannot be given one (`422`). **[V13]**

---

### User Story 5 - Administer accounts and hotels (Priority: P2)

A super admin lists, edits and deletes accounts, and lists, edits, moves and deletes hotels across all accounts.

**Why this priority**: Details change after onboarding and customers leave, but none of this blocks a first customer.

**Independent Test**: Edit and delete accounts and hotels through the API, and confirm that counts, slug rules and deletion rules hold.

**Acceptance Scenarios**:

1. **Given** accounts with hotels and users, **When** a super admin lists accounts, **Then** each shows its name, slug, country, default currency, default timezone, number of live hotels, and number of distinct non-super-admin users, whether attached by account, home hotel or membership.
2. **Given** an account with a live hotel, **When** a super admin deletes it, **Then** it returns `422` and nothing changes. With only deleted hotels or none, it is soft-deleted.
3. **Given** a request carrying `contract_value_monthly` or `contract_currency`, **When** an account is created or updated, **Then** those values are ignored, and no response ever includes them.
4. **Given** the account list, **When** a super admin filters, sorts or searches on a contract field, **Then** the filter or sort is refused with `422`, and search does not match contract values. **[V2]**
5. **Given** a soft-deleted hotel in the same account, **When** a super admin creates a hotel there with the same slug, **Then** the deleted hotel is restored with its data and the new details, and the account's seat counts include it again. **[V5]**
6. **Given** a soft-deleted hotel in a different account, **When** a super admin creates a hotel with the same slug, **Then** it returns `422` "The slug has already been taken." and the deleted hotel stays deleted, in its own account, with its data.
7. **Given** a slug held by any hotel or account, including a soft-deleted one, **When** an edit or a new account would reuse it, **Then** it returns `422`, not a server error. **[V4]**
8. **Given** a hotel with memberships, or with staff attached directly to its current account, or whose home-hotel staff have memberships in other hotels, or whose owner's home hotel is elsewhere, **When** a super admin moves it to another account, **Then** it returns `422` and nothing changes. **[V6]**
9. **Given** a hotel whose users are attached only through their home hotel, **When** a super admin moves it to another live account, **Then** the move succeeds, and those users and the seat counts follow it.
10. **Given** a soft-deleted hotel, **When** its staff call any hotel endpoint, by default or by naming it with `X-Hotel-Id`, **Then** they get none of its data and nothing they create lands in it. After a restore, the same users reach it again with their existing tokens.
11. **Given** a hotel admin, **When** they call the tenant `POST /api/hotel` or `DELETE /api/hotel/{hotel}`, or a super admin calls either, **Then** the response is `405`. Viewing and editing their own hotel still work as before.

---

### User Story 6 - Platform actions stay out of hotel staff's sight (Priority: P2)

Every administrative write is recorded with the super admin as actor, in a part of the audit trail no hotel can read, even when the event is about that hotel or one of its records.

**Why this priority**: The audit trail is what makes these powerful endpoints accountable, but operator actions on a customer must not leak to that customer's staff.

**Independent Test**: Record a platform event about a record that hotel staff can read the history of, then confirm that its hotel's admin does not see it on any event log endpoint.

**Acceptance Scenarios**:

1. **Given** any account, hotel or user write, password reset or revoke through the new endpoints, **When** it completes, **Then** exactly one platform event is recorded, with no hotel attached and the super admin as actor.
2. **Given** a platform event about a hotel-owned record, **When** that hotel's admin reads the record's history or any other tenant event log, **Then** the event is absent.
3. **Given** a request where a current hotel is set, **When** a platform event is recorded, **Then** it still has no hotel attached.
4. **Given** any event recording a user write, **When** it is read, **Then** it contains no password, password hash or reset token.
5. **Given** a tenant edit to a user, **When** it completes, **Then** it writes no platform event.

---

### User Story 7 - Access stays restricted (Priority: P2)

The new endpoints inherit the super admin guard and every protection of the stack they join.

**Why this priority**: These endpoints reach every customer's data and can change who can log in.

**Independent Test**: Call every new address as a hotel admin, as an employee, without a token and without an API key.

**Acceptance Scenarios**:

1. **Given** a hotel admin or employee, **When** they call any new `/api/superadmin` address, **Then** they are refused with `403` or `404`, and nothing changes.
2. **Given** a request with no valid login token or no valid API key, **When** it calls any new address, **Then** it is refused with `401`.
3. **Given** the public reset endpoint, **When** it is called repeatedly, **Then** it is rate limited like login, and refused without a valid API key.

### Edge Cases

- **Reset email cannot be sent when a user is created**: the response is still `201`, with `reset_email_sent: false`. The user exists, the operator resends, and creating the user again is not needed. **[V3]**
- **Reset email cannot be sent on a resend**: the response is `503` and no event is recorded. **[V3]**
- **Link expires before the user opens it** (72 hours for a new user's first link, 60 minutes for any other): the user cannot set a password and the operator sends another, which lasts 60 minutes. **[V3]**
- **User's email changes while a link is pending**: the old link stops working, and the operator sends another to the new address.
- **Revoking a user with no tokens or device**: succeeds and records one event.
- **`hotel_id` and `hotel_group_id` naming different accounts when creating a user**: `422`. **[V7]**
- **New user without a home hotel, even with an account**: `422`. **[V7]**
- **Account-wide role for a user with no account**: `422`, because there is no account to attach them to.
- **Membership or home hotel naming a soft-deleted hotel**: `422`.
- **Hotel created or moved into a soft-deleted account**: `404` in the path, `422` in the body.
- **Unknown or soft-deleted account, hotel or user in the path**: `404`.
- **Deleting a hotel with staff attached**: the hotel is soft-deleted and staff can no longer reach it through the API. Their links and tokens are kept, so a user with no other hotel can still log in but sees no hotel data, and access returns if the hotel is restored.
- **Filtering or sorting users on `password` or `remember_token`**: refused with `422`. **[V2]**

## Requirements *(mandatory)*

### Functional Requirements

**Accounts**

- **FR-001**: Super admins MUST be able to list, create, view, edit and soft-delete accounts.
- **FR-002**: An account MUST NOT be deletable while it has a live hotel.
- **FR-003**: Account responses MUST contain only id, name, slug, country, default currency, default timezone, hotel count and user count.
- **FR-004**: The user count MUST count each non-super-admin user once, whether attached through the account, a home hotel or a membership, in live hotels only.
- **FR-005**: Contract fields MUST NOT be returned, accepted, filtered on, sorted on or matched by search. **[V2]**
- **FR-006**: The account list MUST support the shared search, sort and pagination behaviour, limited to the account's public fields.

**Hotels**

- **FR-007**: Super admins MUST be able to list hotels across accounts, filter by account, view, create under an account, edit and soft-delete them.
- **FR-008**: Editing through these endpoints MUST be limited to name, slug and account. All other hotel settings remain the hotel admin's.
- **FR-009**: A created hotel MUST receive the same operational defaults as any new hotel, and MUST ignore any client-supplied default team or category.
- **FR-010**: A hotel created without a currency or timezone MUST take the account's defaults. **[V12]**
- **FR-011**: Creating a hotel with the slug of a soft-deleted hotel MUST restore that hotel only when it belongs to the same account. Otherwise the request MUST return `422` "The slug has already been taken." and leave the deleted hotel as it was.
- **FR-012**: A restored hotel MUST count again in its account's seats. **[V5]**
- **FR-013**: A slug edit or new account slug that collides with any record, including a soft-deleted one, MUST return `422`. **[V4]**
- **FR-014**: Moving a hotel MUST be refused with `422` while it has memberships, staff attached directly to its account, home-hotel staff with memberships in other hotels, or an owner whose home hotel is elsewhere, and when the destination is not a live account. **[V6]**
- **FR-015**: Hotel creation and deletion MUST NOT exist on the tenant hotel endpoints, for any caller. Tenant viewing, listing and editing MUST behave as before, except that a tenant edit MUST NOT change a hotel's account or owner, for any caller.
- **FR-015a**: A soft-deleted hotel MUST NOT be reachable through the API by any user who is not a super admin, by any route: home hotel, membership or account. It MUST NOT be used as a user's current hotel. Links and tokens MUST be kept, so that a restore returns access. WhatsApp and scheduled work are not changed by this requirement.

**Users**

- **FR-016**: Super admins MUST be able to list, view, create and edit every user who is not a super admin, and filter by account, hotel and role. Account and hotel filters MUST find users attached by account, home hotel or membership.
- **FR-017**: A super admin target MUST return `404` on every user administration endpoint and MUST NOT appear in any list.
- **FR-018**: A new user MUST receive a random password that is never returned, logged, emailed or recorded, and a password reset email whose link stays valid for 72 hours. No request MAY set a password through these endpoints.
- **FR-019**: A new user MUST be given a home hotel. An account is optional, and when given, the hotel MUST belong to it. **[V7]**
- **FR-020**: A new user with role `admin` at a hotel with no owner MUST become its owner.
- **FR-021**: Filtering and sorting users MUST NOT be possible on secret columns. **[V2]**
- **FR-022**: Super admins MUST be able to change a user's role (`admin` or `employee`), account-wide role (`owner`, `group_admin` or none), hotel memberships and staff role.
- **FR-023**: Setting an account-wide role MUST also attach the user to the account they belong to. It MUST be refused for an employee, and removed when an admin becomes an employee. **[V13]**
- **FR-024**: A hotel added to a user's memberships MUST be live and in the user's account. Removing or keeping one needs no such check, so access can always be narrowed. Setting them MUST replace the list, and MUST NOT remove the user's home hotel. A user's home hotel MUST NOT be changeable through the API after creation. **[V8]**
- **FR-025**: Staff role rules MUST match the tenant endpoint: employees only, from the user's home hotel, and removed when the user stops being an employee.
- **FR-026**: Ownership MUST follow role changes: an employee made an admin at a hotel with no owner becomes its owner, and an owner made an employee stops being the owner. **[V10]**
- **FR-027**: User responses from these endpoints MUST include memberships. Tenant user responses MUST NOT change. **[V9]**

**Super admin role**

- **FR-028**: On an otherwise valid request, `role=super_admin` MUST be refused with `403` and the message "The super admin role cannot be assigned through the API." on every endpoint that writes a role, for every caller. A request that is also invalid in another way MAY return `422` first, and writes nothing either way. Any other invalid role MUST return `422`. **[V1]**
- **FR-029**: No API request MAY attach a super admin to a hotel or an account, or change a super admin's role, since nothing in the API could grant it back. **[V11]**

**Reset access**

- **FR-030**: Super admins MUST be able to send a user a password reset email whose link opens the frontend's reset page and stays valid for 60 minutes. The longer life in FR-018 MUST NOT apply to these links, and sending one MUST invalidate any link still pending for that user.
- **FR-031**: A reset request within the resend window MUST return `429`, send nothing and record nothing. **[V3]**
- **FR-032**: The email MUST be sent during the request. A mail failure MUST NOT fail or undo a user creation: the response MUST be `201` and state `reset_email_sent: false` (`true` when sent). A failed resend MUST return `503` and record no event. Every mail failure MUST also reach error monitoring. **[V3]**
- **FR-033**: Super admins MUST be able to revoke a user's access, which removes all their API tokens and pending pairing codes and unpairs their WhatsApp device, all or nothing.
- **FR-034**: A public endpoint MUST accept a reset token, email and confirmed new password, subject to the API key and login-style rate limiting. Failure MUST NOT reveal whether the email exists.
- **FR-035**: A completed reset MUST clear access as in FR-033 and MUST NOT record a platform event.

**Audit**

- **FR-036**: Each successful write in FR-001 to FR-033 MUST record exactly one platform event, named `platform.account.*`, `platform.hotel.*` or `platform.user.*`. Creating a user records only the creation, not the first reset email. An edit that changes nothing records no event. A revoke always records one, even with nothing to revoke.
- **FR-037**: Platform events MUST always have no hotel attached, whatever the subject or the current hotel, and MUST NOT appear on any tenant event log endpoint.
- **FR-038**: Platform events MUST identify the super admin as actor and MUST NOT contain passwords, hashes or reset tokens.
- **FR-039**: Restoring a hotel MUST record the creation event and show that it was a restore.
- **FR-040**: Moved controllers and tenant user edits MUST keep their current audit behaviour and MUST NOT write platform events.

**Guard and documentation**

- **FR-041**: Every new `/api/superadmin` endpoint MUST inherit the file-level super admin guard, and MUST work without relying on the super admin's unrestricted tenant scope or on any tenant policy.
- **FR-042**: API documentation MUST cover the new endpoints, the removed tenant hotel endpoints, the refused role, password reset and device unpairing, and breaking changes MUST be summarized in a dated changes note.

### Endpoint Map

All under `/api/superadmin` unless marked.

| Method | Path | Purpose |
| --- | --- | --- |
| `GET`, `POST` | `/accounts` | List, create |
| `GET`, `PUT`, `DELETE` | `/accounts/{account}` | View, edit, soft-delete |
| `POST` | `/accounts/{account}/hotels` | Create or restore a hotel |
| `GET` | `/hotels` | List across accounts |
| `GET`, `PUT`, `DELETE` | `/hotels/{hotel}` | View, edit or move, soft-delete |
| `GET`, `POST` | `/users` | List, create |
| `GET`, `PUT` | `/users/{user}` | View, edit name, email and phone |
| `PUT` | `/users/{user}/access` | Change role, account-wide role, memberships, staff role |
| `POST` | `/users/{user}/password-reset` | Send a reset email |
| `POST` | `/users/{user}/revoke-tokens` | Revoke tokens and unpair the device |
| `POST` | `/api/reset-password` (public, API key) | Set a new password with a reset token |
| `POST`, `DELETE` | `/api/hotel`, `/api/hotel/{hotel}` (tenant) | Removed: now `405` |

### Key Entities

- **Account**: the paying customer (a hotel group). Holds hotels and account-attached users. Its contract fields stay hidden in this version.
- **Hotel**: belongs to one account, and may have one owner, who is an admin. Soft-deleted hotels keep their slug and data.
- **User**: an account owner, hotel admin or employee, with a home hotel (required when created through these endpoints), optional account link, account-wide role, memberships and staff role. Super admins are users outside this administration.
- **Hotel membership**: grants a user access to one more hotel in their own account.
- **Access credentials**: a user's API tokens, pending pairing codes and paired WhatsApp device, which are removed together.
- **Password link**: the emailed link that lets a user set a password, single use and known only to the recipient. A **first-password link** goes to a new user and lasts 72 hours. A **reset link** is any other and lasts 60 minutes. The code calls the first kind an invitation.
- **Platform event**: an audit entry about an operator action, with no hotel attached and invisible to tenants.

## Success Criteria *(mandatory)*

### Measurable Outcomes

- **SC-001**: A customer with one hotel and one admin can be onboarded through the API alone, with zero database or seeder steps, and the admin can open their hotel on first login.
- **SC-002**: 100% of attempts to grant the super admin role, across all four role-writing endpoints and every caller type, are refused and change nothing.
- **SC-003**: After a lockout, 100% of the user's earlier tokens and pairing codes are refused, and their next WhatsApp message gets no Admin Advisor answer.
- **SC-004**: At no point does a password or reset token appear in a response, audit event or log entry produced by these endpoints.
- **SC-005**: Every administrative write produces exactly one platform event, and zero platform events are visible to any hotel user.
- **SC-006**: 100% of new endpoints refuse hotel admins, employees, and callers without a token or API key.
- **SC-007**: No collision on a slug, including with a soft-deleted record, produces a server error, and no slug reuse moves one customer's data to another account.
- **SC-008**: The existing test suite passes, changed only where this spec deliberately changes behaviour: tenant hotel creation and deletion, super admin role grants, and staff access to soft-deleted hotels.

## Assumptions

- **Stage 1 comes first**: the `/api/superadmin` route move on branch `022-superadmin-route-move` ships before this feature.
- **Out of scope, per the request**: suspension columns, filters, resource fields and actions (stage 3); the dashboard (stage 4); impersonation (stage 5); removing the super admin bypasses (stage 6); rollback plans and data backfills.
- **Also not included**: deleting users, restoring accounts, a self-service "forgot password" endpoint, editing staff role definitions, changing a user's home hotel, transferring a hotel's ownership directly, and a UI for reading platform events.
- **Self-registration** through `/api/register` is unchanged.
- **Links** can be resent once a minute, the existing setting. Links other than a new user's first one keep the existing 60-minute life.
- **The frontend** provides a reset page and sets its address through a new configuration value. Frontend changes follow from a handoff after the backend is done.
- **Account-wide access does not extend hotel settings**: hotel settings depend on hotel ownership, so an account owner can open only the hotels they own, not every hotel in the account. WhatsApp pairing and the tenant user endpoints use the home hotel. Those rules do not change here.
- **The login rate limit** is shared with the reset endpoint, so reset attempts count toward the same limit.
