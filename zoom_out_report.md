# Stage 5: Initial Zoom Out Report

*   **Target:** `docs/designs/super-admin-platform.md` (lines 1–1177)
*   **Reviewer:** egm-zoom-out (Skeptical Architect)
*   **Standard:** `zoom-out-standard.md`
*   **Context given:** `concepts_brief.md`, `design_draft.md`
*   **Date:** 2026-11-04

**Verdict: Needs revision.** No blockers, 7 Major, 6 Nit.

The document has enough context. The objective and motivation are clear (lines
9–59), and the overall direction is sound. Four problems need fixing before
implementation:

*   The usage and AI-cost data are built twice.
*   Two dashboard performance measures have no evidence of a performance
    problem behind them.
*   The core tenant-scope method gets rewritten to save one query.
*   The audit design conflicts with how `EventLogger` actually works.

The reviewer checked claims against `app/`, `routes/` and `bootstrap/`.

---

## Major

### 1. The `/dashboard/usage` endpoint repeats an existing one

*   **Affected scope:** lines 86–90, 214–219, 359–360, 966–974.
*   **Why:** `app/Http/Controllers/Admin/UsageController.php` already returns
    usage and AI totals per account for a `from`/`to` range. It uses the same
    `UsageReport::totals()` and `aiTotals()` calls, and adds `seats()` and the
    hotels of each account. `AiCostController` (`/ai-cost`) covers AI cost.
    After Stage 1 both will be at `/api/superadmin/usage` and
    `/api/superadmin/ai-cost`. The new section is a second, cached copy of the
    same aggregation, with different defaults: the last 30 days instead of
    month-to-date. The concept brief (line 139) already suggested linking to
    the existing endpoints.
*   **Proposed change:** Drop `GET /dashboard/usage` and
    `PlatformSummary::usage()`. The frontend dashboard calls the existing
    `/api/superadmin/usage` (and `/ai-cost`). If a 12-month cap is needed, add
    it to `UsageController`'s validation.

### 2. The 15-minute cache and the per-section split are added without a measured problem

*   **Affected scope:** lines 222–223, 355–377, 551–559 (Alternative K), 597,
    972–974, 991.
*   **Why:**
    *   The brief says the platform has fewer than 50 hotels and that the
        queries are cheap (brief line 138). The "load on a 2 GB host" reason
        (line 556) is not measured.
    *   The cache causes a problem the document admits at line 375: a super
        admin's own suspension or new account does not show for up to 15
        minutes, on the screen they use to confirm it.
    *   It also brings a versioned key scheme, a `cached_at` contract and a
        cache-hit test.
*   **Proposed change:** Serve `counts` and `activity` live from one endpoint
    (or keep two, but without caching). Add a short `Cache::remember` only
    after a measured slow query. If the author keeps the cache, at least clear
    the `counts` section in `Suspension` and `AccountController::store`.

### 3. `User::accessibleHotelIds()`, `ResolveTenant` and `TenantContext` are rewritten to save one query

*   **Affected scope:** lines 309–323, 598–603, 853–874 (3.4a, 3.4b, 3.4c).
*   **Why:**
    *   `accessibleHotelIds()` (`app/Models/User.php:223-240`) decides what
        every tenant query can see. Today it is two simple queries over three
        sources.
    *   The plan replaces it with a single joined query across group role,
        pivot and legacy `hotel_id`, adds suspension state to `TenantContext`,
        and adds a `suspensionResolved()` check.
    *   It also has to save and restore that state in `runForHotel()` and
        `withoutScope()`, although jobs use the separate job middleware and
        never read it.
    *   All of this exists only to back the "`not_suspended` adds no queries"
        claim, which saves one indexed query per request.
*   **Proposed change:**
    *   Leave `User`, `ResolveTenant` and `TenantContext` unchanged.
    *   `EnsureNotSuspended` runs one query:
        `Hotel::whereIn('id', TenantContext::hotelIds())` with the suspension
        columns and the group joined. It then narrows the context through the
        existing `setHotelIds()` and `setCurrentHotelId()`.
    *   This removes 3.4a, 3.4b and 3.4c and keeps the security-critical scope
        method as it is.

### 4. The audit convention does not match how `EventLogger` works

