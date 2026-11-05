# Super Admin Platform Module (v1)

*   **Author:** Abdalrahman-Abdallah-Ahmed
*   **Status:** Draft
*   **Date:** 2026-11-04

--------------------------------------------------------------------------------

## Overview

The super admin (the internal team that runs the platform) gets its own area of
the API, `api/superadmin`, separate from what hotel admins use. It has four
parts:

1.  **Dashboard.** Platform-wide counts, usage and AI cost, and activity per
    hotel.
2.  **Administration.** Accounts (`HotelGroup`), their hotels and their users:
    create, edit, suspend, reactivate and reset access.
3.  **Impersonation.** A super admin can act as a hotel user for a limited time,
    and the log shows who really acted.
4.  **Separation.** Super admins stop using hotel endpoints directly.
    Impersonation becomes the only way into a hotel's operational screens.

All existing super-admin routes move from `api/admin` to `api/superadmin` in one
release (a hard cut). Every super admin write is recorded in `EventLog`.

## The Problem

Today the super admin is treated as a hotel admin with no limits. That causes
four problems.

1.  **The dashboard is wrong for them.** `GET /api/dashboard`
    (`DashboardController::generalData`) is the hotel dashboard. A super admin
    with no home hotel gets `403 "You do not belong to any hotel"`. One with a
    home hotel gets a confusing mix: cross-hotel task, arrival and VIP counts
    (the tenant scope is unrestricted and the cache key is `all`) next to
    single-hotel occupancy. Nothing shows platform-level figures such as the
    number of accounts, hotels and users, or how active each hotel is.
2.  **Onboarding needs the database.** There is no API for creating an account
    (`HotelGroup`). Hotel creation works only because super admins skip every
    policy (`HotelPolicy::create()` returns `false` for everyone else).
    Onboarding a new customer means seeders or SQL.
3.  **There is no way to stop a customer.** Nothing suspends an account or
    hotel. `is_active` exists, but hotel admins can change it themselves, and
    jobs already read it as "switched off for housekeeping or proactive
    messages", not as "suspended". `HotelGroup.is_active` is not read anywhere.
    Suspending a non-paying customer today means editing data by hand, and their
    WhatsApp concierge, AI jobs and staff logins keep running.
4.  **Super admin actions inside hotels can't be attributed.** Super admins read
    and write hotel data directly through about 25 policy bypasses
    (`before(): isSuperAdmin()`), and the logs show only that a super admin did
    it. There is no clear boundary between "platform operator" and "hotel user",
    and no way to see a hotel exactly as its staff see it.

**Proposed solution:** a separate super admin route file and set of controllers.
Account, hotel and user administration move there. Suspension gets its own
columns and stops everything for the suspended account or hotel. Super admins
reach hotel screens only through short-lived impersonation tokens, and the log
records both the hotel user and the real super admin.

## Goals

1.  **Separate super admin API.** `routes/superadmin.php` under
    `api/superadmin`, with the `super_admin` guard applied to the whole file in
    `bootstrap/app.php` (the same pattern as `routes/admin.php` today). Every
    existing `api/admin` route moves there and `routes/admin.php` is deleted.
2.  **Onboarding without SQL.** A super admin can create an account, its hotels,
    and its first admin user through the API, and edit them later.
