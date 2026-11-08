# Super Admin Platform Module (v1)

*   **Author:** Abdalrahman-Abdallah-Ahmed
*   **Status:** Proposed
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
4.  **Separation.** Super admins lose direct access to hotel endpoints.
    Impersonation becomes the only way into a hotel's operational screens.

All existing super-admin routes move from `api/admin` to `api/superadmin` in one
release (a hard cut). Every super admin write is recorded in `EventLog`.

## The Problem

Today the super admin is treated as a hotel admin with no limits. That causes
four problems.

1.  **The dashboard is wrong for them.** `GET /api/dashboard`
    (`DashboardController::generalData`) is the hotel dashboard. A super admin
    with no home hotel gets `403 "You do not belong to any hotel"`. One with a
    home hotel gets cross-hotel task, arrival and VIP counts
    (the tenant scope is unrestricted and the cache key is `all`) next to
    single-hotel occupancy. Nothing shows platform-level figures such as the
    number of accounts, hotels and users, or how active each hotel is.
2.  **Onboarding needs the database.** The public `POST /api/register` creates
    one admin, one hotel and a single-hotel account (`HotelGroup`) for whoever
    signs up. There is no API for a super admin to create an account for a
    customer, add hotels to an existing account, or create its first admin.
    Hotel creation through `POST /api/hotel` works only because super admins
    skip every policy (`HotelPolicy::create()` returns `false` for everyone
    else). Onboarding a multi-hotel customer means seeders or SQL.
3.  **There is no way to stop a customer.** Nothing suspends an account or
    hotel. `is_active` exists, but hotel admins can change it themselves, and
    jobs already read it as "switched off for housekeeping or proactive
    messages", not as "suspended". `HotelGroup.is_active` is not read anywhere.
    Suspending a non-paying customer today means editing data by hand, and their
    WhatsApp concierge, AI jobs and staff logins keep running.
4.  **Super admin actions inside hotels cannot be attributed.** Super admins read
    and write hotel data directly through about 25 policy bypasses
    (`before(): isSuperAdmin()`), and the logs show only that a super admin did
    it. Nothing separates "platform operator" from "hotel user", and there is
    no way to see a hotel exactly as its staff see it.

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
    `staff_role_id`. No API request can grant `super_admin`, on create or on
    promotion, whoever is asking. Super admins come only from the seeder or
    CLI, and they have no hotel links: no `hotel_id`, no `hotel_group_id` or
    `group_role`, and no `hotel_user` rows.
4.  **Reset access.** Send a password reset email, and revoke all of a user's
    tokens. Revoking also unpairs their WhatsApp device.
5.  **Full-stop suspension** at account and hotel level, stored in new
    `suspended_at`, `suspended_by` and `suspended_reason` columns:
    *   login is blocked, and existing tokens are rejected with a
        machine-readable "suspended" response;
    *   jobs that act on one hotel stop, including ones already queued. A
        stopped job is not run and is not recorded as a failed job. Its
        `failed()` handler, if it has one, still runs to clean up;
    *   the AI concierge and Admin Advisor stop. Guests who write in get a
        fixed reply that is not AI-generated;
    *   a user with several hotels keeps access to the ones that are not
        suspended;
    *   data is kept. Reactivation restores login, API access, AI replies and
        job processing. Jobs skipped during the suspension are not replayed.
        Proactive messages still valid at reactivation may be sent late.
6.  **Super admin dashboard** with:
    *   platform counts: accounts, hotels and users; active vs suspended;
        signups over time;
    *   a usage and AI cost summary;
    *   activity per hotel: reservation and booking counts.

    The window defaults to the last 30 days and can be chosen with `from`/`to`
    (at most 12 months).
7.  **Impersonation you can attribute.** A super admin can start an
    impersonation of any user who is not a super admin. Rules:
    *   the token expires after 30 minutes, cannot be extended, and can be
        ended early through an end endpoint;
    *   `EventLog` records both the hotel user and the real super admin;
    *   the token cannot pair WhatsApp devices, or create, edit or delete users
        or staff roles. That covers passwords, emails, roles and hotel
        membership;
    *   in a suspended hotel, the token can only read.
8.  **Super admins lose direct access to hotel endpoints.** Hotel endpoints
    return 403 to a super admin who is not impersonating. This ships last, as
    stage 6, after stages 2 and 5.
9.  **Audit.** Every new super admin write (account, hotel and user
    administration), suspension, reactivation, reset, and impersonation start
    and end (when ended through the end endpoint or logout) is recorded in
    `EventLog`. The controllers moved from `api/admin` keep their current audit
    behaviour in v1. Rows for
    account-level actions (`hotel_id = null`) never appear on tenant event log
    endpoints.

### Success Criteria

*   No `api/admin` route is registered, and the frontend's super admin area
    calls only `api/superadmin`.
*   CUJ 1 passes as an end-to-end feature test with no seeder or SQL step.
*   A route test proves every `auth:sanctum` tenant route has
    `not_suspended`, and a job inventory test proves every hotel job has
    `SkipIfHotelSuspended`.
*   The stage 6 test proves every hotel endpoint, except `/logout` and
    `GET /user`, returns 403 to a super admin's own token.
*   Sentry events from a request or job blocked by suspension carry
    `suspended=true`. Events from a request made with an impersonation token
    carry `impersonating=true` and `impersonator_id`.

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
*   Stopping queued notifications when a hotel is suspended. Six queued
    notifications are not jobs in `app/Jobs`, so ones already queued may still
    send (risk R11).
*   Super admin editing of staff role definitions. Only the hotel's own admins
    create or edit staff roles. Super admins can assign an existing role.
*   A read-only suspension mode. Suspension is a full stop.
*   Keeping `api/admin` as an alias. The move is a hard cut.
*   Snapshot tables, websockets or SSE for the dashboard.

## Open Questions

*   **Existing `api/admin` controllers (blocks stage 6).** It is not yet
    verified which of them rely on the unrestricted tenant scope
    (`TenantContext::hotelIds() === null`). `KnowledgeRebuildController` is
    known to. The others need checking one by one before stage 6.

## Critical User Journeys (CUJs)

### CUJ 1: Onboard a new customer without SQL

*   **Given**: A super admin is signed in. No account exists for the customer.
*   **When**: They `POST /api/superadmin/accounts`, then
    `POST /api/superadmin/accounts/{account}/hotels` for each hotel, then
    `POST /api/superadmin/users` for the first admin, with `hotel_id` and
    `role=admin`.
*   **Then**: The account, hotels and admin exist, and the admin owns their
    hotel. The admin receives a password reset email, sets a password, and can
    then log in at `POST /api/login`. The super admin never knows the password.
    Each creation writes an `EventLog` row with the super admin as actor.

### CUJ 2: Suspend a non-paying account

*   **Given**: An account has two hotels. Staff are signed in, and guests are
    using WhatsApp.
*   **When**: A super admin calls
    `POST /api/superadmin/accounts/{account}/suspend` with a reason.
*   **Then**:
    *   The staff's next request returns `403` with
        `body.reason = "suspended"` and `body.scope = "account"`, and the frontend
        shows the suspended message.
    *   A new login returns the same response.
    *   A guest WhatsApp message gets the fixed reply, with no AI run.
    *   Scheduled and queued jobs for both hotels exit without doing any work.
    *   `POST /api/superadmin/accounts/{account}/reactivate` restores access
        and processing. Skipped jobs are not replayed. Proactive messages still
        valid at that point may be sent late.

### CUJ 3: Suspend one hotel of a multi-hotel user

*   **Given**: A user belongs to hotels A and B in the same account.
*   **When**: Hotel A is suspended.
*   **Then**:
    *   The user can still log in, and their hotel list (`GET /api/hotel`)
        shows only B.
    *   A request without `X-Hotel-Id` works in B, even when A is the user's
        default hotel.
    *   A request with `X-Hotel-Id: A` returns the suspended `403` with
        `body.scope = "hotel"`.

### CUJ 4: Support a hotel through impersonation

*   **Given**: A hotel admin reports a problem.
*   **When**: A super admin calls
    `POST /api/superadmin/users/{user}/impersonate`.
*   **Then**:
    *   They receive a token for that user that expires after 30 minutes.
    *   Hotel endpoints apply that user's permissions and tenant scope, except
        for the restrictions below.
    *   Every write in the request is logged with the super admin as actor and
        `context.impersonated_user_id` set to the user.
    *   `POST /api/superadmin/impersonation/end` or `POST /api/logout` ends the
        session and records an end event. A session that reaches 30 minutes
        expires without an end event; the start event records when it expires.
    *   The token cannot connect WhatsApp, or create, edit or delete users or
        staff roles.
    *   In a suspended hotel, a `GET` with the token returns the hotel's data,
        and any other method returns the suspended `403`.

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

| Stage | Goals | CUJs | Depends on |
| --- | --- | --- | --- |
| 1. Route move | 1 | — | — |
| 2. Administration and reset access | 2, 3, 4 | 1, 5 | 1 |
| 3. Suspension | 5 | 2, 3 | 2 |
| 4. Dashboard | 6 | 6 | 1, 3 |
| 5. Impersonation | 7 | 4 | 2 |
| 6. Super admins lose hotel access | 8 | — | 2, 5 |
| Audit (every stage) | 9 | 1, 2, 4, 5 | — |

### Request paths after v1