*   **Affected scope:** lines 104–107, 437–444, 735, 800, 837, 1012–1014.
*   **Why:**
    *   **Event names:** `EventLogger::record()`
        (`app/Support/Audit/EventLogger.php:92`) builds `event_type` as
        `snake(class_basename($subject)).'.'.$verb`. So
        `platform.account.suspended` and `platform.user.*` cannot be produced
        without changing the signature, and the plan does not list that change.
        A `HotelGroup` subject comes out as `hotel_group.suspended`.
    *   **Which hotel the row is attached to:** `resolveHotelId()`
        (`:248-255`) uses the subject's own `hotel_id`. Every `User` event for
        a hotel user, and every `Hotel` suspension event, gets a non-null
        `hotel_id`. Those rows are then visible on that hotel's tenant event
        log, which contradicts lines 105–107 and test 2.13 ("audit rows have
        `hotel_id = null`"). They may also expose `suspended_reason`.
*   **Proposed change:** Either:
    *   accept the existing naming (`hotel_group.suspended`,
        `user.access_changed`) and state which events tenants are allowed to
        see; or
    *   add one explicit option to `record()`, such as a `platform: true` flag
        that forces `hotel_id = null` and the prefix.

    Name this as a change to `EventLogger` in the plan, instead of letting it
    appear during implementation.

### 5. User events are logged twice (`RecordsEvents` plus explicit events)

*   **Affected scope:** lines 442, 735, 760–768.
*   **Why:** Adding `RecordsEvents` to `User` and `HotelGroup` already logs
    every create, update and delete (`app/Models/Concerns/RecordsEvents.php`).
    `UserAdministration` also records its own `platform.user.*` event for each
    method, so each super admin edit writes two rows. `RecordsEvents` on `User`
    also starts logging every tenant user edit and profile change. That is a
    change in scope the document does not discuss, and the trait needs an
    `eventLoggedAttributes()` allow-list the plan does not mention.
*   **Proposed change:** Pick one mechanism:
    *   explicit `EventLogger::record()` calls in the super admin services only,
        which matches how `UserController::recordStaffRoleAssignment` already
        works; or
    *   `RecordsEvents` with a stated allow-list and no explicit calls.

### 6. The super admin `HotelController` repeats tenant hotel logic

*   **Affected scope:** lines 265–270, 706–711, 770–775.
*   **Why:**
    *   The tenant `HotelController::store` restores a soft-deleted hotel with
        the same slug.
    *   Its `update` validates housekeeping defaults
        (`invalidHousekeepingDefaults`) and merges `proactive_settings`.
    *   A new `Superadmin\HotelController::update` that "also" edits hotels
        either copies this logic or skips it.
    *   With impersonation, a super admin can already make operational edits
        as the hotel admin.
*   **Proposed change:**
    *   Limit the super admin hotel `update` to platform fields (`name`,
        `slug`, `hotel_group_id`), or drop it for v1.
    *   Move the tenant `store` body, including the trashed-slug restore, into
        a small service (for example `HotelProvisioning`) that the super admin
        `store` calls, instead of writing creation again.

### 7. The attribution claim does not hold for queued work

*   **Affected scope:** lines 401–404, 1023–1028.
*   **Why:** "Every model write that is already audited gets this without
    changing its caller" is true only inside the HTTP request. Writes from
    jobs dispatched during impersonation go through `EventLogger::actingAs(...)`
    or `asAiAgent(...)` with the token's user. Examples are the reservation
    import (`Prepare`/`WriteReservationImportJob`) and AI runs. Those writes
    are recorded as the hotel user, which is exactly problem 4 (lines 49–53)
    that the module is meant to solve.
*   **Proposed change:** Either state this limit as accepted for v1, or block
    job-dispatching write endpoints (imports) for impersonation tokens in the
    same `not_impersonating` list. Do not build impersonator propagation into
    jobs.

---

## Nit

### 8. The job list names jobs that do not carry a hotel

*   **Affected scope:** lines 906–908, 916–917.
*   **Why:**
    *   `app/Jobs/AiCost/` contains only `FlagAiCostOverrunsJob`, which loops
        over accounts with no hotel context.
    *   `app/Jobs/Metering/` contains only `RebuildUsageCountersJob`, which has
        no hotel reference. Skipping metering rebuilds for suspended hotels
        would also leave the usage bookkeeping wrong.
    *   Hotel jobs missing from the list include
        `RetryGuestMemoryExtractionsJob` and `PurgeDeletedKnowledgeDocumentsJob`.
*   **Proposed change:** Remove the AiCost and Metering wording. Rely on the
    inventory test (3.13) to find the full list, and state that metering and
    cost jobs run regardless of suspension.

### 9. The same suspension route check is enforced twice

*   **Affected scope:** lines 333–334, 943–953.
*   **Why:** `SuspensionRouteInventoryTest` and a new "suspension-gated"
    column in `EntryPointInventoryTest` check the same thing.
*   **Proposed change:** Keep one. The new standalone route test is simpler;
    drop the inventory column, or the reverse.

### 10. The super admin grant guard is checked twice, and the shared service shares the wrong thing

*   **Affected scope:** lines 275–277, 727–737, 744–746.
*   **Why:**
    *   The current `denySuperAdminGrant` (`UserController.php:164-171`)
        depends on who is asking: it denies unless the requester is a super
        admin. The super admin side needs a different rule: always deny. After
        Stage 6 the tenant rule reduces to the same "always deny".
    *   The plan checks it in both `UpdateUserAccessRequest` and
        `changeAccess`.
    *   The logic that is actually shared is `denyStaffRole` and the team and
        hotel ownership checks.
*   **Proposed change:** Express "never `super_admin`" as a validation rule
    (`Rule::notIn`) in both request classes. Share only the staff-role and
    membership checks through `UserAdministration`.

### 11. Custom password-reset pieces where the framework already provides them

*   **Affected scope:** lines 755–763.
*   **Why:** Laravel's built-in `ResetPassword` notification supports
    `ResetPassword::createUrlUsing()`. That makes the custom
    `ResetPasswordNotification` class and the `sendPasswordResetNotification()`
    override unnecessary. No `Password::` usage or `frontend_url` setting exists
    in the code today.
*   **Proposed change:** Register `createUrlUsing` once in
    `AppServiceProvider` with `config('app.frontend_url')`, and drop 2.7 and
    the override in 2.8.

### 12. Small extra pieces of code

*   **Affected scope:** 876–880 (`SuspendedResponse`), 739–746 (five new
    FormRequests), 767–768 ("`hotels()` count helper, if missing"), 910–911
    (`SendWhatsAppMessageJob` skipping its own middleware for one message type).
*   **Why:**
    *   **`SuspendedResponse`:** a class for one `apiResponse` call used in two
        places.
    *   **FormRequests:** the codebase derives validation from the schema
        through `GenericStoreRequest` / `GenericUpdateRequest`, but the plan
        adds custom requests without saying why.
    *   **`hotels()` count helper:** `HotelGroup::hotels()` already exists, so
        `withCount('hotels')` is enough.
    *   **`SendWhatsAppMessageJob` exception:** the job is made to check its own
        message type.
*   **Proposed change:**
    *   Make `SuspendedResponse` a static method on `EnsureNotSuspended`.
    *   Use the Generic requests plus a `notIn` rule unless a gap is shown.
    *   Delete the helper line.
    *   Send the fixed reply through a separate send path, or say why the
        exception is cheaper.

### 13. Renaming the controller namespace adds churn for naming only

*   **Affected scope:** lines 251–253, 640–646, 669–671.
*   **Why:** Moving `App\Http\Controllers\Admin` to `Superadmin` and renaming
    `tests/Feature/Admin/` only changes names, inside an already breaking
    stage. The naming is also inconsistent with existing and new classes
    (`EnsureSuperAdmin`, `SuperAdminDeniedOnTenantRoutesTest`).
*   **Proposed change:** Either keep the namespace and change only the URL
    prefix, or choose one spelling (`SuperAdmin`) throughout.

---

## Reuse confirmed (keep as written)

*   `HotelGroupResource`, `HotelResource`, `UserResource`,
    `GenericIndexRequest` + `GenericQuery`, `TenantContext::withoutScope()`,
    `UsageReport`, and Sanctum's `expires_at`.
*   Sentry tagging should reuse `App\Support\Observability\TagsSentryScope`,
    which `ResolveTenant` already uses. Say so explicitly at lines 350–351 and
    851.

## Files the reviewer checked

*   `docs/designs/super-admin-platform.md`
*   `concepts_brief.md`
*   `app/Http/Controllers/Admin/UsageController.php`
*   `app/Http/Controllers/UserController.php`
*   `app/Http/Controllers/HotelController.php`
*   `app/Support/Audit/EventLogger.php`
*   `app/Models/Concerns/RecordsEvents.php`
*   `app/Models/User.php`
*   `app/Http/Middleware/ResolveTenant.php`
*   `app/Jobs/AiCost/FlagAiCostOverrunsJob.php`
*   `app/Jobs/Metering/RebuildUsageCountersJob.php`
*   `routes/admin.php`