3.  **User administration across the platform.** List, filter (by account,
    hotel and role), create and edit every user who is not a super admin:
    account owners, hotel admins and employees. Role changes cover `role`,
    `group_role`, hotel membership (only within the user's own account) and
    `staff_role_id`. Granting `super_admin` is never allowed.
4.  **Reset access.** Send a password reset email, and revoke all of a user's
    tokens. Revoking also unpairs their WhatsApp device.
5.  **Full-stop suspension** at account and hotel level, stored in new
    `suspended_at`, `suspended_by` and `suspended_reason` columns:
    *   login is blocked, and existing tokens are rejected with a
        machine-readable "suspended" response;
    *   all hotel jobs exit, including ones already queued;
    *   the AI concierge and Admin Advisor stop. Guests who write in get a
        fixed reply that is not AI-generated;
    *   a user with several hotels keeps access to the ones that are not
        suspended;
    *   data is kept, and reactivation restores everything.
6.  **Super admin dashboard** with:
    *   platform counts: accounts, hotels and users; active vs suspended;
        signups over time;
    *   a usage and AI cost summary;
    *   activity per hotel: reservation and booking counts.

    The window defaults to the last 30 days and can be chosen with `from`/`to`
    (at most 12 months).
7.  **Impersonation you can attribute.** A super admin can start an
    impersonation of any user who is not a super admin. Rules:
    *   the token has a short fixed expiry and an explicit end endpoint;
    *   `EventLog` records both the hotel user and the real super admin;
    *   the token cannot pair WhatsApp devices, change passwords or roles, or
        create tokens;
    *   in a suspended hotel, the token can only read.
8.  **Super admins leave hotel endpoints.** Hotel endpoints return 403 to a
    super admin who is not impersonating. This ships last, after goals 2–4
    and 7.
9.  **Audit.** Every super admin write, suspension, reactivation, reset, and
    impersonation start and end is recorded in `EventLog`. Rows for
    account-level actions (`hotel_id = null`) never appear on tenant event log
    endpoints.

**Success criteria:**

*   The frontend ships a separate super admin sidebar and dashboard that use
    only `api/superadmin`.
*   Onboarding a new account, its hotels and its admin needs no database access.
*   Failing hotels are spotted in Sentry. Suspension and impersonation add tags
    to Sentry events.

## Non-Goals

*   Plans and limits, feature flags per hotel or account, and announcements to
    hotel admins. These are later phases.
*   Health and failure signals on the dashboard (failed jobs, failed imports,
    WhatsApp health). Sentry already covers them.
*   Revenue on the dashboard, in any form, including converting between
    currencies.
*   A UI for browsing the audit log. Events are written to `EventLog` only.
*   Super admin tiers or permissions. There is one `super_admin` role, and super
    admins are still created by seeder or CLI, not through the API.
*   A read-only suspension mode. Suspension is a full stop.
*   Keeping `api/admin` as an alias. The move is a hard cut.
*   Snapshot tables, websockets or SSE for the dashboard.

## Open Questions

*   **Existing `api/admin` controllers.** Reviewers disagreed on how many of
    them rely on the unrestricted tenant scope (`TenantContext::hotelIds() ===
    null`). `KnowledgeRebuildController` is known to. The others need checking
    one by one before goal 8.
*   **Account owners (`group_role`) and suspension.** When one hotel in their
    account is suspended, do account owners still see that hotel in
    account-level views, or is it hidden for them too?
*   **The guest fixed reply.** What is the wording, and is it one platform-wide
    text or set per hotel? It is sent in the guest's language or in English
    only.

## Critical User Journeys (CUJs)

### CUJ 1: Onboard a new customer without SQL

*   **Given**: A super admin is signed in. No account exists for the customer.
*   **When**: They `POST /api/superadmin/accounts`, then
    `POST /api/superadmin/accounts/{account}/hotels` for each hotel, then
    `POST /api/superadmin/users` for the first admin, with `hotel_id` and
    `role=admin`.
*   **Then**: The account, hotels and admin exist. The admin can log in at
    `POST /api/login`. Each creation writes an `EventLog` row with the super
    admin as actor.

### CUJ 2: Suspend a non-paying account

*   **Given**: An account has two hotels. Staff are signed in, and guests are
    using WhatsApp.
*   **When**: A super admin calls
    `POST /api/superadmin/accounts/{account}/suspend` with a reason.
*   **Then**:
    *   The staff's next request returns `403` with
        `body.reason = "suspended"` and `scope = "account"`, and the frontend
        shows the suspended message.
    *   A new login returns the same response.
    *   A guest WhatsApp message gets the fixed reply, with no AI run.
    *   Scheduled and queued jobs for both hotels exit without doing any work.
    *   `POST /api/superadmin/accounts/{account}/reactivate` restores
        everything.

### CUJ 3: Suspend one hotel of a multi-hotel user

*   **Given**: A user belongs to hotels A and B in the same account.
*   **When**: Hotel A is suspended.
*   **Then**:
    *   The user can still log in, and their hotel list shows only B.
    *   A request with `X-Hotel-Id: A` returns the suspended `403` with
        `scope = "hotel"`.

### CUJ 4: Support a hotel through impersonation

*   **Given**: A hotel admin reports a problem.
*   **When**: A super admin calls
    `POST /api/superadmin/users/{user}/impersonate`.
*   **Then**:
    *   They receive a token for that user that expires after 30 minutes.
    *   Hotel endpoints behave exactly as they do for that user.
    *   Every write is logged with the super admin as actor and
        `context.on_behalf_of` set to the user.
    *   `POST /api/superadmin/impersonation/end` (or reaching the 30 minutes)
        ends the session, and a start and an end event are recorded.
    *   The token cannot connect WhatsApp, change a password or role, or
        create tokens.

### CUJ 5: Lock out a compromised user

*   **Given**: A user's password may be leaked.
*   **When**: A super admin calls
    `POST /api/superadmin/users/{user}/revoke-tokens`, then
    `POST /api/superadmin/users/{user}/password-reset`.
*   **Then**:
    *   All of the user's tokens are deleted, and their WhatsApp device is
        unpaired, so the Admin Advisor stops answering them.
    *   They receive a reset email.
    *   Both actions are logged.

### CUJ 6: See the platform at a glance

*   **Given**: A super admin opens the frontend.
*   **When**: The super admin dashboard calls
    `GET /api/superadmin/dashboard/counts`, `.../usage` and `.../activity`,
    optionally with `from` and `to`.
*   **Then**: They see:
    *   accounts, hotels and users, active vs suspended;
    *   signups per day;
    *   AI usage and cost per account;
    *   reservations and bookings per hotel for the last 30 days.

    Each section loads on its own. Figures come from a 15-minute cache and
    carry `cached_at`, so they are not live.

## Technical Plan (Architecture)

### Request paths after v1

```text
                      ┌───────────────────────────────── routes/api.php (hotel endpoints)
 client ── api.key ── auth:sanctum ── throttle:api ── tenant ── not_suspended ── no_super_admin ──▶ policy ──▶ controller
                      │                                   │            │
                      │                                   │            └─ 403 for a super admin token that is not an
                      │                                   │               impersonation token (stage 6)
                      │                                   └─ drops suspended hotels from TenantContext; 403 "suspended"
                      │                                      if the account, all hotels, or X-Hotel-Id is suspended
                      │                                      (an impersonation token may still GET/HEAD)
                      │
                      └───────────────────────────────── routes/superadmin.php (prefix api/superadmin)
 client ── api.key ── auth:sanctum ── throttle:api ── tenant ── super_admin ──▶ Superadmin\* controller ──▶ service
                                                        (null = unrestricted scope for super admins, unchanged)
```

### 1. Route move (stage 1)

*   `bootstrap/app.php` registers `routes/superadmin.php` in the `then:`
    block, with the same stack as today
    (`api, api.key, auth:sanctum, throttle:api, tenant, super_admin`) and
    prefix `api/superadmin`. The `api/admin` registration and
    `routes/admin.php` are deleted. This is a hard cut.
*   Controllers move from `App\Http\Controllers\Admin` to
    `App\Http\Controllers\Superadmin`, so the namespace matches the URL.
    Their behaviour does not change in this stage.
*   Route classification in tests already uses middleware rather than the URL
    (`tests/Support/EntryPointDiscovery.php`), so `CrossTenantRouteTest` and
    `EntryPointInventoryTest` keep working. Tests that hard-code `/api/admin`
    URLs are updated.

### 2. Account, hotel and user administration (stage 2)

*   **Accounts.** New `Superadmin\AccountController` provides CRUD for
    `HotelGroup`, plus suspend and reactivate. It reuses `HotelGroupResource`.
    The account list includes counts of hotels and users and the suspension
    state.
*   **Hotels.** New `Superadmin\HotelController` lists hotels across accounts
    (filterable by account) and creates hotels under an account. Edits made
    by a super admin also go through it. The existing tenant
    `HotelController` keeps the hotel admin's own edits. Hotel creation moves
    out of the tenant `HotelController`, where it works today only through
    the super admin bypass.
*   **Users.** New `Superadmin\UserController` lists every user who is not a
    super admin, filtered by account, hotel and role. It can create and edit
    them, and change `role`, `group_role`, hotel membership (the `hotel_user`
    pivot, limited to hotels in the user's account) and `staff_role_id`.
    *   Shared rules move into a service (`App\Services\Superadmin\UserAdministration`)
        so they are not copied from the tenant `UserController`. The
        `denySuperAdminGrant` guard is one of them.
*   **Reset access.** Lives in the same service:
    *   `sendPasswordReset(User)` uses Laravel's password broker
        (`password_reset_tokens` already exists) and a reset notification
        whose link points to the frontend's reset page.
    *   `revokeAccess(User)` deletes all of the user's Sanctum tokens and
        unpairs their `WhatsAppDevice` in one transaction.
    *   A public `POST /api/reset-password` endpoint accepts the token and
        the new password.
*   **Querying across hotels.** Super admin controllers read across hotels.
    They use `TenantContext::withoutScope()` explicitly instead of relying on
    the super admin's `null` scope, so stage 6 cannot change their results.

### 3. Suspension (stage 3)

*   **Storage.** A migration adds `suspended_at` (nullable timestamp),
    `suspended_by` (nullable user uuid) and `suspended_reason` (nullable
    text) to `hotel_groups` and `hotels`.
    *   The columns are not in `$fillable`, so tenant
        `GenericUpdateRequest` rules can never write them.
    *   Only `App\Services\Superadmin\Suspension` writes them.
*   **One rule.** `Hotel::isSuspended()` is true when the hotel or its account
    is suspended. `Hotel::scopeNotSuspended()` is the query form, used by the
    jobs that loop over hotels. `HotelGroup::isSuspended()` covers the
    account.
*   **Login.** `AuthenticatedSessionController::apiLogin` checks credentials
    first, then refuses with the suspended response when:
    *   the user's account is suspended, or
    *   every hotel the user can reach is suspended.

    It issues no token in that case. Checking credentials first means the
    suspended message is only revealed to someone who knows the password.
*   **Loading the state.** `ResolveTenant` loads suspension state in the
    hotel query it already runs. It is not loaded separately.
    *   A new `User::accessibleHotels()` returns rows of `id`,
        `suspended_at` and the group's `suspended_at` from one joined query.
        `accessibleHotelIds()` becomes a `pluck` over it, and its behaviour
        does not change: suspended hotels are still included.
    *   `ResolveTenant` stores the ids as today, and also stores
        `TenantContext::setSuspension(accountSuspended: bool,
        suspendedHotelIds: array)`.
    *   For super admins (`null` scope) nothing is loaded, because they are
        never suspended.
*   **HTTP.** A new `not_suspended` middleware (`EnsureNotSuspended`) runs
    after `tenant` on the tenant group in `routes/api.php`. It reads only
    `TenantContext` and runs no queries of its own. If `tenant` did not run
    before it, it refuses the request instead of letting it through. It:
    1.  removes suspended hotels from `TenantContext::hotelIds()` and from the
        current hotel;
    2.  returns the suspended `403` when the account is suspended, no hotels
        remain, or `X-Hotel-Id` names a suspended hotel;
    3.  lets an impersonation token through on `GET` and `HEAD` only.

    The suspended response is
    `apiResponse('Your account has been suspended.', 403, ['reason' => 'suspended', 'scope' => 'account'|'hotel'])`.

    A route test fails if any `auth:sanctum` route outside the super admin
    stack, the login route and the logout route is missing `not_suspended`.
*   **Jobs.** A new job middleware `SkipIfHotelSuspended` reads the job's
    hotel id through a small interface (`HasHotel::hotelId()`). When the hotel
    is suspended it deletes the job without running it and logs one line.
    *   Every hotel job declares the middleware in `middleware()`.
    *   A test in the style of `QueueLaneTest` fails if a job that implements
        `HasHotel` (or runs `runForHotel`) lacks it.
    *   Jobs that loop over hotels (insights, proactive triggers, housekeeping
        day, expiry, outcomes) select hotels with `notSuspended()`.
*   **WhatsApp.** `ProcessInboundWhatsAppMessageJob` does not use the
    middleware. Once it has resolved the hotel:
    *   if the hotel is suspended, it stores the inbound message, sends the
        fixed reply through `SendWhatsAppMessageJob` (which skips the
        middleware for this one message type), and stops before any AI run;
    *   a staff sender (Admin Advisor) of a suspended hotel gets the same
        fixed reply.
*   **Sentry.** `not_suspended` and the job middleware tag the Sentry scope
    with `suspended=true` when they block.

### 4. Dashboard (stage 4)

*   Three endpoints, so one slow section does not hold up the others:
    *   `GET /api/superadmin/dashboard/counts`: accounts, hotels and users
        (excluding super admins), split active vs suspended, plus signups per
        day. Signups are accounts created and users created, grouped by day.
    *   `GET /api/superadmin/dashboard/usage`: totals from the existing
        `UsageReport::totals()` and `aiTotals()`, grouped by account.
    *   `GET /api/superadmin/dashboard/activity`: reservations and bookings
        created per hotel, counted with one grouped query each under
        `withoutScope()`.
*   `from` and `to` default to the last 30 days in UTC, and the range can be
    at most 12 months. Validation is done in a `SuperadminDashboardRequest`.
*   **Cached for 15 minutes** (author decision, which replaces "live"):
    *   each section is wrapped in `Cache::remember` with a 900-second
        lifetime;
    *   the key is `superadmin:dashboard:v1:{section}:{from}:{to}`;
    *   the cached value is plain arrays only, following the same rule as
        `DashboardController`, so nothing comes back from the cache as an
        incomplete class;
    *   there are no snapshot tables.

    A super admin's own changes (a new account, a suspension) can take up to
    15 minutes to appear. The responses include `cached_at`, so the frontend
    can show how old the figures are.
*   A service, `App\Services\Reports\PlatformSummary`, owns the queries, so
    the controller stays thin.

### 5. Impersonation (stage 5)

*   **Schema.** A migration adds `impersonator_id` (nullable uuid, references
    `users`) to `personal_access_tokens`. `expires_at` already exists.
*   **Start.** `POST /api/superadmin/users/{user}/impersonate`:
    *   refuses if the target is a super admin or has been soft-deleted;
    *   creates a token for the target user named `impersonation`, with
        `impersonator_id` set to the super admin and `expires_at` set to now
        plus 30 minutes;
    *   records an `impersonation.started` event;
    *   returns the token and its expiry.

    Sessions cannot be extended; a new one has to be started.
*   **End.** `POST /api/superadmin/impersonation/end` takes the impersonation
    token id, deletes the token and records `impersonation.ended`. Expiry is
    enforced by Sanctum's `expires_at`. A scheduled prune removes expired
    impersonation tokens.
*   **Who am I.** `GET /api/user` includes an `impersonation` object
    (`impersonator`, `expires_at`) when the current token has an
    `impersonator_id`, so the frontend can show a banner.
*   **Attribution.** `EventLogger::record()` checks the current token. When it
    has an `impersonator_id`, the actor becomes the super admin and
    `context.on_behalf_of` is set to the impersonated user's id. Every model
    write that is already audited gets this without changing its caller.
*   **Restrictions.** A new `not_impersonating` middleware returns 403 on:
    *   `POST /api/connect` (WhatsApp pairing);
    *   the user's own password change;
    *   role and staff-role changes;
    *   any endpoint that creates tokens.

    In a suspended hotel, `not_suspended` limits the token to `GET` and
    `HEAD`.

### 6. Super admins leave hotel endpoints (stage 6)

*   A new `no_super_admin` middleware on the tenant group in `routes/api.php`
    returns 403 to a super admin's own token. An impersonation token belongs
    to the hotel user, so it passes.
*   The super admin bypasses are removed:
    *   the policy `before()` bypasses (about 25);
    *   the `isSuperAdmin()` branches in `resolveHotel()`,
        `User::permissions()`, tenant controllers, AI tools (`GuardedTool`,
        `GetStaffTool`, `GetReportTool`) and Resources (about 20).

    Each is listed first in an inventory, with a decision recorded per file.
*   `User::accessibleHotelIds()` keeps returning `null` for super admins.
    The super admin stack still relies on it, and the `null` vs `[]` rule in
    CLAUDE.md stays intact.
*   About 33 test files that use a super admin as a hotel user are rewritten
    to use an admin, or an impersonation token where the super admin's view
    is the point of the test.
*   A generic test walks every route in `routes/api.php` behind
    `auth:sanctum` as a super admin and expects `403`.

### Audit (all stages)

*   Super admin writes are recorded through `EventLogger::record()`:
    *   account events have `hotel_id = null`;
    *   their `event_type` names start with `platform.` (for example
        `platform.account.suspended`);
    *   they are written under `withoutScope()`.
*   `HotelGroup` and `User` get `RecordsEvents` for create, update and delete.
*   A test proves that rows with `hotel_id = null` never appear on tenant
    event log endpoints.

## Alternatives Considered

### Alternative A: Keep super admin routes in `routes/admin.php` under `api/admin`

*   **Pros**: No breaking change, and no coupled frontend deploy.
*   **Cons**: The prefix `admin` reads as "hotel admin", not "platform
    operator", and new platform endpoints would sit next to the old ones
    under a misleading name.
*   **Reason for Rejection**: Rejected by author. The super admin area gets
    its own file and prefix (`api/superadmin`), and every existing route moves
    there.

### Alternative B: Move routes with a one-release `api/admin` alias

*   **Pros**: The frontend and backend can deploy separately, and rollback is
    easier.
*   **Cons**: Two prefixes are live for a release, and the alias has to be
    removed later.
*   **Reason for Rejection**: Rejected by author. Both apps are released
    together, so a hard cut documented in `docs/latest-changes-<date>.md` is
    enough.

### Alternative C: Reuse `is_active` for suspension

*   **Pros**: No migration, because the column already exists on `hotels` and
    `hotel_groups`.
*   **Cons**: Hotel admins can write `hotels.is_active` (it is in `$fillable`
    and `GenericUpdateRequest` derives its rules from fillable columns), so a
    suspended hotel could reactivate itself. Jobs already read it as "switched
    off for housekeeping or proactive messages", which is a different meaning.
    It also has no "who, when, why" record.
*   **Reason for Rejection**: Rejected by author. Use new `suspended_at`,
    `suspended_by` and `suspended_reason` columns that only the super admin
    service writes.

### Alternative D: Read-only suspension

*   **Pros**: Staff could still see their data and finish guests' stays.
*   **Cons**: Every write path needs a check: HTTP, about 20 jobs, the
    WhatsApp webhook, and AI write tools. It also needs a list of exemptions
    (logout, check-out, cancel) that would keep growing.
*   **Reason for Rejection**: Rejected by author. Suspension is a full stop,
    enforced at login, in one HTTP middleware and in one job middleware.

### Alternative E: Stop jobs inside `TenantContext::runForHotel()`

*   **Pros**: One place covers jobs, AI tools and console commands.
*   **Cons**: Failure and cleanup paths that also run inside `runForHotel`
    (an import marked failed, a voucher marked finished) would be blocked,
    and the WhatsApp fixed reply would need a bypass.
*   **Reason for Rejection**: Rejected by author. A job middleware is explicit
    per job, and an inventory test enforces it.

### Alternative F: Enforce suspension in `User::accessibleHotelIds()` or `ResolveTenant`

*   **Pros**: No new middleware alias, and every tenant route already passes
    through `ResolveTenant`.
*   **Cons**: `accessibleHotelIds()` would quietly hide suspended hotels from
    every future caller, for example an account owner's overview.
    `ResolveTenant` also runs on the super admin stack, so it would need
    exceptions for super admins and for impersonation.
*   **Reason for Rejection**: Rejected by author. A separate `not_suspended`
    middleware keeps suspension in one class, and a route test checks that it
    is present.

### Alternative G: Edge block only, or a tenant scope change, for removing super admin hotel access

*   **Pros**: An edge block alone is a small change. A scope change (`[]` for
    super admins, plus a `platform` resolver) puts the restriction at the data
    layer.
*   **Cons**: An edge block alone leaves about 45 dead bypass branches that
    someone may later rely on. A scope change risks queries that return zero
    rows without any error on any super admin path still using `tenant`, and
    it touches the `null` vs `[]` rule that CLAUDE.md warns about.
*   **Reason for Rejection**: Rejected by author. Use the edge block and also
    remove the bypasses, with an inventory first, so the end state has no
    dead code. `accessibleHotelIds()` keeps returning `null`.

### Alternative H: Impersonation through an `X-Act-As` header on the super admin's own token

*   **Pros**: No new token, and no schema change.
*   **Cons**: Every `Auth::user()` read, every policy, `ResolveTenant` and
    `User::permissions()` would have to understand the header.
*   **Reason for Rejection**: Rejected during brainstorming. A token issued to
    the target user makes existing policies and the tenant scope work
    unchanged.

### Alternative I: Record impersonated writes as the hotel user, or as a new `ActorKind`

*   **Pros**: With the hotel user as actor, hotel event logs read naturally. A
    new `ActorKind` case could be filtered on its own.
*   **Cons**: With the hotel user as actor, the log looks as if that user did
    it unless the reader checks `context`. A new `ActorKind` means every
    `ActorKind` switch (exports, reports) needs a new case.
*   **Reason for Rejection**: Rejected by author. The super admin is the
    actor, with `context.on_behalf_of` set to the user.

### Alternative J: Longer or extendable impersonation sessions

*   **Pros**: Fewer interruptions during long support sessions.
*   **Cons**: Elevated access lasts longer, and extending needs its own
    endpoint and audit trail.
*   **Reason for Rejection**: Rejected by author. Sessions last 30 minutes and
    cannot be extended; a new one has to be started.

### Alternative K: Live dashboard, or nightly snapshot tables

*   **Pros**: Live gives figures that are always current. Snapshots give the
    lowest query cost.
*   **Cons**: Live re-runs grouped queries across every hotel on each load,
    which puts load on the database on a 2 GB host. Snapshots need new
    tables, a scheduled job and a backfill.
*   **Reason for Rejection**: Rejected by author. Each section is cached for
    15 minutes instead.

### Alternative L: Health, failures and revenue on the dashboard

*   **Pros**: The super admin sees everything in one place.
*   **Cons**: There is no WhatsApp connection per hotel (one platform-wide
    number). `failed_jobs` has no `hotel_id`. Revenue is in mixed
    currencies.
*   **Reason for Rejection**: Rejected by author. Failures and WhatsApp health
    are handled in Sentry, and revenue is deferred.

### Alternative M: Temporary password, or a separate WhatsApp unpair action

*   **Pros**: A temporary password works without mail. A separate unpair
    action gives finer control.
*   **Cons**: With a temporary password, the super admin knows the user's
    credential. A separate unpair action leaves a gap: after tokens are
    revoked, the paired device would keep Admin Advisor access.
*   **Reason for Rejection**: Rejected by author. Reset access means a
    password reset email plus revoking all tokens, and revoking also unpairs
    the device.

--------------------------------------------------------------------------------

## Non-Functional Requirements

*   **Security.**
    *   Every `api/superadmin` route gets the `super_admin` guard from the
        file-level registration.
    *   Impersonation tokens last 30 minutes, are attributed to the real
        super admin, and cannot pair WhatsApp devices, change passwords or
        roles, or create tokens.
    *   `suspended_*` columns cannot be written by tenant requests.
    *   The suspended message is only returned after the password has been
        checked.
    *   The reset-password endpoint is throttled the same way as
        `throttle:login`.
*   **Performance.**
    *   Dashboard sections are cached for 15 minutes.
    *   `not_suspended` adds no queries. `ResolveTenant` already queries the
        user's hotels on every request. That query now also selects
        `hotels.suspended_at` and the account's `suspended_at` (a join to
        `hotel_groups`) and stores the suspended ids in `TenantContext`.
        `not_suspended` only reads `TenantContext`. Nothing is cached, so
        there is nothing to clear and no way for the value to go stale.
    *   The job middleware adds one indexed lookup per job (`Hotel::isSuspended()`),
        with no cache.
*   **Rollout.**
    *   Stages ship in order, as separate PRs: 1 → 2 → 3 → 4 → 5 → 6.
    *   Stage 1 is a breaking move and must deploy together with the
        frontend.
    *   Stage 6 must not ship before stages 2 and 5.
    *   Migrations only add nullable columns, so rolling back means reverting
        the code. The columns can stay.

## Detailed Implementation Plan

Migration filenames follow the repo sequence `YYYY_MM_DD_NNNNNN_description.php`.
Dates below are placeholders for the day each stage is written.

### Stage 1: Route move (`api/admin` → `api/superadmin`)

#### 1.1 `routes/superadmin.php` (Create)

*   **Type / Scope**: Routes.
*   **Proposed Changes**: Every route from `routes/admin.php`, unchanged
    apart from controller namespaces. The header docblock is adapted from
    `routes/admin.php`: the file-level guard, and no tenant-facing endpoints.

#### 1.2 `routes/admin.php` (Delete)

*   **Proposed Changes**: Removed. All its routes now live in
    `routes/superadmin.php`.

#### 1.3 `bootstrap/app.php` (Modify)

*   **Proposed Changes**: The `then:` block registers
    `routes/superadmin.php` with prefix `api/superadmin` and the same
    middleware stack. The comment is updated. The `api/admin` registration is
    removed.

#### 1.4 `app/Http/Controllers/Admin/*` → `app/Http/Controllers/Superadmin/*` (Move)

*   **Files**: `AiCostController.php`, `GuestMemoryExtractionController.php`,
    `KnowledgeBaseArticleController.php`, `KnowledgeDocumentController.php`,
    `KnowledgeRebuildController.php`, `UsageController.php`.
*   **Proposed Changes**: The namespace changes to
    `App\Http\Controllers\Superadmin`. The code does not change.

#### 1.5 Code comments that mention `api/admin` (Modify)

*   **Files**: `app/Http/Controllers/AccountUsageController.php`,
    `app/Http/Controllers/KnowledgeBaseArticleController.php`,
    `app/Services/Knowledge/ArticleCommands.php`,
    `database/seeders/AiModelPriceSeeder.php`.
*   **Proposed Changes**: Comment and string references to `api/admin`
    become `api/superadmin`.

#### 1.6 Tests that call `/api/admin` (Modify)

*   **Files**: `tests/Feature/Admin/GlobalKnowledgeTest.php`,
    `tests/Feature/Admin/GuestMemoryExtractionAdminTest.php`,
    `tests/Feature/Admin/KnowledgeRebuildTest.php`,
    `tests/Feature/AccountUsageAiBlockTest.php`,
    `tests/Feature/AiCostAttributionTest.php`,
    `tests/Feature/AiCostCapabilityTest.php`,
    `tests/Feature/CostVisibilityTest.php`,
    `tests/Feature/KnowledgeBaseArticleControllerTest.php`,
    `tests/Feature/KnowledgeDocumentVisionTest.php`,
    `tests/Feature/UsageMeteringTest.php`.
*   **Proposed Changes**: URLs become `/api/superadmin/...`. The folder
    `tests/Feature/Admin/` is renamed to `tests/Feature/Superadmin/`. A new
    test asserts that `/api/admin/usage` returns 404.

#### 1.7 `tests/Inventory/entry_points.php` (Modify)

*   **Proposed Changes**: Entries that name `Admin\` controllers are updated
    to `Superadmin\`.

#### 1.8 Documentation (Modify / Create)

*   **Modify**: `docs/usage-metering-api-documentation.md`,
    `docs/ai-cost-attribution-api-documentation.md`,
    `docs/global-knowledge-admin-api-documentation.md`,
    `docs/guest-memories-api-documentation.md`,
    `docs/knowledge-base-article-api-documentation.md`,
    `docs/knowledge-document-api-documentation.md`,
    `docs/auth-api-documentation.md`,
    `docs/staff-roles-api-documentation.md`. Each URL prefix changes.
*   **Modify**: `CLAUDE.md`. "Request flow" and "Layers" now point to
    `routes/superadmin.php` and `api/superadmin`.
*   **Create**: `docs/latest-changes-<date>.md`. A breaking-change note that
    maps every old URL to its new one and says the frontend and backend must
    deploy together.
*   Historical specs (`specs/008`, `011`, `012`) and dated `latest-changes`
    files are left unchanged, because they are history.

### Stage 2: Account, hotel and user administration + reset access

#### 2.1 `app/Http/Controllers/Superadmin/AccountController.php` (Create)

*   **Proposed Changes**: `index` (filter by name or suspended, with counts
    of hotels and users), `store`, `show`, `update` and `destroy` (soft
    delete, refused while the account has active hotels). It uses
    `GenericIndexRequest` and `GenericQuery::apply()`. Every response goes
    through `HotelGroupResource`.

#### 2.2 `app/Http/Controllers/Superadmin/HotelController.php` (Create)

*   **Proposed Changes**: `index` (all hotels, filterable by
    `hotel_group_id` and suspended), `store` (requires `hotel_group_id`),
    `show` and `update`. It runs under `TenantContext::withoutScope()` and
    responds with `HotelResource`.

#### 2.3 `app/Http/Controllers/Superadmin/UserController.php` (Create)

*   **Proposed Changes**:
    *   `index`: every user who is not a super admin, filterable by
        `hotel_group_id`, `hotel_id` and `role`.
    *   `store`, `show` and `update`.
    *   `updateAccess`: changes `role`, `group_role`, hotel memberships and
        `staff_role_id`.
    *   `sendPasswordReset` and `revokeTokens`.

    Every action delegates to `UserAdministration`.

#### 2.4 `app/Services/Superadmin/UserAdministration.php` (Create)

*   **Proposed Changes**:
    *   `create(array)`, `update(User, array)` and `changeAccess(User, array)`.
        `changeAccess` refuses `super_admin`, and refuses memberships of
        hotels outside the user's account.
    *   `sendPasswordReset(User)`: `Password::broker()->sendResetLink`.
    *   `revokeAccess(User)`: in one transaction, deletes all tokens and
        deletes the `WhatsAppDevice`.

    Each method records a `platform.user.*` event. The `denySuperAdminGrant`
    logic moves here from `UserController`, and the tenant `UserController`
    calls it from here.

#### 2.5 `app/Http/Requests/Superadmin/*` (Create)

*   **Files**: `StoreAccountRequest.php`, `UpdateAccountRequest.php`,
    `StoreHotelRequest.php`, `StoreUserRequest.php`,
    `UpdateUserAccessRequest.php`.
*   **Proposed Changes**: Validation rules. `UpdateUserAccessRequest`
    rejects `role=super_admin` and checks that each hotel belongs to the
    user's account.

#### 2.6 `app/Http/Controllers/Auth/PasswordResetController.php` (Create)

*   **Proposed Changes**: `POST /api/reset-password` takes `token`, `email`
    and `password` (with confirmation). It calls
    `Password::broker()->reset`, deletes all of the user's tokens on success,
    and is throttled with `throttle:login`.

#### 2.7 `app/Notifications/ResetPasswordNotification.php` (Create)

*   **Proposed Changes**: A mail notification. Its link is
    `config('app.frontend_url').'/reset-password?token=…&email=…'`.

#### 2.8 `app/Models/User.php` (Modify)

*   **Proposed Changes**: `sendPasswordResetNotification()` uses the new
    notification. Adds `RecordsEvents`.

#### 2.9 `app/Models/HotelGroup.php` (Modify)

*   **Proposed Changes**: Adds `RecordsEvents` and a `hotels()` count
    helper, if missing.

#### 2.10 `app/Policies/HotelPolicy.php` and `app/Http/Controllers/HotelController.php` (Modify)

*   **Proposed Changes**: Hotel creation is removed from the tenant
    `HotelController` (`store`). The route uses
    `->except(['store', 'edit', 'create'])`. `HotelPolicy::create()` is
    deleted.

#### 2.11 `routes/superadmin.php` and `routes/api.php` (Modify)

*   **Proposed Changes**: Superadmin routes for accounts, hotels and users,
    including `/users/{user}/access`, `/users/{user}/password-reset` and
    `/users/{user}/revoke-tokens`. `routes/api.php` gets the public
    `POST /reset-password`.

#### 2.12 `config/app.php` (Modify)

*   **Proposed Changes**: Adds `frontend_url`, read from env.

#### 2.13 Tests (Create)

*   **Files**: `tests/Feature/Superadmin/AccountAdministrationTest.php`,
    `tests/Feature/Superadmin/HotelAdministrationTest.php`,
    `tests/Feature/Superadmin/UserAdministrationTest.php`,
    `tests/Feature/PasswordResetTest.php`.
*   **Proposed Changes**: These cover:
    *   CUJ 1 and CUJ 5;
    *   refusing a `super_admin` grant;
    *   refusing a membership in a hotel from another account;
    *   revoking tokens also deletes the device;
    *   a non-super-admin gets 403;
    *   audit rows have `hotel_id = null`.

#### 2.14 `tests/Feature/HotelControllerTest.php` and `tests/Feature/CreateUserTest.php` (Modify)

*   **Proposed Changes**: Hotel creation assertions move to the superadmin
    test. The tenant `POST /api/hotel` now expects 405 or 404.

#### 2.15 Documentation (Create / Modify)

*   **Create**: `docs/superadmin-accounts-api-documentation.md`, covering
    accounts, hotels, users and reset access.
*   **Modify**: `docs/hotel-api-documentation.md` (hotel creation removed),
    `docs/user-management-api-documentation.md`,
    `docs/auth-api-documentation.md` (reset password) and
    `docs/latest-changes-<date>.md`.

### Stage 3: Suspension

#### 3.1 `database/migrations/<date>_000001_add_suspension_to_hotel_groups_and_hotels.php` (Create)

*   **Proposed Changes**: Adds `suspended_at` (timestampTz, nullable,
    indexed), `suspended_by` (uuid, nullable, foreign key to `users`, null on
    delete) and `suspended_reason` (text, nullable) to `hotel_groups` and
    `hotels`.

#### 3.2 `app/Models/Hotel.php` and `app/Models/HotelGroup.php` (Modify)

*   **Proposed Changes**: Adds `suspended_at` to `$casts`, but not to
    `$fillable`. Adds `isSuspended()` (the hotel's own state, or its
    group's) and `scopeNotSuspended()` (a `whereNull` on both, joined
    through the group).

#### 3.3 `app/Services/Superadmin/Suspension.php` (Create)

*   **Proposed Changes**: `suspendAccount`, `reactivateAccount`,
    `suspendHotel` and `reactivateHotel`. Each one:
    *   sets or clears the columns;
    *   records `platform.account.suspended` (or the matching event).

    There is no cache to clear. The next request reads the new state through
    `ResolveTenant`.

#### 3.4 `app/Http/Middleware/EnsureNotSuspended.php` (Create, alias `not_suspended`)

*   **Proposed Changes**: As in Technical Plan §3.
    *   It reads the suspension state from `TenantContext` and runs no
        queries.
    *   It refuses the request if that state was never set, which means
        `tenant` did not run.
    *   It removes suspended hotel ids and returns the suspended response.
    *   Impersonation tokens may still use `GET` and `HEAD`.
    *   It tags Sentry.

#### 3.4a `app/Models/User.php` (Modify)

*   **Proposed Changes**: Adds `accessibleHotels(): ?Collection`. It runs
    one query that selects `hotels.id`, `hotels.suspended_at` and
    `hotel_groups.suspended_at` (a left join), covering the same three
    sources as today: group role, pivot and the legacy `hotel_id`. It
    returns `null` for super admins. `accessibleHotelIds()` is re-implemented
    as `accessibleHotels()?->pluck('id')->all()`, with the same results.

#### 3.4b `app/Http/Middleware/ResolveTenant.php` (Modify)

*   **Proposed Changes**: Calls `accessibleHotels()` once. It sets
    `TenantContext::setHotelIds()` from the ids as today, and also calls
    `TenantContext::setSuspension($accountSuspended, $suspendedHotelIds)`.
    It makes no decision; `not_suspended` decides.

#### 3.4c `app/Support/Tenancy/TenantContext.php` (Modify)

*   **Proposed Changes**: Adds `setSuspension()`, `isAccountSuspended()`,
    `suspendedHotelIds()` and `suspensionResolved(): bool`. `reset()` clears
    them, and `runForHotel()` and `withoutScope()` save and restore them,
    like the existing fields.

#### 3.5 `app/Support/Suspension/SuspendedResponse.php` (Create)

*   **Proposed Changes**: One helper that builds the `apiResponse` body
    (`reason` and `scope`). Login and the middleware both use it, so the
    shape is identical everywhere.

#### 3.6 `bootstrap/app.php` and `routes/api.php` (Modify)

*   **Proposed Changes**: Registers the `not_suspended` alias. Adds it to the
    `auth:sanctum` tenant group after `tenant`, everywhere except logout.

#### 3.7 `app/Http/Controllers/Auth/AuthenticatedSessionController.php` (Modify)

*   **Proposed Changes**: After `Auth::attempt` succeeds and before
    `createToken`, `apiLogin` returns `SuspendedResponse` when the account is
    suspended or every hotel the user can reach is suspended.

#### 3.8 `app/Jobs/Middleware/SkipIfHotelSuspended.php` and `app/Contracts/HasHotel.php` (Create)

*   **Proposed Changes**: The job middleware calls `$job->hotelId()`. If
    that hotel is suspended, it deletes the job (`$job->delete()`), logs
    `job.skipped_suspended` and does not call `$next`.

#### 3.9 Hotel jobs (Modify)

*   **Files**: `CreateAiInsightsJob`, `ExtractGuestMemoriesJob`,
    `GenerateActivityRecommendationsJob`, `IndexKnowledgeDocumentJob`
    (hotel documents only), `PrepareReservationImportJob`,
    `WriteReservationImportJob`, `SendGuestRequestNoticeJob`,
    `SendProactiveMessageJob`, `SendReservationVoucherEmailJob`,
    `SyncKnowledgeChunksJob` (hotel rows only), `SendWhatsAppMessageJob`,
    and the jobs in `app/Jobs/AiCost/` and `app/Jobs/Metering/` that carry a
    hotel.
*   **Proposed Changes**: Each implements `HasHotel` and returns
    `SkipIfHotelSuspended` from `middleware()`. `SendWhatsAppMessageJob`
    skips the check for the suspension fixed reply.

#### 3.10 Jobs that loop over hotels (Modify)

*   **Files**: `EvaluateProactiveTriggersJob`, `ExpireRecommendationsJob`,
    `MatchRecommendationOutcomesJob`, `StartHousekeepingDayJob`, and any
    `app/Jobs/AiCost/*` job that loops over accounts or hotels.
*   **Proposed Changes**: Hotels are selected with `->notSuspended()`.

#### 3.11 `app/Jobs/ProcessInboundWhatsAppMessageJob.php` (Modify)

*   **Proposed Changes**: After the hotel is resolved, if it is suspended:
    *   the inbound message is stored;
    *   the fixed reply goes out through `SendWhatsAppMessageJob`;
    *   the job stops before any agent turn.

    This applies to guest and staff senders alike.

#### 3.12 `lang/en/suspension.php` (Create)

*   **Proposed Changes**: Holds the fixed WhatsApp reply text and the
    suspended API message, so they can be translated later.

#### 3.13 Tests (Create / Modify)

*   **Create** `tests/Feature/SuspensionTest.php`: CUJ 2 and CUJ 3. Covers
    login refused, the existing token refused, the `X-Hotel-Id` of a
    suspended hotel, the multi-hotel user, reactivation, and tenant
    `PUT /api/hotel/{id}` being unable to write `suspended_at`.
*   **Create** `tests/Feature/SuspensionJobTest.php`: each hotel job is
    skipped when its hotel is suspended, and the WhatsApp fixed reply is
    sent without an AI run.
*   **Create** `tests/Feature/SuspensionRouteInventoryTest.php`: every
    `auth:sanctum` route outside the superadmin stack, except logout, has
    `not_suspended`.
*   **Modify** `tests/Feature/QueueLaneTest.php` (or create
    `tests/Feature/JobSuspensionInventoryTest.php`): fails when a job that
    implements `HasHotel` or calls `runForHotel` lacks
    `SkipIfHotelSuspended`, unless it is listed in an allowlist with a
    reason (`ProcessInboundWhatsAppMessageJob`).
*   **Modify** `tests/Inventory/entry_points.php` and
    `tests/Feature/EntryPointInventoryTest.php`: adds a "suspension-gated"
    column.

#### 3.14 Documentation

*   **Modify**: `docs/superadmin-accounts-api-documentation.md` (suspend
    and reactivate endpoints), `docs/auth-api-documentation.md` (suspended
    login response) and `docs/latest-changes-<date>.md` (the new 403 shape
    every client must handle).

### Stage 4: Dashboard

#### 4.1 `app/Services/Reports/PlatformSummary.php` (Create)

*   **Proposed Changes**: `counts(from, to)`, `usage(from, to)` (wrapping
    `UsageReport`) and `activity(from, to)`. Each runs under
    `TenantContext::withoutScope()` and returns plain arrays.

#### 4.2 `app/Http/Controllers/Superadmin/DashboardController.php` (Create)

*   **Proposed Changes**: `counts`, `usage` and `activity`. Each wraps
    `PlatformSummary` in `Cache::remember(…, 900)` under the key
    `superadmin:dashboard:v1:{section}:{from}:{to}` and adds `cached_at`.

#### 4.3 `app/Http/Requests/Superadmin/DashboardRequest.php` (Create)

*   **Proposed Changes**: `from` and `to` are optional dates. They default
    to the last 30 days, `to` must be on or after `from`, and the range is at
    most 366 days.

#### 4.4 `routes/superadmin.php` (Modify)

*   **Proposed Changes**: Adds `GET /dashboard/counts`, `/dashboard/usage`
    and `/dashboard/activity`.

#### 4.5 `tests/Feature/Superadmin/DashboardTest.php` (Create)

*   **Proposed Changes**: Covers the counts (super admins excluded,
    suspended split), activity per hotel across two accounts, the default
    window, a range over 12 months rejected, a cache hit on the second call,
    and 403 for an admin.

#### 4.6 `docs/superadmin-dashboard-api-documentation.md` (Create)

*   **Proposed Changes**: Documents the three endpoints, their query
    parameters, response shapes and the 15-minute cache.

### Stage 5: Impersonation

#### 5.1 `database/migrations/<date>_000001_add_impersonator_to_personal_access_tokens.php` (Create)

*   **Proposed Changes**: Adds `impersonator_id` (uuid, nullable, foreign
    key to `users`, cascade on delete) to `personal_access_tokens`.

#### 5.2 `app/Services/Superadmin/Impersonation.php` (Create)

*   **Proposed Changes**:
    *   `start(User $superAdmin, User $target)` refuses a super admin or a
        soft-deleted target, creates a token named `impersonation` with
        `expires_at` set to now plus 30 minutes, and records
        `platform.impersonation.started`.
    *   `end(PersonalAccessToken)` deletes the token and records
        `platform.impersonation.ended`.
    *   `current(): ?PersonalAccessToken` returns the current token if it is
        an impersonation token.

#### 5.3 `app/Http/Controllers/Superadmin/ImpersonationController.php` (Create)

*   **Proposed Changes**: `POST /users/{user}/impersonate` and
    `POST /impersonation/end`.

#### 5.4 `app/Support/Audit/EventLogger.php` (Modify)

*   **Proposed Changes**: In `record()`, when the current token has an
    `impersonator_id`, the actor becomes the impersonator and
    `context.on_behalf_of` is set to `Auth::id()`. `currentActorKind()`
    stays `USER`.

#### 5.5 `app/Http/Middleware/EnsureNotImpersonating.php` (Create, alias `not_impersonating`)

*   **Proposed Changes**: Returns 403 when the current token is an
    impersonation token. It is applied in `routes/api.php` to `/connect`, to
    password-change routes, to user role and staff-role updates, and to any
    route that creates tokens.

#### 5.6 `app/Http/Resources/UserResource.php` (Modify)

*   **Proposed Changes**: When the request uses an impersonation token, adds
    `impersonation: { impersonator: {id, name}, expires_at }`.

#### 5.7 `routes/console.php` (Modify)

*   **Proposed Changes**: Schedules `sanctum:prune-expired --hours=1`, if it
    is not already scheduled.

#### 5.8 `tests/Feature/Superadmin/ImpersonationTest.php` (Create)

*   **Proposed Changes**: Covers:
    *   CUJ 4;
    *   the token works on hotel endpoints as the target user;
    *   a write is logged with the super admin as actor and
        `on_behalf_of` set;
    *   the token fails after 30 minutes (time travel);
    *   `/connect`, password change and role change return 403;
    *   impersonating a super admin returns 422;
    *   in a suspended hotel, `GET` works and `POST` returns 403;
    *   start and end events are recorded.

#### 5.9 `tests/Feature/EventLogTest.php` (Modify)

*   **Proposed Changes**: Adds a case proving rows with `hotel_id = null`
    and `platform.*` rows never appear on tenant event-log endpoints.

#### 5.10 Documentation

*   **Create**: `docs/superadmin-impersonation-api-documentation.md`.
*   **Modify**: `docs/auth-api-documentation.md` (the impersonation field
    on the user) and `docs/whatsapp-device-api-documentation.md`
    (`/connect` is refused while impersonating).

### Stage 6: Remove super admin access to hotel endpoints

#### 6.1 `app/Http/Middleware/DenySuperAdmin.php` (Create, alias `no_super_admin`)

*   **Proposed Changes**: Returns 403 "Super admins use /api/superadmin" when
    `$request->user()->isSuperAdmin()`. Impersonation tokens belong to the
    target user, so they pass.

#### 6.2 `bootstrap/app.php` and `routes/api.php` (Modify)

*   **Proposed Changes**: Registers the alias and adds it to the tenant
    group after `not_suspended`. `/logout` and `GET /user` stay reachable,
    so the frontend can still route a super admin to the super admin area.

#### 6.3 Policies (Modify: remove the `before()` super admin bypass)

*   **Files**: `ActivityCategoryPolicy`, `ActivityPolicy`,
    `AiInsightsPolicy`, `BookingPolicy`, `ConversationPolicy`,
    `EventLogPolicy`, `GuestMemoryPolicy`, `GuestPolicy`, `HotelPolicy`,
    `HotelPolicyPolicy`, `KnowledgeBaseArticlePolicy`,
    `KnowledgeDocumentPolicy`, `ProactiveMessagePolicy`,
    `RecommendationPolicy`, `ReservationImportPolicy`,
    `ReservationImportPreviewPolicy`, `ReservationPolicy`, `RoomPolicy`,
    `RoomTypePolicy`, `StaffRolePolicy`, `StayPolicy`, `TaskCategoryPolicy`,
    `TaskPolicy`, `TeamPolicy`, `TransactionPolicy`, `UserPolicy`,
    `VoucherTemplatePolicy` (all in `app/Policies/`).
*   **Proposed Changes**: The `isSuperAdmin()` short-circuit is deleted.
    `KnowledgeDocumentPolicy` and `KnowledgeBaseArticlePolicy` are checked
    first: if the superadmin knowledge controllers authorize through them,
    those controllers switch to their own explicit checks.

#### 6.4 Other `isSuperAdmin()` branches (Modify, decided file by file)

*   **Files**: `app/Helpers/Helper.php` (`resolveHotel`),
    `app/Models/User.php` (`permissions()`),
    `app/Http/Controllers/AccountUsageController.php`,
    `AiActivityController.php`, `KnowledgeBaseArticleController.php`,
    `KnowledgeDocumentController.php`, `UserController.php`,
    `app/Ai/Tools/Admin/GuardedTool.php`, `app/Ai/Tools/GetReportTool.php`,
    `app/Ai/Tools/GetStaffTool.php`,
    `app/Http/Resources/AiActivityResource.php` and
    `app/Http/Resources/KnowledgeDocumentResource.php`.
*   **Proposed Changes**: Before any edit, the PR description lists each
    branch with one of three decisions: remove it (tenant-only path), keep
    it (shared with the superadmin stack), or move it to a superadmin
    controller.
*   `User::accessibleHotelIds()` and `EnsureSuperAdmin` are not changed.

#### 6.5 Tests that use a super admin as a hotel actor (Modify)

*   **Files**: `AiActivityApiTest`, `AiAdvisorControllerTest`,
    `AvailabilityControllerTest`, `ConversationViewerTest`,
    `CreateUserTest`, `DashboardCacheTest`, `DashboardControllerTest`,
    `ExportControllerTest`, `FilterableTest`, `FreshInstallTest`,
    `GenericCrudTest`, `GuestControllerTest`, `HotelControllerTest`,
    `HotelPolicyControllerTest`, `HousekeepingBoardTest`,
    `KnowledgeBaseArticleControllerTest`, `OperationalDefaultsTest`,
    `ReservationControllerTest`, `ReservationImportPreviewTest`,
    `ReservationImportTest`, `RoomControllerTest`, `StayListTest`,
    `TenantIsolationTest`, `VoucherTemplateControllerTest` (all in
    `tests/Feature/`), plus `tests/Pest.php` helpers and
    `tests/Support/EntryPointDiscovery.php`.
*   **Proposed Changes**: The actor becomes an admin, or an impersonation
    token where the super admin path is what is being tested. Super admin
    cases that test the old behaviour become `403` assertions.

#### 6.6 `tests/Feature/SuperAdminDeniedOnTenantRoutesTest.php` (Create)

*   **Proposed Changes**: Walks every route in `routes/api.php` behind
    `auth:sanctum`, except `/logout` and `GET /user`, as a super admin, and
    asserts `403`. Repeats with an impersonation token on one route and
    asserts it succeeds.

#### 6.7 `tests/Feature/CrossTenantRouteTest.php` and `tests/Feature/PermissionAuthorizationTest.php` (Modify)

*   **Proposed Changes**: Remove any super admin "sees everything" cases
    that no longer apply.

#### 6.8 Documentation

*   **Modify**: `docs/dashboard-api-documentation.md` (super admins get
    403), `docs/staff-roles-api-documentation.md` (super admins are no
    longer implied on hotel endpoints) and `CLAUDE.md` (Tenancy: super
    admins reach hotel data only through impersonation).
*   **Create**: `docs/latest-changes-<date>.md`.

### Verification Plan

*   **Build**: `composer install` and `php artisan migrate` on a fresh
    Postgres database.
*   **Tests**: `php artisan test --parallel`, run against the Postgres
    container. Every stage's new tests pass, and so do all existing
    inventory tests (`EntryPointInventoryTest`, `CrossTenantRouteTest`,
    `QueueLaneTest`, `JobTenantIsolationTest`, `AiToolInventoryTest`).
*   **Lint**: `./vendor/bin/pint --test`.
*   **Coverage**: Each new controller, service and middleware has feature
    tests for success, 403 (wrong role), and the suspension and
    impersonation edge cases listed above.
*   **Manual**: On staging, onboard an account through the API (CUJ 1).
    Suspend it and confirm the frontend shows the suspended message and
    WhatsApp sends the fixed reply (CUJ 2). Impersonate a hotel admin and
    confirm the banner and the 30-minute expiry (CUJ 4).

--------------------------------------------------------------------------------

*Drafted with EGM*
