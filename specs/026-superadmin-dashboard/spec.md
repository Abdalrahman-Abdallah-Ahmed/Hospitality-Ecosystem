# Feature Specification: Super Admin Platform Dashboard

**Feature Branch**: `026-superadmin-dashboard`

**Created**: 2026-11-08

**Status**: Draft

**Input**: User description: "Stage 4 of docs/designs/super-admin-platform.md: a platform dashboard for super admins with counts, usage and AI cost, and activity per hotel. Three read-only sections load separately. Counts cover accounts, hotels and users (excluding super admins), active vs suspended, plus signups per day. Usage gives usage totals and AI cost in USD per account. Activity gives reservations and bookings created per hotel, with no revenue. The window is optional UTC dates, 30 days ending today by default, at most 12 months. Each section is cached for 15 minutes and reports when its figures were computed. Revenue, health and failure signals, snapshot tables, live updates, impersonation, removing the super admin bypasses, rollback, backfills and deploy coordination are out of scope. The Stage 4 plan must be verified against the code, and any mismatch reported."

## Problem and Need

The platform operator has no single view of the platform. Counting customers, spotting suspended ones, comparing AI cost between accounts or seeing which hotels are in use means calling several reports or querying the database by hand.

This feature gives the operator one dashboard that answers three questions: how many customers do we have and how many are stopped, what does each account use and cost us, and which hotels are active. Figures may be up to 15 minutes old, and each section says when it was computed.

**Actors**:

- **Super admin**: the platform operator, the only user who can read the dashboard.
- **Frontend application**: the API client. It loads each section on its own and shows how old the figures are.
- **Everyone else**: admins, account owners and employees are refused, whichever hotel they belong to.

Requirements tagged **[V1]** to **[V16]** settle a point where the design's Stage 4 plan disagrees with the code or leaves a gap. The evidence and the chosen default for each are in [plan-verification.md](plan-verification.md).

## Clarifications

### Session 2026-11-08

- Q: When a user is linked to a suspended account but can also reach an active hotel in another account, should the dashboard count them as active or suspended? → A: Active. A user counts as suspended only when suspension actually refuses them at login. Users have no suspension of their own, and adding one is out of scope.
- Q: Should each account's AI cost on the dashboard also say whether it was measured or estimated, and should cost from calls logged with no account be shown separately? → A: Yes to both. Each account gets `ai_cost_usd`, `cost_basis` and `estimated_rows_pct`, and the response gets a separate `unattributed` entry with `calls` and `ai_cost_usd`.
- Q: What should the dashboard do when `from` or `to` is a date after today? → A: Reject it with `422`. The frontend caps its date picker at today.
- Q: Should a signup count every new account and user, or only customers who registered themselves? → A: Every one, as designed. `accounts` is every account created, including ones a super admin made and ones later soft-deleted. `users` is every user created except super admins, staff included. The API docs state this, so `users` isn't read as new customers.

## User Scenarios & Testing *(mandatory)*

### User Story 1 - See how many customers there are and who is stopped (Priority: P1)

A super admin opens the dashboard and sees how many accounts, hotels and users exist, how many of each are active or suspended, and how many signups happened per day.

**Why this priority**: This is the first thing the operator needs, and it is how they see the result of suspending a non-paying customer.

**Independent Test**: Create accounts, hotels and users in a known mix of suspended states, call the counts section, and compare every figure with the expected total.

**Acceptance Scenarios**:

1. **Given** a platform with suspended and active accounts, **When** a super admin calls `GET /api/superadmin/dashboard/counts`, **Then** the response gives `accounts`, `hotels` and `users`, each as `{active, suspended}`, plus `signups`, `from`, `to` and `cached_at`.
2. **Given** a super admin user exists, **When** counts are read, **Then** that user appears in no user figure and no signup figure.
3. **Given** an active account with one hotel suspended on its own and another hotel not suspended, **When** counts are read, **Then** the account counts as active, one hotel as suspended and one as active.
4. **Given** a suspended account with two hotels, **When** counts are read, **Then** the account and both hotels count as suspended.
5. **Given** a customer registers through `POST /api/register`, **When** counts are read for that day, **Then** that day's signups show one more account and one more user.
6. **Given** a window in which some days have no signups, **When** counts are read, **Then** those days are listed with zeros, so the list has one row for each day of the window.

---

### User Story 2 - Count suspended users the way access actually works (Priority: P1)

A user counts as suspended exactly when the platform would refuse them for suspension. The operator can trust that the number matches what customers experience.