```text
routes/api.php (hotel endpoints)
 client ── api.key ── auth:sanctum ── throttle:api ── tenant ── not_suspended [1] ── no_super_admin [2] ──▶ policy ──▶ controller

routes/superadmin.php (prefix api/superadmin)
 client ── api.key ── auth:sanctum ── throttle:api ── tenant [3] ── super_admin ──▶ Superadmin\* controller ──▶ service

[1] not_suspended (stage 3): drops suspended hotels from TenantContext, and returns 403 "suspended" when
    the account, all of the user's hotels, or X-Hotel-Id is suspended. An impersonation token keeps its
    scope and may still GET/HEAD.
[2] no_super_admin (stage 6): 403 for a super admin token that is not an impersonation token.
[3] tenant: unchanged; super admins keep the null (unrestricted) scope.
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
    `HotelGroup`, plus suspend and reactivate. Responses use a super-admin-only
    resource, because the suspension fields must never reach hotel staff. The
    account list includes counts of hotels and users and the suspension state.
*   **Hotels.** New `Superadmin\HotelController` lists hotels across accounts
    (filterable by account), creates hotels under an account, deletes them, and
    edits platform fields only (`name`, `slug`, `hotel_group_id`). The existing
    tenant `HotelController` keeps the hotel admin's own edits. Hotel creation
    and deletion move out of the tenant `HotelController`, where they work today
    only through the super admin bypass. Creation is shared through a service,
    so the restore of a soft-deleted hotel with the same slug is kept.
*   **Users.** New `Superadmin\UserController` lists every user who is not a
    super admin, filtered by account, hotel and role. It can create and edit
    them, and change `role`, `group_role`, hotel membership (the `hotel_user`
    pivot, limited to hotels in the user's account) and `staff_role_id`.
    *   Shared rules move into a service (`App\Services\Superadmin\UserAdministration`)
        so they are not copied from the tenant `UserController`. The
        `denySuperAdminGrant` guard is one of them, and it now refuses
        `role=super_admin` from everyone, super admins included. Today it lets
        super admins grant the role.
    *   A new user gets a random password and a password reset email, so the
        super admin never knows the credential. (Spec 025 removed `hotels.owner_id`:
        ownership belongs to the account, and no admin becomes an owner
        automatically.)
*   **Reset access.** Lives in the same service:
    *   `sendPasswordReset(User)` uses Laravel's password broker
        (`password_reset_tokens` already exists) and Laravel's built-in reset
        notification, with `ResetPassword::createUrlUsing()` pointing to the
        frontend's reset page.
    *   `revokeAccess(User)` deletes all of the user's Sanctum tokens and
        unpairs their `WhatsAppDevice` in one transaction.
    *   A public `POST /api/reset-password` endpoint accepts the token and
        the new password. A completed reset also calls `revokeAccess(User)`, so
        a paired device cannot outlast a password change.
*   **Querying across hotels.** Super admin controllers read across hotels.
    They use `TenantContext::withoutScope()` explicitly instead of relying on
    the super admin's `null` scope, so stage 6 cannot change their results.
*   **Authorization.** Super admin controllers rely only on the route guard.
    They never call tenant policies, which stop granting super admins anything
    in stage 6.

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
    *   the user has at least one hotel, and every one of them is suspended.

    It issues no token in that case. Checking credentials first means the
    suspended message is only revealed to someone who knows the password.
*   **HTTP.** A new `not_suspended` middleware (`EnsureNotSuspended`) runs
    after `tenant` and before route-model binding, on the tenant group in
    `routes/api.php`. `User`, `ResolveTenant` and `TenantContext` do not change.
    For a `null` scope (super admins) it does nothing, because they are never
    suspended. Otherwise it runs one query of the hotels in
    `TenantContext::hotelIds()` with their suspension state and their group's. It:
    1.  removes suspended hotels from `TenantContext::hotelIds()` and from the
        current hotel. If the current hotel is removed, the first remaining
        hotel becomes current. When the user's default hotel (`hotel_id`) is
        among the removed hotels, the in-memory `hotel` relation is set to the
        resulting current hotel, because policies and `resolveHotel()` read
        `$user->hotel`. The `hotel_id` attribute is not changed;
    2.  returns the suspended `403` when the account is suspended, suspended
        hotels were removed and none remain, or `X-Hotel-Id` names a suspended
        hotel;
    3.  treats an impersonation token by method. On `GET` and `HEAD` it skips
        steps 1 and 2, so support can read suspended hotels. On any other method
        it follows steps 1 and 2 like a normal user, so it can write only to
        active hotels.

    A user with no hotels at all keeps today's behaviour and is not told they
    are suspended, unless `User::account()` shows their account is. Account
    owners follow the same rule as other users: a suspended hotel is hidden from
    them, including in account-level views.

    The suspended response is
    `apiResponse($message, 403, ['reason' => 'suspended', 'scope' => 'account'|'hotel'])`.
    The message is "This account has been suspended." for `account` and "This
    hotel has been suspended." for `hotel`.

    A route test fails if any `auth:sanctum` route, other than the super admin
    routes and the logout route, is missing `not_suspended`.
*   **Hotel list.** `GET /api/hotel` is the user's hotel list. It needs a new
    `hotels.view` permission, checked through `allows()`, and it lists only the
    hotels in `TenantContext::hotelIds()`. Admins hold the permission
    automatically. Employees get it only through a staff role, and it is not in
    `employeeDefaults()`. This changes today's rule that `/api/hotel` is
    admin-only, for this read alone: it opens `viewAny` and no other hotel
    ability. On a hotel's own routes, after the normal authorization check, a
    hotel that is really suspended returns the suspended `403`. A hotel the
    caller cannot reach gets the normal policy answer, never "suspended".
*   **Jobs.** A new job middleware `SkipIfHotelSuspended` reads the job's
    hotel id through an interface (`HasHotel::hotelId()`). When the hotel is
    suspended, it does not run the job. It calls the job's `failed()` handler
    directly with a `HotelSuspended` exception when the job has one, logs one
    line, and returns. The job then leaves the queue. No `failed_jobs` row is
    written, so the repeated-failure alert and Sentry need no exclusion. A job
    with a null hotel id, such as a global knowledge document, runs.
    *   Every job that acts on one hotel declares the middleware in
        `middleware()`.
    *   A test in the style of `QueueLaneTest` fails if a job that acts on one
        hotel neither has the middleware nor appears on a list that states its
        reason.
    *   Jobs that loop over hotels (proactive triggers, housekeeping day, expiry,
        outcomes) select hotels with `notSuspended()`. Guest memory retries
        filter their rows to hotels that are not suspended, and due retries
        resume after reactivation.
    *   A job that is already running when its hotel is suspended finishes. The
        check runs only when a job starts.
    *   Recurring scheduled jobs pick the hotel up again on their next run after
        reactivation. One-off work (imports, vouchers, guest request notices) is
        not re-run; staff redo it after reactivation.
    *   Proactive messages are the exception. A blocked job's row stays
        `SCHEDULED`, and the first `EvaluateProactiveTriggersJob` run after
        reactivation may also create rows that are still inside the trigger
        finder's normal window. No catch-up of missed messages is built.
        Proactive messages still valid at reactivation may be sent late.
        Send-time guardrails decide, including `valid_until`, the WhatsApp
        window and quiet hours.
*   **WhatsApp.** `ProcessInboundWhatsAppMessageJob` does not use the
    middleware. The webhook has already stored the inbound message.
    *   `replyFor()` returns the existing `UNAVAILABLE_REPLY` for a suspended
        hotel, after the not-paired and opt-out checks and before any AI run. So
        STOP still works. The text is one English string for the whole platform:
        "Sorry, I can't answer messages right now. Please contact the hotel
        directly for assistance."
    *   The fixed reply is sent at most once every 24 hours per hotel and phone
        number. Later messages stay stored with no reply.
    *   A staff sender (Admin Advisor) of a suspended hotel gets the same reply.
    *   `SendWhatsAppMessageJob` carries no hotel, and stays unchanged and
        ungated.
*   **Sentry.** `not_suspended` and the job middleware tag the Sentry scope
    with `suspended=true` when they block.

### 4. Dashboard (stage 4)

*   Three endpoints, so one slow section does not hold up the others:
    *   `GET /api/superadmin/dashboard/counts`: accounts, hotels and users
        (excluding super admins), split active vs suspended, plus signups per
        day. Signups are accounts created and users created, grouped by day.
        Accounts created through `POST /api/register` count as signups.
    *   `GET /api/superadmin/dashboard/usage`: totals from the existing
        `UsageReport::totals()` and `aiTotals()`, grouped by account, plus AI
        cost per account.
    *   `GET /api/superadmin/dashboard/activity`: reservations and bookings
        created per hotel, counted with one grouped query each under
        `withoutScope()`.
*   `from` and `to` are dates (`Y-m-d`, UTC), not timestamps. They default to
    the 30 days ending today, and the range can be at most 12 months.
    Validation is done in a `SuperadminDashboardRequest`.
*   **Cached for 15 minutes** (see Alternative K):
    *   each section is wrapped in `Cache::remember` with a 900-second
        lifetime;
    *   the key is `superadmin:dashboard:v1:{section}:{from}:{to}` with both
        dates as `Y-m-d`, so requests on the same day share one entry per
        section. It uses the default cache store;
    *   the cached value is plain arrays only (no models or objects), following
        the same rule as `DashboardController`, so reading it back never yields
        `__PHP_Incomplete_Class`;
    *   there are no snapshot tables.

    A super admin's own changes (a new account, a suspension) can take up to
    15 minutes to appear. The responses include `cached_at`, so the frontend
    can show how old the figures are.
*   `App\Services\Reports\PlatformSummary` owns the queries, except AI cost, which
    `App\Services\AiCost\AiCostReport` owns because `CostVisibilityTest` keeps cost
    out of `app/Services/Reports/`. The controller validates, reads the cache,
    delegates, and adds the cost to the usage section.

### 5. Impersonation (stage 5)

*   **Schema.** A migration adds `impersonator_id` (nullable uuid, references
    `users`) to `personal_access_tokens`. `expires_at` already exists.
*   **Start.** `POST /api/superadmin/users/{user}/impersonate`:
    *   refuses if the target is a super admin;
    *   creates a token for the target user named `impersonation`, with
        `impersonator_id` set to the super admin and `expires_at` set to now
        plus 30 minutes;
    *   records a `platform.impersonation.started` event;
    *   returns the token, the token id and its expiry.

    Sessions cannot be extended; a new one has to be started.
*   **End.** `POST /api/superadmin/impersonation/end` takes the impersonation
    token id, deletes the token and records `platform.impersonation.ended`.
    Logging out
    with an impersonation token records the same event. Expiry is enforced by
    Sanctum's `expires_at` and writes no event; the `expires_at` stored on the
    start event shows when the session ended. An hourly prune removes every
    expired token, including impersonation tokens.
*   **Who am I.** `GET /api/user` includes an `impersonation` object
    (`impersonator`, `expires_at`) when the current token has an
    `impersonator_id`, so the frontend can show a banner.
*   **Attribution.** `EventLogger::record()` checks the current token. When it
    has an `impersonator_id`, the actor becomes the super admin and
    `context.impersonated_user_id` is set to the impersonated user's id. Every
    write that is already audited inside the request gets this without changing
    its caller. Writes made later by jobs the request dispatched, such as
    reservation imports and AI runs, are still recorded as the hotel user (risk
    R8).
*   **Sentry.** When the current token has an `impersonator_id`, the request's
    Sentry scope is tagged `impersonating=true` and `impersonator_id`, through
    the existing `TagsSentryScope`.
*   **Restrictions.** A new `not_impersonating` middleware returns 403 to an
    impersonation token on:
    *   `POST /api/connect`, the only authenticated route that creates tokens.
        `POST /api/login` is unauthenticated, so an impersonation token never
        reaches it;
    *   every write method on `/api/users` and `/api/staff-roles`.
        `PUT /api/users/{user}` accepts `password` and `email`, because
        `GenericUpdateRequest` builds its rules from fillable columns. Without
        this block, an impersonation token could set a permanent password and
        keep access after the 30 minutes.

    To change users or assign a staff role, a super admin uses `api/superadmin`.
    Staff role definitions are changed only by the hotel's own admins.
    In a suspended hotel, `not_suspended` limits the token to `GET` and
    `HEAD`.

### 6. Super admins lose hotel access (stage 6)

*   A new `no_super_admin` middleware on the tenant group in `routes/api.php`
    returns 403 to a super admin's own token. An impersonation token belongs
    to the hotel user, so it passes. `POST /logout` and `GET /user` are exempt,
    because they are in the same group and the super admin frontend needs them.
*   The super admin bypasses are removed:
    *   the policy `before()` bypasses (about 25);
    *   the `isSuperAdmin()` branches in `resolveHotel()`, tenant controllers,
        AI tools (`GuardedTool`,
        `GetStaffTool`, `GetReportTool`) and Resources (about 20).

    Each is listed first in an inventory, with a decision recorded per file.
*   `User::accessibleHotelIds()` keeps returning `null` for super admins.
    The super admin stack still relies on it, and the `null` vs `[]` rule in
    CLAUDE.md stays intact.
*   The test files listed in plan item 6.5 that use a super admin as a hotel user
    are rewritten
    to use an admin, or an impersonation token where the super admin's view
    is the point of the test.
*   A generic test walks every route in `routes/api.php` behind
    `auth:sanctum`, except `/logout` and `GET /user`, as a super admin and
    expects `403`.

### Audit (all stages)

*   Super admin writes are recorded through a new
    `EventLogger::recordPlatform()`:
    *   platform rows always have `hotel_id = null`, whatever the subject or
        current tenant. The row is written inside
        `TenantContext::runForHotel(null, ...)`, which clears the current hotel.
        Setting `hotel_id = null` on the row is not enough, because the
        `EventLog` create hook fills any empty `hotel_id` from the current
        hotel. `withoutScope()` is not enough either, because it keeps the
        current hotel. This matters when an impersonation token logs out: that
        request runs on the tenant stack, where the current hotel is set;
    *   it takes the `event_type` as an argument, so names start with
        `platform.` (for example `platform.account.suspended`). Today
        `record()` builds the name from the subject's class name;
    *   it resolves the actor the same way as `record()`, including the
        impersonator swap. The in-memory token still has `impersonator_id`
        after `apiLogout` deletes it.
*   Super admin services call it explicitly. `RecordsEvents` is not added to
    `HotelGroup` or `User`. Each action writes one row, and tenant edits to
    users do not start producing platform rows.
*   A test proves platform rows have `hotel_id = null` and do not appear on any
    hotel's event log endpoint, including the end event written when an
    impersonation token logs out. That test also asserts the super admin is the
    actor and `context.impersonated_user_id` is set.
*   A test proves that granting `super_admin` through `POST` or
    `PUT /api/users` is refused for every caller.

## Alternatives Considered

### Alternative A: Keep super admin routes in `routes/admin.php` under `api/admin`

*   **Pros**: No breaking change, and no coupled frontend deploy.
*   **Cons**: The prefix `admin` reads as "hotel admin", not "platform
    operator", and new platform endpoints would sit next to the old ones
    under a misleading name.
*   **Reason for Rejection**: The `admin` prefix reads as "hotel admin".
    Keeping it would put new platform endpoints under a misleading name for
    good, while renaming is a one-time cost because both apps release together.

### Alternative B: Move routes with a one-release `api/admin` alias

*   **Pros**: The frontend and backend can deploy separately, and rollback is
    easier.
*   **Cons**: Two prefixes are live for a release, and the alias has to be
    removed later.
*   **Reason for Rejection**: Both apps release together, so an alias adds a
    second live prefix and a later removal step without making any deploy
    safer. The hard cut is documented in `docs/latest-changes-<date>.md`.

### Alternative C: Reuse `is_active` for suspension

*   **Pros**: No migration, because the column already exists on `hotels` and
    `hotel_groups`.
*   **Cons**: Hotel admins can write `hotels.is_active` (it is in `$fillable`
    and `GenericUpdateRequest` derives its rules from fillable columns), so a
    suspended hotel could reactivate itself. Jobs already read it as "switched
    off for housekeeping or proactive messages", which is a different meaning.
    It also has no "who, when, why" record.
*   **Reason for Rejection**: A hotel admin could clear their own suspension
    through `PUT /api/hotel/{id}`, and jobs would mix two meanings of one
    column. New `suspended_*` columns are written only by the super admin
    service and record who suspended, when and why.

### Alternative D: Read-only suspension

*   **Pros**: Staff could still see their data and finish guests' stays.
*   **Cons**: Every write path needs a check: HTTP, about 20 jobs, the
    WhatsApp webhook, and AI write tools. It also needs a list of exemptions
    (logout, check-out, cancel) that would keep growing.
*   **Reason for Rejection**: Read-only needs a check on every write path and
    an exemption list that keeps growing. A full stop needs only the
    enforcement points in §3: login, `not_suspended`, `SkipIfHotelSuspended`,
    `notSuspended()` in jobs that loop over hotels, and the inbound WhatsApp
    job.

### Alternative E: Stop jobs inside `TenantContext::runForHotel()`

*   **Pros**: One place covers jobs, AI tools and console commands.
*   **Cons**: Failure and cleanup paths that also run inside `runForHotel`
    (an import marked failed, a voucher marked finished) would be blocked,
    and the WhatsApp fixed reply would need a bypass.
*   **Reason for Rejection**: Blocking inside `runForHotel()` would also block
    the failure and cleanup paths that run inside it. A job middleware is
    declared per job, is enforced by an inventory test, and calls the job's
    `failed()` handler directly when it blocks.

### Alternative F: Enforce suspension in `User::accessibleHotelIds()` or `ResolveTenant`

*   **Pros**: No new middleware alias, and every tenant route already passes
    through `ResolveTenant`.
*   **Cons**: `accessibleHotelIds()` would quietly hide suspended hotels from
    every future caller, for example an account owner's overview.
    `ResolveTenant` also runs on the super admin stack, so it would need
    exceptions for super admins and for impersonation.
*   **Reason for Rejection**: Hiding suspended hotels in
    `accessibleHotelIds()` changes what every future caller sees, and
    `ResolveTenant` would need exceptions for super admins and impersonation.
    One middleware keeps suspension in one class, and a route test checks that
    it is present.

### Alternative G: Edge block only, or a tenant scope change, for removing super admin hotel access

*   **Pros**: An edge block alone is a small change. A scope change (`[]` for
    super admins, plus a `platform` resolver) puts the restriction at the data
    layer.
*   **Cons**: An edge block alone leaves about 45 dead bypass branches that
    someone may later rely on. A scope change risks queries that return zero
    rows without any error on any super admin path still using `tenant`, and
    it touches the `null` vs `[]` rule that CLAUDE.md warns about.
*   **Reason for Rejection**: An edge block alone leaves about 45 bypass
    branches that nothing exercises any more. A scope change risks silent
    zero-row results and touches the `null` vs `[]` rule. The edge block plus
    bypass removal, with an inventory first, avoids both.
    `accessibleHotelIds()` keeps returning `null`.

### Alternative H: Impersonation through an `X-Act-As` header on the super admin's own token

*   **Pros**: No new token, and no schema change.
*   **Cons**: Every `Auth::user()` read, every policy, `ResolveTenant` and
    `User::permissions()` would have to understand the header.
*   **Reason for Rejection**: A header would have to be understood by every
    `Auth::user()` read, every policy, `ResolveTenant` and
    `User::permissions()`. A token issued to the target user makes all of them
    work unchanged.

### Alternative I: Record impersonated writes as the hotel user, or as a new `ActorKind`

*   **Pros**: With the hotel user as actor, hotel event logs read naturally. A
    new `ActorKind` case could be filtered on its own.
*   **Cons**: With the hotel user as actor, the log looks as if that user did
    it unless the reader checks `context`. A new `ActorKind` means every
    `ActorKind` switch (exports, reports) needs a new case.
*   **Reason for Rejection**: With the hotel user as actor, the log looks as if
    that user acted unless the reader checks `context`. A new `ActorKind` needs
    a new case in every `ActorKind` switch. The super admin as actor, with the
    user in `context.impersonated_user_id`, avoids both.

### Alternative J: Longer or extendable impersonation sessions

*   **Pros**: Fewer interruptions during long support sessions.
*   **Cons**: Elevated access lasts longer, and extending needs its own
    endpoint and audit trail.
*   **Reason for Rejection**: Each extension widens the window in which a
    leaked token works, and needs its own endpoint and audit event. Starting a
    new 30-minute session costs one call.

### Alternative K: Live dashboard, or nightly snapshot tables

*   **Pros**: Live gives figures that are always current. Snapshots give the
    lowest query cost.
*   **Cons**: Live re-runs three grouped queries across every hotel on each
    page load. Nothing has been measured. With fewer than 50 hotels each run is
    expected to be cheap, so the cache limits repeated loads; it does not fix a
    known slow query. Snapshots need new tables, a scheduled job and a backfill.
*   **Reason for Rejection**: Snapshots need new tables, a scheduled job and a
    backfill. A 15-minute cache per section limits the cross-hotel queries to
    one run per section per window, at the cost of figures up to 15 minutes old.

### Alternative L: Health, failures and revenue on the dashboard

*   **Pros**: The super admin sees everything in one place.
*   **Cons**: There is no WhatsApp connection per hotel (one platform-wide
    number). `failed_jobs` has no `hotel_id`. Revenue is in mixed
    currencies.
*   **Reason for Rejection**: There is no per-hotel WhatsApp connection to
    report on, `failed_jobs` has no `hotel_id`, and revenue is in mixed
    currencies. Sentry already covers failures and WhatsApp health. Revenue
    needs a currency decision first.

### Alternative M: Temporary password, or a separate WhatsApp unpair action

*   **Pros**: A temporary password works without mail. A separate unpair
    action gives finer control.
*   **Cons**: With a temporary password, the super admin knows the user's
    credential. A separate unpair action leaves a gap: after tokens are
    revoked, the paired device would keep Admin Advisor access.
*   **Reason for Rejection**: With a temporary password, the super admin knows
    the user's credential. A separate unpair action leaves a gap: a paired
    device keeps Admin Advisor access after the tokens are revoked.

### Alternative N: Extend `POST /api/register` for super admin onboarding

*   **Pros**: One onboarding path, and no new controller.
*   **Cons**: It is public and creates exactly one hotel for the person
    registering. Super admin options there would put platform actions behind a
    public endpoint.
*   **Reason for Rejection**: Creating an account for another customer is a
    platform action, so it belongs behind the `super_admin` guard.
    Self-registration stays as it is (see R7).

## Risks & Mitigations

| # | Risk | Failure mode | Mitigation | Stage |
| --- | --- | --- | --- | --- |
| R1 | An impersonation token is leaked or misused | Whoever holds it acts as a hotel user | 30-minute expiry with no extension; it cannot pair WhatsApp or write to users or staff roles, so it cannot set a lasting password; every write in the request is attributed to the super admin; the start event records `expires_at` | 5 |
| R2 | Super admin paths return zero rows after stage 6 | A super admin controller still relies on the tenant scope and gets an empty list with no error | `accessibleHotelIds()` keeps returning `null`; super admin controllers use `withoutScope()` explicitly; Open Question 1 is settled per controller before stage 6 | 2, 6 |
| R3 | The stage 1 hard cut is not deployed together | The frontend calls `api/admin` and gets 404 | Both apps deploy in one release; `latest-changes` maps every old URL | 1 |
| R4 | A route or job misses the suspension check | A suspended hotel keeps working through that path | A route test for `not_suspended`; a job inventory test for `SkipIfHotelSuspended` | 3 |
| R5 | Platform audit rows appear on a tenant event log | Hotel staff see account-level events, such as a suspension reason | Super admins have no hotel links; platform events are written with the current hotel cleared, so `hotel_id` stays null (see Audit); a test checks it, including impersonation logout | 2 |
| R6 | Work queued at suspension is lost | Skipped messages and vouchers are never sent | Blocked jobs call their `failed()` handler directly to clean up; proactive messages still valid at reactivation may be sent late, and send-time guardrails decide; staff redo one-off work (imports, vouchers) after reactivation | 3 |
| R7 | A suspended customer registers again through `POST /api/register` | They get a new, empty account | Accepted for v1: the new account has none of the old data and can be suspended too | 3 |
| R8 | Writes from jobs started during impersonation are attributed to the hotel user | An import started by a super admin shows the hotel user as actor | Accepted for v1; the `platform.impersonation.started` event shows who held the session at that time | 5 |
| R9 | Removing about 45 bypasses breaks a path nobody listed | A hotel feature fails for a role that should have access | An inventory with one decision per file before any edit; the generic stage 6 test; the rewritten test files | 6 |
| R10 | Dashboard figures are up to 15 minutes old | A super admin does not see their own suspension in the counts | Every response carries `cached_at` | 4 |
| R11 | Queued notifications are not stopped by suspension | A notification queued before the suspension still sends | Accepted for v1: the volume is small and nothing new is queued, because the hotel's API access has stopped | 3 |
| R12 | A pairing code issued before a suspension is used after it | A device pairs within the code's 15-minute life | Accepted for v1: the paired device only receives the fixed reply | 3 |

--------------------------------------------------------------------------------

## Non-Functional Requirements

*   **Security.**
    *   Every `api/superadmin` route gets the `super_admin` guard from the
        file-level registration.
    *   Impersonation tokens last 30 minutes, are attributed to the real
        super admin, and cannot pair WhatsApp devices or write to users or
        staff roles.
    *   `suspended_*` columns cannot be written by tenant requests.
    *   The suspended message is only returned after the password has been
        checked.
    *   The reset-password endpoint is throttled the same way as
        `throttle:login`.
*   **Performance.**
    *   Dashboard sections are cached for 15 minutes.
    *   `not_suspended` adds one indexed query per hotel request, and one more
        only when it loads a replacement hotel or, for a user with no hotels, the
        account. The suspension state is not
        cached; it is read fresh on every request.
    *   The job middleware adds a small indexed lookup per job, with no cache.
*   **Rollout.**
    *   Stages ship in order, as separate PRs: 1 → 2 → 3 → 4 → 5 → 6.
    *   Stage 1 is a breaking move and must deploy together with the
        frontend.
    *   Stage 6 must not ship before stages 2 and 5.

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
    `App\Http\Controllers\Superadmin`. Behaviour does not change. Comments that
    mention `api/admin` change as in 1.5.

#### 1.5 Code comments that mention `api/admin` or `routes/admin.php` (Modify)

*   **Files**: `app/Http/Controllers/AccountUsageController.php`,
    `app/Http/Controllers/KnowledgeBaseArticleController.php`,
    `app/Http/Controllers/KnowledgeDocumentController.php`,
    `app/Services/Knowledge/ArticleCommands.php`,
    `app/Policies/KnowledgeDocumentPolicy.php`,
    `app/Models/KnowledgeIndexRebuild.php`, `app/Models/KnowledgeDocument.php`,
    `database/seeders/AiModelPriceSeeder.php`, `tests/Pest.php`, and the moved
    controllers in 1.4.
*   **Proposed Changes**: Comment and string references to `api/admin` become
    `api/superadmin`, and `routes/admin.php` becomes `routes/superadmin.php`.

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
    test asserts that `/api/admin/usage` returns 404. In `CostVisibilityTest`,
    the allowlist entry `app/Http/Controllers/Admin/` becomes
    `app/Http/Controllers/Superadmin/`.

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

#### 2.1 `app/Http/Controllers/Superadmin/AccountController.php` and `app/Http/Resources/Superadmin/AccountResource.php` (Create)

*   **Proposed Changes**:
    *   `index`: uses `AccountIndexRequest` and `GenericQuery::apply()`, with
        `withCount('hotels')` and a `users_count` subselect. The subselect counts
        distinct users attached by `hotel_group_id`, legacy `hotel_id` or the
        `hotel_user` pivot, excluding super admins. The seat query in
        `MeteringService` is not reused, because it writes counters and ignores
        the pivot.
    *   `store`, `show` and `update`.
    *   `destroy`: soft delete. It returns 422 while the account has hotels that
        are not deleted.

    Every response goes through `Superadmin\AccountResource`: `id`, `name`,
    `slug`, `country_code`, `default_currency`, `default_timezone`, `hotels_count`
    and `users_count`. Contract fields are not exposed or accepted in v1, so
    `CostVisibilityTest` does not need changing. Each write records
    `platform.account.created`, `.updated` or `.deleted`.

#### 2.2 `app/Http/Controllers/Superadmin/HotelController.php` and `app/Http/Resources/Superadmin/HotelResource.php` (Create)

*   **Proposed Changes**:
    *   `index`: all hotels, using `GenericIndexRequest`, filterable by
        `hotel_group_id`.
    *   `store`: takes `{account}` from the path and calls `HotelProvisioning`.
    *   `show`.
    *   `update`: `name`, `slug` and `hotel_group_id` only.
    *   `destroy`: soft delete.

    It runs under `TenantContext::withoutScope()` and responds with
    `Superadmin\HotelResource`, which extends the shared `HotelResource`. The
    shared `HotelResource` never gets suspension fields. Each write records
    `platform.hotel.created`, `.updated` or `.deleted`.

#### 2.2a `app/Services/Superadmin/HotelProvisioning.php` (Create)

*   **Proposed Changes**: The body of the tenant `HotelController::store` moves
    here, including the stripping of operational default fields. The restore of a
    soft-deleted hotel with the same slug changes: it happens only when
    `$trashed->hotel_group_id === $account->id`. Otherwise it returns 422 "The slug
    has already been taken." The old `owner_id` comparison would let a slug reuse
    restore another customer's deleted hotel and move its guests and reservations
    to the new account.

#### 2.3 `app/Http/Controllers/Superadmin/UserController.php` (Create)

*   **Proposed Changes**:
    *   `index`: every user who is not a super admin, using `UserIndexRequest`,
        filterable by `hotel_group_id`, `hotel_id` and `role`. The controller
        applies the account and hotel filters itself, across group, pivot and
        legacy `hotel_id`, and removes those two keys before `GenericQuery`,
        because `Filterable` would add an exact `WHERE` that drops pivot users.
    *   `store`, `show` and `update`.
    *   `updateAccess`: changes `role`, `group_role`, hotel memberships and
        `staff_role_id`.
    *   `sendPasswordReset`, and `revokeTokens`, which calls
        `UserAdministration::revokeAccess`.

    Every action delegates to `UserAdministration` and responds with `UserResource`.
    A super admin target returns 404.

#### 2.4 `app/Services/Superadmin/UserAdministration.php` and `app/Http/Controllers/UserController.php` (Create / Modify)

*   **Proposed Changes**:
    *   `create(array)`: sets a random password and sends the reset link. It
        records only `platform.user.created`. (Spec 025 removed the
        `hotels.owner_id` claim this used to make.)
    *   `update(User, array)`: records `platform.user.updated`.
    *   `changeAccess(User, array)`: records `platform.user.access_changed`.
        *   Setting `group_role` also sets `hotel_group_id` from `User::account()`,
            because `accessibleHotelIds()` ignores `group_role` without it. An
            account owner with no hotel yet is created with `hotel_group_id` in
            the payload.
        *   Memberships are `hotel_user` rows, with `role` taken from the user and
            `is_primary` false. It returns 422 for a hotel outside the user's
            account.
        *   A user who stops being an employee loses `staff_role_id`, as in the
            tenant `UserController` today.
    *   `sendPasswordReset(User)`: `Password::broker()->sendResetLink`. Records
        `platform.user.password_reset_sent`.
    *   `revokeAccess(User)`: calls `clearAccess` and records
        `platform.user.access_revoked`.
    *   `clearAccess(User)`: in one transaction, deletes all tokens and deletes the
        `WhatsAppDevice`. It records nothing.

    The `denySuperAdminGrant` logic moves from the tenant `UserController` to this
    service as `refusesSuperAdminGrant(?string $role): bool`, and that controller
    calls it. It now refuses `role=super_admin` from every caller, on create, update
    and access change, with 403 and the message "The super admin role cannot be
    assigned through the API." Today super admins are allowed. `denyStaffRole` moves
    here in the same way, taking plain values and returning the error, and each
    controller builds the `apiResponse`.

#### 2.4a `app/Support/Audit/EventLogger.php` (Modify)

*   **Proposed Changes**: Adds
    `recordPlatform(string $eventType, Model $subject, ?array $changes = null, ?string $reason = null, array $context = [])`.
    It writes inside `TenantContext::runForHotel(null, ...)`, so `hotel_id` stays
    null, and it resolves the actor the same way as `record()`.

#### 2.5 `app/Http/Requests/Superadmin/*` (Create)

*   **Files and fields**:

    | Request | Fields |
    | --- | --- |
    | `AccountIndexRequest` | Subclasses `GenericIndexRequest` and overrides `modelClass()`, because there is no `App\Models\Account` |
    | `UserIndexRequest` | Subclasses `GenericIndexRequest` |
    | `StoreAccountRequest`, `UpdateAccountRequest` | `name`, `slug`, `country_code`, `default_currency`, `default_timezone` |
    | `StoreHotelRequest` | `name`, `slug`, `timezone`, `currency`, `country_code`, `city`, `address`, `email`, `phone`, `whatsapp_number` |
    | `UpdateHotelRequest` | `name`, `slug`, `hotel_group_id` |
    | `StoreUserRequest` | `name`, `email`, `phone_number`, `role`, `hotel_id`, optional `hotel_group_id` and `group_role`. No `password` |
    | `UpdateUserRequest` | `name`, `email`, `phone_number`. No `password` |
    | `UpdateUserAccessRequest` | `role` (`admin` or `employee`), `group_role` (`owner` or null; spec 025 removed `group_admin`), `hotel_ids` (array), `staff_role_id` |

    `StoreUserRequest` and `UpdateUserAccessRequest` reject `role=super_admin`.

#### 2.6 `app/Http/Controllers/Auth/PasswordResetController.php` (Create)

*   **Proposed Changes**: `POST /api/reset-password` takes `token`, `email` and
    `password`, with confirmation and `Password::defaults()`. It calls
    `Password::broker()->reset` and returns 422 on failure. On success it calls
    `UserAdministration::clearAccess`, which writes no platform event, because no
    super admin acted. It is throttled with `throttle:login` and sits in the
    `api.key` group next to `/login`.

#### 2.7 `app/Providers/AppServiceProvider.php` (Modify)

*   **Proposed Changes**: Registers `ResetPassword::createUrlUsing()` with
    `config('app.frontend_url').'/reset-password?token=…&email=…'`. No custom
    notification class is needed.

#### 2.10 `app/Policies/HotelPolicy.php` and `app/Http/Controllers/HotelController.php` (Modify)

*   **Proposed Changes**: Hotel creation and deletion are removed from the tenant
    `HotelController` (`store`, `destroy`). The route uses
    `->except(['store', 'destroy', 'edit', 'create'])`. `HotelPolicy::create()`,
    `delete()`, `restore()` and `forceDelete()` are deleted.

#### 2.11 `routes/superadmin.php` and `routes/api.php` (Modify)

*   **Proposed Changes**: `routes/api.php` gets the public `POST /reset-password`.
    `routes/superadmin.php` gets these routes, all under `api/superadmin`:

    | Method | Path | Action |
    | --- | --- | --- |
    | `GET`, `POST` | `/accounts` | `AccountController` `index`, `store` |
    | `GET`, `PUT`, `DELETE` | `/accounts/{account}` | `show`, `update`, `destroy` |
    | `POST` | `/accounts/{account}/hotels` | `HotelController@store` |
    | `GET` | `/hotels` | `HotelController@index` |
    | `GET`, `PUT`, `DELETE` | `/hotels/{hotel}` | `show`, `update`, `destroy` |
    | `GET`, `POST` | `/users` | `UserController` `index`, `store` |
    | `GET`, `PUT` | `/users/{user}` | `show`, `update` |
    | `PUT` | `/users/{user}/access` | `updateAccess` |
    | `POST` | `/users/{user}/password-reset` | `sendPasswordReset` |
    | `POST` | `/users/{user}/revoke-tokens` | `revokeTokens` |

#### 2.12 `config/app.php` and `.env.example` (Modify)

*   **Proposed Changes**: Adds `frontend_url`, read from `FRONTEND_URL`.

#### 2.13 Tests (Create)

*   **Files**: `tests/Feature/Superadmin/AccountAdministrationTest.php`,
    `tests/Feature/Superadmin/HotelAdministrationTest.php`,
    `tests/Feature/Superadmin/UserAdministrationTest.php`,
    `tests/Feature/PasswordResetTest.php`.
*   **Proposed Changes**: These cover:
    *   CUJ 1 and CUJ 5, with the CUJ 1 test calling `GET /hotel/{id}` as the new
        admin;
    *   refusing a `super_admin` grant through `POST` and `PUT /api/users` for
        every caller, and through `/users/{user}/access`;
    *   refusing a membership in a hotel from another account;
    *   revoking tokens also deletes the device, and so does a completed reset;
    *   a non-super-admin gets 403;
    *   platform rows have `hotel_id = null`. `HistorySubjects` has no hotel, user
        or account type, so the test writes a row with `recordPlatform()` on a
        `Guest` subject. It asserts the row is absent from
        `GET /api/history/guest/{id}` as that hotel's admin, and that
        `EventLog::query()` under `runForHotel($hotelId)` shows no `platform.*`
        rows.

#### 2.14 Existing tests and inventory (Modify)

*   **Files**: `tests/Feature/HotelControllerTest.php`,
    `tests/Feature/CreateUserTest.php`, `tests/Feature/GenericCrudTest.php`,
    `tests/Feature/OperationalDefaultsTest.php`,
    `tests/Inventory/entry_points.php`.
*   **Proposed Changes**: Tests that create or delete a hotel through the tenant
    API move to `HotelAdministrationTest`. The tenant `POST /api/hotel` now expects
    405. `CreateUserTest:454`, "lets a super admin assign the super admin role",
    becomes a refusal, and `CreateUserTest:352` asserts the new message. The
    `POST api/hotel` and `DELETE api/hotel/{hotel}` inventory entries are removed.

#### 2.15 Documentation (Create / Modify)

*   **Create**: `docs/superadmin-accounts-api-documentation.md`, covering
    accounts, hotels, users and reset access.
*   **Modify**: `docs/hotel-api-documentation.md` (hotel creation and deletion
    removed), `docs/user-management-api-documentation.md` (`super_admin` refused
    for every caller), `docs/auth-api-documentation.md` (reset password),
    `docs/whatsapp-device-api-documentation.md` (revoking access and resetting a
    password unpair the device) and `docs/latest-changes-<date>.md`.

### Stage 3: Suspension

#### 3.1 `database/migrations/<date>_000001_add_suspension_to_hotel_groups_and_hotels.php` (Create)

*   **Proposed Changes**: Adds `suspended_at` (timestampTz, nullable,
    indexed), `suspended_by` (uuid, nullable, foreign key to `users`, null on
    delete) and `suspended_reason` (text, nullable) to `hotel_groups` and
    `hotels`.

#### 3.2 `app/Models/Hotel.php` and `app/Models/HotelGroup.php` (Modify)

*   **Proposed Changes**:
    *   Both: `suspended_at` is cast. None of `suspended_at`, `suspended_by` or
        `suspended_reason` is fillable.
    *   `HotelGroup::isSuspended()` covers the account.
    *   `Hotel::isSuspended()` covers the hotel or its group.
    *   `Hotel::scopeNotSuspended()` is
        `whereNull('hotels.suspended_at')->whereNotIn('hotel_group_id', HotelGroup::withTrashed()->whereNotNull('suspended_at')->select('id'))`.
        It uses no join, because both tables share `id` and `is_active`.

#### 3.3 `app/Services/Superadmin/Suspension.php` (Create)

*   **Proposed Changes**: `suspendAccount`, `reactivateAccount`,
    `suspendHotel` and `reactivateHotel`. Each one:
    *   sets or clears the columns;
    *   records `platform.account.suspended`, `platform.account.reactivated`,
        `platform.hotel.suspended` or `platform.hotel.reactivated` through
        `recordPlatform()`.

    Columns are written with `forceFill`, because they are not fillable. Suspending
    a record that is already suspended returns 422 and does not overwrite the
    existing values. Suspension state is not cached, so the next API request reads
    it. Dashboard counts can lag by up to 15 minutes (R10).

#### 3.3a `Superadmin\AccountController`, `Superadmin\HotelController`, their resources and `routes/superadmin.php` (Modify), `app/Http/Requests/Superadmin/SuspendRequest.php` and `HotelIndexRequest.php` (Create)

*   **Proposed Changes**:
    *   Adds `suspend` and `reactivate` to both controllers, with
        `POST /accounts/{account}/suspend`, `POST /accounts/{account}/reactivate`,
        `POST /hotels/{hotel}/suspend` and `POST /hotels/{hotel}/reactivate`. Each
        returns the updated record through its resource.
    *   `SuspendRequest` requires `reason` (string).
    *   Adds `suspended_at`, `suspended_by` and `suspended_reason` to
        `Superadmin\AccountResource` and `Superadmin\HotelResource`.
    *   Adds the virtual `suspended` filter to `AccountIndexRequest` and a new
        `HotelIndexRequest`, by overriding `filterColumns()`. The controller applies
        it. For a hotel it follows `Hotel::isSuspended()`, so it includes hotels of a
        suspended account.

#### 3.4 `app/Http/Middleware/EnsureNotSuspended.php` (Create, alias `not_suspended`)

*   **Proposed Changes**: As in Technical Plan §3.
    *   For a `null` scope, it passes the request on.
    *   It runs one query of `Hotel::whereIn('hotels.id', TenantContext::hotelIds())`,
        selecting `id`, `hotel_group_id` and `suspended_at`, with the group's
        `suspended_at` as an `addSelect` subquery using `HotelGroup::withTrashed()`.
        It uses no join. A hotel counts as suspended when it or its group is.
    *   The account is suspended when the group on the returned rows is. Only when
        no rows return does it call `User::account()`, one more query.
    *   An impersonation token on `GET` or `HEAD` passes on from here. It is detected
        with `$token instanceof PersonalAccessToken && $token->getAttribute('impersonator_id')`,
        which is null before stage 5. The stage 5 tests cover that branch.
    *   It narrows `TenantContext` with the existing `setHotelIds()` and
        `setCurrentHotelId()`.
    *   When `$user->hotel_id` is among the removed hotels, it calls
        `$request->user()->setRelation('hotel', Hotel::find($currentId))`. This covers
        both the no-header case and `X-Hotel-Id` naming an active hotel. It does not
        change the `hotel_id` attribute, which a later save would persist.
    *   It returns the suspended response when the account is suspended, when
        suspended hotels were removed and none remain, or when `X-Hotel-Id` names a
        suspended hotel. `scope` is `account` when the account is suspended, and
        `hotel` otherwise.
    *   It tags Sentry with `suspended => 'true'`, passing a string, when it blocks.

#### 3.4a Hotel list and hotel routes (Modify)

*   **Files**: `app/Enums/Permission.php`, `app/Policies/HotelPolicy.php`,
    `app/Http/Controllers/HotelController.php`,
    `docs/staff-roles-api-documentation.md`,
    `tests/Feature/PermissionAuthorizationTest.php`,
    `tests/Feature/HotelControllerTest.php`, `tests/Feature/GenericCrudTest.php`.
*   **Proposed Changes**:
    *   Adds `Permission::HOTELS_VIEW = 'hotels.view'`. It is not in
        `employeeDefaults()`. The "hotel settings are admin-only" text in the enum
        and the docs changes to name this read as the exception.
    *   `HotelPolicy` adds `use ChecksPermissions`. `viewAny` checks
        `$this->allows($user, Permission::HOTELS_VIEW)`, with no role check.
    *   `HotelController::index` adds `whereIn('id', TenantContext::hotelIds())`
        when the scope is not null.
    *   `show` and `update` run `authorize` first. They then return the suspended
        `403`, through `SuspendedResponse`, only when `$hotel->isSuspended()` is true
        and the token is not an impersonation token on `show`. A hotel the caller
        cannot reach gets the normal policy answer.
    *   The permission reference and the `PermissionAuthorizationTest` dataset get
        the endpoint: an employee is allowed with the permission and gets `403`
        without it. `HotelControllerTest:37` and `GenericCrudTest:82` now expect an
        admin to get 200.
    *   New tests: a suspended hotel returns the suspended `403` to its owner. An
        active hotel owned by a user with no membership returns 200. A stranger on
        an active hotel gets the policy `403`, without `reason = suspended`.

#### 3.5 `app/Support/Suspension/SuspendedResponse.php` (Create)

*   **Proposed Changes**: One helper that builds the `apiResponse` body (`reason`
    and `scope`) and the message for that scope, from `lang/en/suspension.php`.
    Login, `EnsureNotSuspended` and `HotelController` (3.4a) use it, so all three
    return the same shape.

#### 3.6 `bootstrap/app.php` and `routes/api.php` (Modify)

*   **Proposed Changes**:
    *   Registers the `not_suspended` alias, and
        `prependToPriorityList(SubstituteBindings::class, EnsureNotSuspended::class)`
        after the existing entries, so the order is `ResolveTenant`,
        `EnsureFeatureEnabled`, `EnsureNotSuspended`, then binding.
    *   Route structure: `/logout` sits under `tenant` alone. `GET /user` and all
        other routes nest under `not_suspended`.

#### 3.7 `app/Http/Controllers/Auth/AuthenticatedSessionController.php` and `routes/api.php` (Modify)

*   **Proposed Changes**:
    *   After `Auth::attempt` succeeds and before `createToken`, `apiLogin` skips the
        check for a super admin. Otherwise it returns `SuspendedResponse` when the
        account is suspended (`scope = account`), or when the user has at least one
        hotel and all of them are suspended (`scope = hotel`).
    *   When the user's default hotel is suspended and another is active, `apiLogin`
        sets the `hotel` relation to the first active hotel and uses `loadMissing`
        instead of `load`, which would re-fetch the suspended one.
    *   The `GET /user` closure in `routes/api.php` also uses `loadMissing`, so it
        keeps the relation set by `EnsureNotSuspended`.
    *   Tests assert `body.hotel.id` is the active hotel on both responses.

#### 3.8 `app/Jobs/Middleware/SkipIfHotelSuspended.php`, `app/Contracts/HasHotel.php` and `app/Exceptions/HotelSuspended.php` (Create)

*   **Proposed Changes**: `HasHotel` declares `hotelId(): ?string`. The middleware
    calls it. A null hotel id runs the job. If the hotel is suspended, it calls
    `$job->failed(new HotelSuspended($hotelId))` when the method exists, tags
    Sentry with `suspended=true`, logs `job.skipped_suspended`, and returns
    without calling `$next`. No `failed_jobs` row is written.

#### 3.9 Hotel jobs (Modify)

*   **Proposed Changes**: Each job implements `HasHotel` and adds
    `SkipIfHotelSuspended` to `middleware()`. A job that already declares
    middleware keeps it, with the skip first. Each lookup is unscoped:

    | Job | `hotelId()` |
    | --- | --- |
    | `CreateAiInsightsJob` | `$this->data['user']->hotel?->id` |
    | `ExtractGuestMemoriesJob` | `$this->hotelId` |
    | `GenerateActivityRecommendationsJob` | `$this->reservation->hotel_id` |
    | `IndexKnowledgeDocumentJob` | The `KnowledgeDocument`'s `hotel_id`, null for a global document. Keeps `WithoutOverlapping` |
    | `PrepareReservationImportJob`, `WriteReservationImportJob` | The `ReservationImport`'s `hotel_id` |
    | `SendGuestRequestNoticeJob` | The `Task`'s `hotel_id` |
    | `SendProactiveMessageJob` | The `ProactiveMessage`'s `hotel_id` |
    | `SendReservationVoucherEmailJob` | The `VoucherEmailRequest`'s `hotel_id` |
    | `SyncKnowledgeChunksJob` | The chunkable's `hotel_id`, null for a global article |

#### 3.10 Jobs without a single hotel (Modify)

*   **Select with `->notSuspended()`**: `EvaluateProactiveTriggersJob`,
    `ExpireRecommendationsJob`, `MatchRecommendationOutcomesJob`,
    `StartHousekeepingDayJob`.
*   **Filter their rows**:
    *   `RetryGuestMemoryExtractionsJob`:
        `whereIn('hotel_id', Hotel::notSuspended()->select('id'))`.
    *   `PurgeDeletedKnowledgeDocumentsJob`:
        `where(fn ($q) => $q->whereNull('hotel_id')->orWhereIn('hotel_id', Hotel::withTrashed()->notSuspended()->select('id')))`.
        Global documents and those of soft-deleted hotels still purge. A suspended
        hotel's data is kept.
*   **Exempt, no change**:
    *   `FlagAiCostOverrunsJob`: loops over accounts for log-only margin alerts.
    *   `RebuildUsageCountersJob`: bookkeeping must stay correct during a suspension.
    *   `PruneReservationImportPreviewsJob`: removes expired previews only.
    *   `SendWhatsAppMessageJob`: carries no hotel.

#### 3.11 `app/Jobs/ProcessInboundWhatsAppMessageJob.php` (Modify)

*   **Proposed Changes**: In `replyFor()`, after the not-paired and opt-out
    checks and before any AI run, a suspended hotel returns
    `UNAVAILABLE_REPLY` through the existing send path. This applies to guest and
    staff senders alike. The reply is sent at most once per 24 hours per hotel and
    phone number, enforced by
    `Cache::add('suspended-reply:'.$hotelId.':'.$phone, 1, 86400)`. On a repeat,
    `replyFor()` returns null, so its return type becomes `?string`, and `handle()`
    sets the existing `THROTTLED` status and sends nothing.
    `SendWhatsAppMessageJob` does not change.

#### 3.12 `lang/en/suspension.php` (Create)

*   **Proposed Changes**: Holds `suspension.account` ("This account has been
    suspended.") and `suspension.hotel` ("This hotel has been suspended."). No `ar`
    file is added, because nothing in `app/` calls `setLocale`, so it would never be
    served.

#### 3.13 Tests (Create / Modify)

*   **Create** `tests/Feature/SuspensionTest.php`: CUJ 2 and CUJ 3. Covers:
    *   login refused and the existing token refused;
    *   the `X-Hotel-Id` of a suspended hotel;
    *   a user whose default hotel is suspended, where a dashboard read, a
        policy-guarded `GET` and a create all act on the other hotel, both with no
        header and with `X-Hotel-Id` naming the active hotel;
    *   a suspended hotel's record id, requested without a header, not being
        returned;
    *   a user with no hotels not being told they are suspended, and an owner
        with no hotels in a suspended account being refused;
    *   reactivation, and tenant `PUT /api/hotel/{id}` being unable to write any
        `suspended_*` column.
*   **Create** `tests/Feature/SuspensionJobTest.php`: each hotel job is skipped
    when its hotel is suspended. An import ends failed, a proactive row stays
    `SCHEDULED`, and no `failed_jobs` row exists. Purge still removes global
    documents and those of soft-deleted hotels, and keeps a suspended hotel's. The
    WhatsApp fixed reply is sent without an AI run, a repeat within 24 hours sends
    nothing, STOP still works, and a staff sender gets the same reply.
*   **Sentry**: with a DSN configured, tests assert the scope tag
    `suspended = 'true'` on a blocked request and a blocked job.
*   Tests that make several token requests call `forgetGuards()` between them, as
    `AuthTest` does, so a cached guard cannot hide a refused token.
*   **Create** `tests/Feature/SuspensionRouteInventoryTest.php`: every
    `auth:sanctum` route, other than the super admin routes and the logout route,
    has `not_suspended`.
*   **Create** `tests/Feature/JobSuspensionInventoryTest.php`. Every class from
    `EntryPointDiscovery::queuedJobs()` must be on one of three lists: jobs with
    `SkipIfHotelSuspended`, jobs in 3.10 whose source contains `notSuspended`, or
    exempt jobs with a stated reason. The exempt list holds the four jobs in 3.10
    and `ProcessInboundWhatsAppMessageJob`.

#### 3.14 Documentation

*   **Modify**: `docs/superadmin-accounts-api-documentation.md` (suspend
    and reactivate endpoints), `docs/auth-api-documentation.md` (suspended
    login response), `docs/hotel-api-documentation.md` (the hotel list and
    `hotels.view`), `docs/whatsapp-device-api-documentation.md` (the fixed reply)
    and `docs/latest-changes-<date>.md` (the new 403 shape every client must
    handle).

### Stage 4: Dashboard

#### 4.1 `app/Services/Reports/PlatformSummary.php` and `app/Services/AiCost/AiCostReport.php` (Create)

*   **Proposed Changes**:
    *   `PlatformSummary`: `counts(from, to)`, `usage(from, to)` and
        `activity(from, to)`. Each runs under `TenantContext::withoutScope()` and
        returns plain arrays. `usage` wraps `UsageReport` (`app/Services/Metering/`)
        and maps its models to arrays. It never names the cost column, because
        `CostVisibilityTest` scans this directory.
    *   `AiCostReport::perAccount(from, to): array`: returns USD cost keyed by
        `hotel_group_id`, summing `AiUsageLog.cost_usd`.
    *   A user counts as suspended under the rule in 3.7: their account is suspended,
        or they have at least one hotel and all of them are suspended. A hotel
        counts as suspended under `Hotel::isSuspended()`.

#### 4.2 `app/Http/Controllers/Superadmin/DashboardController.php` (Create)

*   **Proposed Changes**: `counts`, `usage` and `activity`. Each wraps its work in
    `Cache::remember(…, 900)` under the key
    `superadmin:dashboard:v1:{section}:{from}:{to}`, and stores `cached_at` inside
    the closure, so it reports when the figures were computed. `usage` merges
    `PlatformSummary::usage()` with `AiCostReport::perAccount()` inside the closure.
    Responses:
    *   `counts`: `accounts`, `hotels` and `users`, each `{active, suspended}`;
        `signups`: `[{date, accounts, users}]`; `from`, `to`, `cached_at`.
    *   `usage`: `accounts`: `[{hotel_group_id, name, usage, ai, ai_cost_usd}]`;
        `from`, `to`, `cached_at`.
    *   `activity`: `hotels`: `[{hotel_id, hotel_group_id, name, reservations, bookings}]`;
        `from`, `to`, `cached_at`.

#### 4.3 `app/Http/Requests/Superadmin/SuperadminDashboardRequest.php` (Create)

*   **Proposed Changes**: `from` and `to` are optional, with `date_format:Y-m-d`,
    in UTC. They default to the 30 days ending today, resolved before the cache
    key is built. `to` must be on or after `from`, and no later than `from` plus
    12 months (`addMonths(12)`). Queries use `from` at `startOfDay` and `to` at
    `endOfDay`.

#### 4.4 `routes/superadmin.php` (Modify)

*   **Proposed Changes**: Adds `GET /dashboard/counts`, `/dashboard/usage`
    and `/dashboard/activity`.

#### 4.5 `tests/Feature/Superadmin/DashboardTest.php` (Create)

*   **Proposed Changes**: Covers CUJ 6: the counts (super admins excluded,
    suspended split), signups per day, activity per hotel across two accounts,
    the default window, a range over 12 months rejected, a cache hit on the second
    call that keeps the original `cached_at` (frozen time), and 403 for an admin.

#### 4.6 `docs/superadmin-dashboard-api-documentation.md` (Create)

*   **Proposed Changes**: Documents the three endpoints, their query
    parameters, response shapes and the 15-minute cache.

### Stage 5: Impersonation

#### 5.1 `database/migrations/<date>_000001_add_impersonator_to_personal_access_tokens.php` (Create)

*   **Proposed Changes**: Adds `impersonator_id` (uuid, nullable, foreign
    key to `users`, cascade on delete) to `personal_access_tokens`.

#### 5.2 `app/Services/Superadmin/Impersonation.php` (Create)

*   **Proposed Changes**: Both events use the target user as subject, because the
    token's bigint id does not fit the uuid `subject_id`. Both pass
    `context.impersonated_user_id` explicitly, because the end endpoint runs on the
    super admin's own token, where the actor swap in 5.4 does not apply.
    *   `start(User $superAdmin, User $target)` refuses a super admin target. It
        calls `createToken('impersonation', ['*'], now()->addMinutes(30))`, then
        `accessToken->forceFill(['impersonator_id' => ...])->save()`, because the
        column is not fillable on Sanctum's model. It records
        `platform.impersonation.started` with `context.expires_at`.
    *   `end(PersonalAccessToken)` deletes the token and records
        `platform.impersonation.ended`, with `$token->tokenable` as subject.
    *   `current(): ?PersonalAccessToken` returns the current token if it is
        an impersonation token.

#### 5.3 `app/Http/Controllers/Superadmin/ImpersonationController.php` and `routes/superadmin.php` (Create / Modify)

*   **Proposed Changes**:
    *   `POST /users/{user}/impersonate` returns `token`, `token_id` and
        `expires_at`. A super admin target returns 422.
    *   `POST /impersonation/end` takes `{token_id}`. It finds only rows with an
        `impersonator_id`, and returns 404 otherwise. Any super admin may end a
        session.

#### 5.3a `app/Http/Controllers/Auth/AuthenticatedSessionController.php` (Modify)

*   **Proposed Changes**: `apiLogout` reads the current token and deletes it. If
    the token has an `impersonator_id`, it records
    `platform.impersonation.ended`.

#### 5.3b `app/Http/Middleware/ResolveTenant.php` (Modify)

*   **Proposed Changes**: When the current token has an `impersonator_id`, the
    existing `TagsSentryScope::tag` call also adds `impersonating => 'true'`, as a
    string, and `impersonator_id`.

#### 5.4 `app/Support/Audit/EventLogger.php` (Modify)

*   **Proposed Changes**: In `record()` and `recordPlatform()`, read
    `$token = Auth::user()?->currentAccessToken()`. When
    `$token instanceof PersonalAccessToken && $token->impersonator_id`, the actor
    becomes `User::find($token->impersonator_id)`, and
    `context.impersonated_user_id` is set to `Auth::id()`. `currentActorKind()`
    stays `USER`.

#### 5.5 `app/Http/Middleware/EnsureNotImpersonating.php` (Create, alias `not_impersonating`)

*   **Proposed Changes**: Returns 403 when the current token is an impersonation
    token.
    *   `routes/api.php`: applied to `POST /connect`, and with
        `middlewareFor(['store', 'update', 'destroy'], 'not_impersonating')` on the
        `/users` and `/staff-roles` resources. Reads stay open.
    *   `bootstrap/app.php`: registers the alias, and
        `prependToPriorityList(SubstituteBindings::class, EnsureNotImpersonating::class)`.

#### 5.6 `routes/api.php` (Modify)

*   **Proposed Changes**: In the `GET /user` closure only, when the current token
    is an impersonation token, adds
    `impersonation: { impersonator: {id, name}, expires_at }`. `UserResource` does
    not change, because it also renders user lists.

#### 5.7 `routes/console.php` and `tests/Inventory/entry_points.php` (Modify)

*   **Proposed Changes**: Schedules `sanctum:prune-expired --hours=1` hourly, and
    adds exactly that string to the inventory's `scheduled` list, because
    `EntryPointInventoryTest` compares it with its arguments. It prunes every expired
    token, not only impersonation tokens.

#### 5.8 `tests/Feature/Superadmin/ImpersonationTest.php` (Create)

*   **Proposed Changes**: Covers:
    *   CUJ 4;
    *   the token works on hotel endpoints as the target user;
    *   a write is logged with the super admin as actor and
        `context.impersonated_user_id` set;
    *   the token fails after 30 minutes (time travel);
    *   `POST /connect`, and `POST`, `PUT`, `PATCH` and `DELETE` on `/users` and
        `/staff-roles`, return 403, including `PUT /users/{id}` with `password`;
    *   impersonating a super admin returns 422;
    *   in a suspended hotel, `GET` returns data and `POST` returns 403;
    *   for a target with default hotel A suspended and hotel B active, a `POST` with
        no header acts on B and leaves A untouched, a `PUT` on A's record with
        `X-Hotel-Id: B` is refused, and a `POST` in B succeeds;
    *   with a DSN configured, the scope has `impersonating = 'true'` and
        `impersonator_id`;
    *   start and end events are recorded, and logging out with the token records
        the end event with `hotel_id = null`, the super admin as actor, and
        `context.impersonated_user_id` set;
    *   the end endpoint returns 404 for a token that is not an impersonation
        token.

#### 5.10 Documentation

*   **Create**: `docs/superadmin-impersonation-api-documentation.md`.
*   **Modify**: `docs/auth-api-documentation.md` (the impersonation field
    on the user), `docs/whatsapp-device-api-documentation.md`
    (`/connect` is refused while impersonating),
    `docs/user-management-api-documentation.md` and
    `docs/staff-roles-api-documentation.md` (writes are refused while
    impersonating).

### Stage 6: Remove super admin access to hotel endpoints

**Added by spec 025**: `DELETE /api/users/{id}` is the only way to delete a user, and spec 025 kept it open to super admins for that reason. This stage must add a super admin delete route before closing it. The tenant `/api/users` endpoint already ignores `hotel_group_id` and `group_role` for every caller.

#### 6.1 `app/Http/Middleware/DenySuperAdmin.php` (Create, alias `no_super_admin`)

*   **Proposed Changes**: Returns 403 "Super admins use /api/superadmin" when
    `$request->user()->isSuperAdmin()`. Impersonation tokens belong to the
    target user, so they pass.

#### 6.2 `bootstrap/app.php` and `routes/api.php` (Modify)

*   **Proposed Changes**: Registers the alias, and
    `prependToPriorityList(SubstituteBindings::class, DenySuperAdmin::class)`, so a
    super admin gets 403 before any record is looked up. It nests all routes that
    are under `not_suspended`, except `GET /user`. `/logout` and `GET /user` stay
    reachable, so the frontend can still route a super admin to the super admin
    area.

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
*   **Proposed Changes**: The `isSuperAdmin()` short-circuit in `before()` is
    deleted. `! $model->isSuperAdmin()` in `UserPolicy::managesUser` stays. The
    superadmin knowledge controllers do not authorize through these policies, so
    they do not change.

#### 6.4 Other `isSuperAdmin()` branches (Modify)

*   **Proposed Changes**: This table is the inventory from Technical Plan §6. At the
    start of stage 6, re-run the `isSuperAdmin` and `SUPER_ADMIN` search over `app/`
    and record any change here. The Open Question on `api/admin` controllers is
    settled separately, for the moved controllers.

    | File | Decision |
    | --- | --- |
    | `app/Helpers/Helper.php` (`resolveHotel`) | Remove |
    | `app/Models/User.php` (`permissions()`) | Keep: `GET /user` and login return it, and clients build their UI from it |
    | `app/Http/Controllers/AccountUsageController.php` | Remove |
    | `app/Http/Controllers/AiActivityController.php` | Remove |
    | `app/Http/Controllers/KnowledgeBaseArticleController.php` (tenant) | Remove |
    | `app/Http/Controllers/KnowledgeDocumentController.php` (tenant) | Remove |
    | `app/Http/Controllers/UserController.php` (tenant) | Remove |
    | `app/Ai/Tools/Admin/GuardedTool.php` | Remove |
    | `app/Ai/Tools/GetReportTool.php` | Remove |
    | `app/Ai/Tools/GetStaffTool.php` | Remove |
    | `app/Http/Resources/AiActivityResource.php` | Remove |
    | `app/Http/Resources/KnowledgeDocumentResource.php` | Keep: the moved knowledge document controller renders it through `HandlesKnowledgeDocuments` |

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
    `TenantIsolationTest`, `VoucherTemplateControllerTest`,
    `KnowledgeDocumentControllerTest`, `TransactionsFeatureFlagTest`,
    `CostVisibilityTest` (all in `tests/Feature/`), plus `tests/Pest.php` helpers
    and `tests/Inventory/entry_points.php`, whose `global_data` entries cite test
    names.
*   **Proposed Changes**: The actor becomes an admin, or an impersonation
    token where the super admin path is what is being tested. Super admin
    cases that test the old behaviour become `403` assertions.

#### 6.6 `tests/Feature/SuperAdminDeniedOnTenantRoutesTest.php` (Create)

*   **Proposed Changes**: With feature flags switched on, walks every route in
    `routes/api.php` behind `auth:sanctum`, except `/logout` and `GET /user`, as a
    super admin, and asserts `403`, including with an unknown record id. Repeats
    with an impersonation token on one route and asserts it succeeds.

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
*   **Coverage**: Each new `api/superadmin` endpoint has feature tests for success
    and 403. Suspension and impersonation edge cases are those listed in 3.13 and
    5.8.
*   **Manual**: On staging, onboard an account through the API (CUJ 1).
    Suspend it and confirm the frontend shows the suspended message and
    WhatsApp sends the fixed reply (CUJ 2). Impersonate a hotel admin and
    confirm the banner and the 30-minute expiry (CUJ 4).

--------------------------------------------------------------------------------

*Drafted with EGM*