**Why this priority**: A figure that disagrees with login is worse than no figure. It is also the easiest count to get wrong, because users attach to hotels in several ways.

**Independent Test**: Build one user for each row below. For each, compare the count with the result of an actual login attempt.

**Acceptance Scenarios**:

1. **Given** an owner whose home hotel is suspended while another hotel of the account is active, and who has no membership row for that other hotel, **When** counts are read, **Then** the owner counts as active. **[V2]**
2. **Given** an owner whose account's hotels are all suspended individually, **When** counts are read, **Then** the owner counts as suspended.
3. **Given** a user with a direct account link but no group role, whose home hotel is suspended while the account's other hotels are active, **When** counts are read, **Then** the user counts as suspended, because they reach only their home hotel and memberships. **[V2]**
4. **Given** a user whose home hotel is suspended and who is a member of an active hotel, **When** counts are read, **Then** the user counts as active.
5. **Given** a user whose only hotel was deleted and whose account is suspended, **When** counts are read, **Then** the user counts as suspended.
6. **Given** a user with no hotels and no account, **When** counts are read, **Then** the user counts as active, because nothing would refuse them for suspension. **[V9]**
7. **Given** a user linked to a suspended account who also reaches an active hotel in another account, **When** counts are read, **Then** the user counts as active, as they would at login. **[V1]**
8. **Given** the owner of a suspended account that has no hotels, **When** counts are read, **Then** the owner counts as suspended.
9. **Given** a user whose tokens were revoked by a super admin, **When** counts are read, **Then** their count does not change, because revoking is not a suspension. **[V9]**

---

### User Story 3 - Compare usage and AI cost across accounts (Priority: P1)

A super admin sees, for each account, what it used and what its AI cost the platform in USD, and whether the cost figure was measured or estimated.

**Why this priority**: This is the main commercial reason for the dashboard. It shows which customers cost more to serve.

**Independent Test**: Record usage and AI cost for two accounts, call the usage section, and compare each account's figures with `GET /api/superadmin/usage` and `GET /api/superadmin/ai-cost` for the same window.

**Acceptance Scenarios**:

1. **Given** two accounts with recorded usage and AI cost, **When** a super admin calls `GET /api/superadmin/dashboard/usage`, **Then** `accounts` lists each one with `hotel_group_id`, `name`, `usage`, `ai`, `ai_cost_usd`, `cost_basis` and `estimated_rows_pct`, plus `from`, `to` and `cached_at` for the response. **[V5, V6]**
2. **Given** the same window, **When** the dashboard's figures are compared with the existing usage and AI cost reports, **Then** they agree.
3. **Given** an account with no activity in the window, **When** usage is read, **Then** the account is listed with zero cost and `cost_basis` of `no data`.
4. **Given** an account whose AI calls include a model with no price on file, **When** usage is read, **Then** its `cost_basis` says `estimated` or `mixed` rather than presenting the figure as measured. **[V6]**
5. **Given** AI calls logged with no account, **When** usage is read, **Then** their calls, cost and cost basis appear in a separate `unattributed` entry. **[V6, V14]**
6. **Given** a soft-deleted account with AI cost in the window, **When** usage is read, **Then** it is not listed, as in the existing reports, and its cost is included in a separate `deleted_accounts` total. **[V7, V15]**

---

### User Story 4 - See which hotels are in use (Priority: P2)

A super admin sees how many reservations and bookings each hotel created in the window, to spot hotels that are busy or have gone quiet.

**Why this priority**: It is useful for follow-up with customers, but it does not drive a commercial decision the way counts and cost do.

**Independent Test**: Create reservations and bookings in two hotels of different accounts, inside and outside the window, call the activity section, and compare the counts.

**Acceptance Scenarios**:

1. **Given** hotels in two accounts, **When** a super admin calls `GET /api/superadmin/dashboard/activity`, **Then** `hotels` lists each hotel with `hotel_id`, `hotel_group_id`, `name`, `reservations` and `bookings`, plus `from`, `to` and `cached_at`.
2. **Given** a hotel with no reservations or bookings in the window, **When** activity is read, **Then** it is listed with zeros. **[V12]**
3. **Given** a suspended hotel, **When** activity is read, **Then** it is listed with its counts. **[V12]**
4. **Given** a record created before `from` or after `to`, **When** activity is read, **Then** it is not counted.
5. **Given** any activity response, **When** it is inspected, **Then** it contains no revenue, price or currency figure.

---

### User Story 5 - Choose the window (Priority: P2)

A super admin looks at the default 30 days, or picks another window of up to 12 months.

**Why this priority**: The default covers the common case. A chosen window is needed for monthly reviews.

**Independent Test**: Call any section with no dates, with each date alone, with a 12-month range and with a longer one.

**Acceptance Scenarios**:

1. **Given** no dates, **When** a section is read, **Then** the window is the 30 calendar days ending today, counting today, in UTC. **[V10]**
2. **Given** only `from`, **When** a section is read, **Then** `to` is today. **[V10]**
3. **Given** only `to`, **When** a section is read, **Then** `from` is 29 days before it. **[V10]**
4. **Given** `from` and `to` exactly 12 months apart, **When** a section is read, **Then** it succeeds.
5. **Given** `to` more than 12 months after `from`, **When** a section is read, **Then** the response is `422`.
6. **Given** `to` before `from`, an empty date, a date that is not `Y-m-d`, or a date after today, **When** a section is read, **Then** the response is `422`. **[V10]**
7. **Given** a record created at the first or last second of a window day, **When** a section is read, **Then** it is counted.

---

### User Story 6 - Know how old the figures are (Priority: P2)

Each section is reused for 15 minutes, and tells the super admin when it was computed. A section that is slow does not hold up the others.

**Why this priority**: The cache keeps cross-hotel reads cheap, but a super admin must not mistake old figures for live ones (risk R10).

**Independent Test**: Read a section, change the data, read it again with time frozen and then after 15 minutes.

**Acceptance Scenarios**:

1. **Given** a section read twice within 15 minutes for the same window, **When** the data changes between the reads, **Then** the second read returns the same figures and the original `cached_at`.
2. **Given** a section read more than 15 minutes later, **When** it is read, **Then** the figures are new and so is `cached_at`.
3. **Given** a super admin suspends an account, **When** they read counts within 15 minutes, **Then** the old figures may still show, and `cached_at` shows their age.
4. **Given** two different sections, or two different windows of usage or activity, **When** they are read, **Then** each has its own cached figures and its own `cached_at`. In counts, only `signups` is per window: `accounts`, `hotels` and `users` are the same in every window, and `cached_at` is the older of the two entries. **[V16]**
5. **Given** a cached section, **When** it is read back, **Then** the response is identical to the first one, with no empty or broken values.

---

### User Story 7 - Only super admins can read it (Priority: P1)

No customer can reach the dashboard, which shows every customer's data and the platform's AI cost.

**Why this priority**: This is commercial data about every customer. The feature cannot ship without this protection.

**Independent Test**: Call all three sections as an admin, an owner, an employee and with no token.

**Acceptance Scenarios**:

1. **Given** an admin, owner or employee, **When** they call any section, **Then** the response is `403` and no figures are returned.
2. **Given** a request with no token, **When** it calls any section, **Then** the response is `401`.
3. **Given** a super admin sending `X-Hotel-Id`, **When** they read a section, **Then** the figures still cover the whole platform. **[V11]**

### Edge Cases

- **Empty platform**: every count and signup row is zero, and the usage section's `accounts` and the activity section's `hotels` are empty lists.
- **An account with no hotels**: it counts as an account, and appears in usage at zero, like every other account. A hotel moved to another account leaves such an account behind. **[V8]**
- **A hotel moved between accounts**: activity shows it under its current account. Usage stays with the account that incurred it.
- **A deleted hotel**: it is not counted and not listed in activity. Its AI usage stays in its account's `ai` figures, as in the existing usage report.
- **A deleted account**: it is left out of `accounts`, but still counts in the signups of the day it was created. **[V8]**
- **A hard-deleted user**: they disappear from past signup figures, because users have no soft delete. **[V8]**
- **A hotel or account with `is_active` off**: it counts as active on the dashboard if it is not suspended. `is_active` means something else (spec 024). **[V9]**
- **A cancelled reservation**: it still counts as created in its window. A soft-deleted one does not. **[V12]**
- **Imported reservations**: they count on the day of the import. **[V12]**
- **A window ending in a month with no seat count**: seats read as not measured, not zero, as in the existing usage report. **[V5]**

## Requirements *(mandatory)*

### Functional Requirements

**Access and shape**

- **FR-001**: The system MUST provide three separate read-only endpoints: `GET /api/superadmin/dashboard/counts`, `/dashboard/usage` and `/dashboard/activity`.
- **FR-002**: The endpoints MUST be reachable only by super admins, through the guard on the whole super admin route file, and MUST call no tenant policy.
- **FR-003**: Each endpoint MUST compute only its own section, so that a slow section does not delay the others.
- **FR-004**: The endpoints MUST NOT change data, write an audit event or expose revenue in any form.
- **FR-005**: Figures MUST cover every hotel and account whatever the caller's request headers or tenant context say, by lifting the tenant scope explicitly. **[V11]**

**Window**

- **FR-006**: `from` and `to` MUST be optional and in `Y-m-d` format, read as UTC dates.
- **FR-007**: With no dates, the window MUST be the 30 calendar days ending today, counting today. With only `from`, `to` MUST be today. With only `to`, `from` MUST be 29 days before it. **[V10]**
- **FR-008**: The system MUST return `422` when `to` is before `from`, when `to` is later than `from` plus 12 months, when either date is after today, or when a date has the wrong format. Adding 12 months to 29 February MUST give 28 February, not 1 March. **[V10]**
- **FR-009**: Queries MUST read from the start of `from` to the end of `to`, in UTC. Each response MUST echo the resolved `from` and `to`.

**Counts**

- **FR-010**: Counts MUST give `accounts`, `hotels` and `users`, each as `{active, suspended}`, covering records that are not deleted. "Active" means not suspended, and `is_active` plays no part. These three figures show the current state, and the window affects only `signups`. **[V9]**
- **FR-011**: An account MUST count as suspended when it is suspended itself. A hotel MUST count as suspended under the existing rule: it or its account is suspended, including an account that was soft-deleted.
- **FR-012**: Users MUST exclude super admins.
- **FR-013**: A user MUST count as suspended exactly when suspension would refuse them at login: if they reach at least one live hotel, when all of those are suspended; if they reach none, when their account is suspended. Users have no suspension of their own. **[V1]**
- **FR-014**: A user's hotels MUST be the ones they actually reach: every live hotel of the account for an owner, and otherwise their live home hotel and live memberships. A direct account link with no group role adds nothing. **[V2]**
- **FR-015**: A user who reaches no hotel and has no suspended account MUST count as active, including a user with no account at all. **[V9]**
- **FR-016**: The user count MUST NOT run queries per user. **[V3]**
- **FR-017**: `signups` MUST list one row per day of the window as `{date, accounts, users}`, with zeros for days with none, where days are UTC. **[V8]**
- **FR-018**: Signups MUST count accounts and users by the day they were created: every account, soft-deleted or not, and every user who is not a super admin. Accounts from `POST /api/register` MUST count. **[V8]**

**Usage and cost**

- **FR-019**: Usage MUST list every account that is not deleted, by name, as `{hotel_group_id, name, usage, ai, ai_cost_usd, cost_basis, estimated_rows_pct}`. **[V5, V6, V7]**
- **FR-020**: `usage` and `ai` MUST use the same calculation and wording as the existing usage report, so the two cannot disagree. `usage` MUST hold that report's seats, features and recommendations. **[V5]**
- **FR-021**: `ai_cost_usd` MUST be the sum of the account's recorded AI cost in the window, in USD, to six decimals, and MUST equal the existing AI cost report's figure for the same window.
- **FR-022**: `cost_basis` (`measured`, `estimated`, `mixed` or `no data`) and `estimated_rows_pct` MUST use the same definitions as the existing AI cost report. **[V6]**
- **FR-023**: The response MUST include `unattributed`, with the `calls`, `ai_cost_usd`, `cost_basis` and `estimated_rows_pct` of AI calls logged with no account. **[V6, V14]**
- **FR-023a**: The response MUST include `deleted_accounts`, a single total in the same shape, for AI calls of soft-deleted accounts in the window. **[V15]**
- **FR-024**: Cost MUST be calculated apart from the usage figures and added to them in the super admin controller, so that the existing rule keeping cost out of general reports still holds unchanged.

**Activity**

- **FR-025**: Activity MUST list every hotel that is not deleted, suspended ones included, as `{hotel_id, hotel_group_id, name, reservations, bookings}`, with zeros where there is nothing. **[V12]**
- **FR-026**: `reservations` and `bookings` MUST count the records created in the window that are not soft-deleted, whatever their status. **[V12]**

**Cache**

- **FR-027**: Each section MUST be cached for 15 minutes in the default cache store under `superadmin:dashboard:v1:{section}:{from}:{to}`, using the resolved dates. In counts, that entry holds `signups`, and `accounts`, `hotels` and `users` are cached once under `superadmin:dashboard:v1:counts:current`, because they don't depend on the window. **[V16]**
- **FR-028**: The cached value MUST be plain arrays and scalars, with nothing that needs a class to read back.
- **FR-029**: `cached_at` MUST be stored with the figures and give the UTC time they were computed, so a cache hit keeps the original time. In counts, it MUST be the older of the two entries. **[V13, V16]**
- **FR-030**: Writes MUST NOT clear the cache. A super admin's own change can take up to 15 minutes to appear.

**Documentation and tests**

- **FR-031**: `docs/superadmin-dashboard-api-documentation.md` MUST document the three endpoints, their parameters, response shapes, the 15-minute cache, and what "active", "suspended" and "signup" mean.
- **FR-032**: Tests MUST cover every acceptance scenario above, including a comparison of the user count with real login results, and of usage and cost with the existing reports. **[V3, V4]**

### Endpoint Map

| Endpoint | Returns |
| --- | --- |
| `GET /api/superadmin/dashboard/counts` | `accounts`, `hotels`, `users` as `{active, suspended}`; `signups` as `[{date, accounts, users}]`; `from`, `to`, `cached_at` |
| `GET /api/superadmin/dashboard/usage` | `accounts` as `[{hotel_group_id, name, usage, ai, ai_cost_usd, cost_basis, estimated_rows_pct}]`; `unattributed` and `deleted_accounts` as `{calls, ai_cost_usd, cost_basis, estimated_rows_pct}`; `from`, `to`, `cached_at` |
| `GET /api/superadmin/dashboard/activity` | `hotels` as `[{hotel_id, hotel_group_id, name, reservations, bookings}]`; `from`, `to`, `cached_at` |

All three accept optional `from` and `to`, and return `403` for anyone but a super admin and `422` for an invalid window.

### Key Entities

No new data is stored.

- **Account**: the paying customer. Active or suspended, and it may have no hotels.
- **Hotel**: belongs to one account. Suspended when it or its account is.
- **User**: reaches hotels as an owner, through a home hotel, or through memberships. Suspended when that leaves them refused.
- **Window**: the two dates a section covers.
- **Section figures**: one cached result for a section and window, with the time it was computed.

## Success Criteria *(mandatory)*

### Measurable Outcomes

- **SC-001**: A super admin can see the platform's customer counts, cost per account and activity per hotel from one dashboard, with no direct database work.
- **SC-002**: For every kind of user in User Story 2, the suspended count matches the real login result in 100% of cases.
- **SC-003**: Each account's usage and cost match the existing usage and AI cost reports exactly for the same window.
- **SC-004**: Accounts, hotels and users each add up: active plus suspended equals the total of records that are not deleted, and no super admin is included.
- **SC-005**: Repeat loads within 15 minutes do no new cross-hotel counting, and always show when the figures were computed.
- **SC-006**: 100% of requests from anyone but a super admin are refused, with no figures in the response.
- **SC-007**: No response contains revenue, and no hotel-facing route shows any of this data.

## Assumptions

- **Out of scope, per the request**: revenue in any form, health and failure signals (Sentry covers them), snapshot tables, websockets and SSE, impersonation (stage 5), removing the super admin bypasses (stage 6), rollback, data backfills and deploy coordination. The project is in development with test users.
- No new permission is needed. The dashboard stays behind the super admin guard, like the other cross-account reports.
- Frontend changes follow from a handoff after the backend is done, as for specs 023 to 025. The handoff must say that the date picker stops at today.
- All days are UTC. A hotel's own time zone does not affect the window, or the day a signup falls on.
- API messages are in English, as in specs 023 and 024.

### Known Limits

- **Signups are not only customers signing up**: nothing records where an account or user came from. So signups also include accounts a super admin created and staff added by a hotel. **[V8]**
- **Deleted accounts' costs**: these appear only as one `deleted_accounts` total, with no per-account row. `GET /api/superadmin/ai-cost` doesn't report them at all. **[V7, V15]**
- **User totals don't add up across accounts**: a user attached to an account twice counts once on the platform, and platform users include those with no account. So the total can differ from the sum of the account list's `users_count`, and from metered seats. **[V9]**
- **Two booking figures**: the usage section's `bookings_created` counts meter events per account, and activity's `bookings` counts live records per hotel. They can differ, for example after a booking is soft-deleted, which lowers only the second.
- **Speed is not measured**: the design expects fewer than 50 hotels, and no index is added for these reads. Reservations have no index for counting by creation date, so this is the first place to look if the section slows down. **[V12]**
- **No refresh control**: a super admin cannot force a recount, and waits up to 15 minutes.
