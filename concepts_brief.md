# Super Admin Platform Module (v1)

- **Author:** Abdalrahman-Abdallah-Ahmed
- **Date:** 2026-11-04

## Overview

The module gives the internal super admin team its own area of the API under `api/superadmin`. It has four parts:

- a cross-hotel dashboard;
- administration of accounts (HotelGroup), hotels and users, including suspension;
- impersonation of hotel staff;
- audit through the existing `EventLog`.

It also takes away the super admin's current unrestricted access to hotel endpoints. After this, impersonation is the only way a super admin reaches hotel operational screens.

Six reviewer lenses looked at the concept against the codebase: design, codebase details, zoom-out, comprehension, implementation readiness and structural. All six completed. They agree on the direction. They also agree that four parts of the concept are under-specified or rest on assumptions the code does not support. Those four are covered in Analysis, ordered by risk.

## Analysis

### Risks (highest first)

**R1. Blocker: Impersonation as specified hides what super admins do.**
- `EventLogger` takes the actor from `Auth::user()` (`app/Support/Audit/EventLogger.php:100`). Its context only records ip, route, queue and ai (`:260-281`).
- If a super admin is given a Sanctum token for the target user, every write is logged as that hotel user. Reusing EventLog without changes would therefore hide super admin actions.
- `personal_access_tokens` has no column for the impersonator.
- "No time limit" is not what would actually happen. Sanctum already expires tokens after 7 days (`config/sanctum.php:56`).
- An impersonating session could call `WhatsAppController::connect()` (`:38`), which issues a token for the current user. That would pair the super admin's phone as the hotel admin's device, and the access would last after impersonation ends.
- Open questions with no answer yet:
  - Can a super admin impersonate a user in a suspended hotel, and can they write there?
  - What happens to the impersonation token when the target's password or role changes?

**R2. Blocker: Removing unrestricted tenant access touches about 40 files and much of the test suite.**
- `User::accessibleHotelIds()` returns `null` for super admins (`app/Models/User.php:223-227`), and `ResolveTenant` relies on that.
- About 25 policies short-circuit with `before(): isSuperAdmin() ? true : null` (for example `ReservationPolicy.php:17-20`).
- About 20 more `isSuperAdmin()` branches exist in:
  - `resolveHotel()` (`Helper.php:60-66`) and `User::permissions()`;
  - `UserController`, `KnowledgeBaseArticleController`, `KnowledgeDocumentController`, `AiActivityController` and `AccountUsageController`;
  - `GuardedTool`, `GetStaffTool` and `GetReportTool`;
  - two Resources.
- About 33 test files use a super admin as an operational actor.
- `HotelPolicy::create()` returns `false` for everyone (`HotelPolicy.php:37`). Hotel creation works today only through the super admin `before()` bypass, so removing the bypass early makes hotel onboarding impossible.
- If `accessibleHotelIds()` returned `[]` for super admins, the `tenant` middleware on the super admin stack (`bootstrap/app.php:36`) would scope every platform query to zero hotels. The queries would return nothing, without any error.
- Reviewers disagreed on how much the existing `api/admin` controllers depend on the `null` scope.
  - Codebase details found that most use `withoutGlobalScope('hotel')` or read non-tenant models. `KnowledgeRebuildController` is the exception.
  - Zoom-out and implementation readiness say the dependence is wider.
  - Resolve this per controller before changing anything.

**R3. Blocker: Suspension cannot reuse `is_active`, and it has no single place to enforce it.**
- Hotel admins can write `hotels.is_active` today. It is in `Hotel::$fillable` (`Hotel.php:41`), and `GenericUpdateRequest` derives its rules from fillable columns. A suspended hotel's admin could therefore reactivate it through `PUT /api/hotel/{id}`.
- `is_active` already has an operational meaning. Jobs skip hotels where it is false: `ExpireRecommendationsJob:39`, `EvaluateProactiveTriggersJob:53` and `StartHousekeepingDayJob:40`.
- `HotelGroup.is_active` is not read anywhere in `app/`.
- Writes come from many places:
  - HTTP routes;
  - about 20 jobs;
  - the WhatsApp webhook (`WhatsAppController::handleInboundMessage`, which resolves the hotel without the tenant middleware);
  - AI write tools (`WritesData` through `GuardedTool`/`AuditedTool`).
- Staff of a suspended hotel could still write through the Admin Advisor over WhatsApp (`ProcessInboundWhatsAppMessageJob.php:358-371`).
- Login (`AuthenticatedSessionController.php:39-44`) does no status check.
- Jobs queued before a suspension will still run unless `handle()` checks again.

**R4. Resolved by the author (iteration 1): the health, failures and revenue sections are dropped from the dashboard.** Failures and WhatsApp health are already monitored in Sentry, so the dashboard does not duplicate them. Revenue is deferred, so currency conversion is not needed. The reviewer findings below are kept for the record.

*Original finding (major): some dashboard signals do not exist in the form the concept assumes.*
- **WhatsApp connection per hotel.** There is no per-hotel connection. WhatsApp is one platform-wide number from env (`config/services.php:39-44`). Hotels hold only a free-text `whatsapp_number`, and `WhatsAppDevice` rows are per-user pairings. A health signal would have to come from `whatsapp_inbound_messages.status` per hotel, from device presence, or from both.
- **Failed jobs.** `failed_jobs` has no `hotel_id`. Attributing failures to a hotel means parsing JSON payloads, which is a sequential scan that keeps growing.
- **Revenue per hotel.** Each hotel has its own `currency` (`Hotel.php:32`), so summing revenue across hotels gives a wrong figure.
- **"Live".** The term is undefined. The existing tenant dashboard already caches (`DashboardController::CACHE_SECONDS`).
- **Current dashboard behaviour.** The concept's description is inaccurate. A super admin with a home hotel gets cross-hotel task, arrival and VIP counts mixed with single-hotel occupancy, because the cache key is `all` and `DashboardSummary` relies on the tenant scope.

**R5. Major: Audit rows for account-level actions are not visible to scoped readers.**
- `EventLog` uses `BelongsToHotel`, and `hotel_id` is nullable.
- Account-level and user-level events get `hotel_id = null`.
- `subject` is required, and `event_type` is built from the class name.
- `HotelGroup` and `User` do not use `RecordsEvents`.
- Rows with no hotel hold platform data (for example contract value). They must never appear on tenant event-log endpoints.

**R6. Major: Reset access is mostly unbuilt.**
- No password broker or mail flow is wired. Laravel's `Password::sendResetLink` is available but not configured.
- No bulk `tokens()->delete()` path exists.
- Revoking tokens would also delete WhatsApp pairing tokens (`WhatsAppDevice::PAIRING_TOKEN_NAME`), but a paired device keeps AI access. "Reset access" has to include unpairing the device.

**R7. Major: The breaking route move couples the frontend and backend deploys.**
- `api/admin` appears in about 50 files (tests, docs and code comments).
- Route classification in the tests uses middleware, not the URL prefix (`tests/Pest.php:1928`, `tests/Support/EntryPointDiscovery.php:36`). If the `super_admin` alias stays, `CrossTenantRouteTest` and `EntryPointInventoryTest` keep working.
- Rolling back is a pure code revert, but only if the move ships separately from schema changes.

### What already exists and can be reused

- **Hotel CRUD:** `HotelController` and `HotelPolicy`. The rules move to the superadmin side.
- **User administration:** `UserController`, with super admin creation via `hotel_group_id`/`group_role`, role changes, the `denySuperAdminGrant` guard and staff-role audit.
- **Account data:** `HotelGroupResource` exists. HotelGroup CRUD is the only entity endpoint that is genuinely missing.
- **Usage and AI cost:** `UsageController`, `AiCostController` and `MeteringService`, plus the account loop in `FlagAiCostOverrunsJob`.
- **Failure sources:** `ImportStatus` (`ReservationImport`), `GuestMemoryExtractionStatus`, `AlertOnRepeatedJobFailures` and `PlatformAlerts`.
- **Navigation:** `Hotel::admins()` and `User::account()` for moving between account, hotel and user.
- **Audit:** `EventLogger::record()` with `$context`, and `actingAs()` (`EventLogger.php:216`), which already supports "a human on whose behalf something acts". Also `ActorKind`.
- **Route guard:** the `then:` registration block in `bootstrap/app.php:35-39` can be copied as is.

### Terms to settle before the spec

- **"Account".** Use HotelGroup everywhere, in URLs and in docs.
- **"Admin users".** Does it cover only `role=admin`, or also `group_role` users and employees?
- **"Role change".** Which of these does it touch: `role`, `group_role`, `staff_role_id` or the `hotel_user` pivot? It must never grant `super_admin`.
- **"Read-only".** Are logout, the user's own password change and profile update exempt?
- **Response for a suspended write.** A machine-readable 403 body, for example `{message:"suspended", code:403, body:{scope:"account"|"hotel"}}`, so the frontend can tell it apart from a permission 403.
- **Users with several hotels.** Which hotel decides the suspension check for a user who spans hotels (legacy `hotel_id`, the pivot, or the group role)?
- **"Inactive" in the counts.** Does it mean suspended, or dormant?

## Goals vs. Non-Goals

**Goals (v1)**
- A super admin route area with the guard applied to the whole file, as `routes/admin.php` has today.
- Onboarding an account, its hotels and its first admin through the API, with no SQL.
- A super admin dashboard with platform counts (accounts, hotels, users, active vs suspended, signups over time), a usage and AI cost summary, and activity per hotel (reservation and booking counts, no revenue).
- Failing hotels are spotted through Sentry, not the dashboard. Suspension and impersonation add `suspended` and `impersonating` tags to Sentry events.
- Suspension and reactivation at account and hotel level. Data is kept, and one rule decides whether a hotel is suspended.
- Bounded, attributable impersonation of hotel admins and employees.
- Every super admin write and every impersonation start and end recorded in `EventLog`, with the real actor identifiable.
- Super admins without impersonation get 403 on hotel operational routes.

**Non-Goals (v1)**
- Plans and limits, feature flags per hotel or account, and announcements.
- An audit UI. `EventLogPolicy` exists, so a filtered index can be added later.
- Super admin tiers. Super admins are still provisioned by seeding or CLI, not through the API.
- A health and failures section on the dashboard (failed jobs, failed imports, failed memory extractions, WhatsApp health). Sentry covers these.
- Revenue on the dashboard, in any form, including conversion between currencies.
- Snapshot or aggregate tables, websockets and SSE.

## Alternatives Weighed

| Topic | Option A | Option B | Lens consensus |
| --- | --- | --- | --- |
| How super admins lose hotel access | Edge block: keep `accessibleHotelIds()` as `null`, and add middleware to the `routes/api.php` tenant group that returns 403 to a super admin who is not impersonating. Remove the policy and helper bypasses later. | Change the tenant scope so super admins get `[]` on tenant routes, and give the superadmin stack its own `platform` resolver instead of `tenant`. | A. It reaches the goal without touching about 40 files at once, and it keeps the `null` / `[]` distinction intact. B can follow as cleanup once the inventory of bypasses is done. |
| Impersonation mechanism | Issue a Sanctum token to the target user, with a new `personal_access_tokens.impersonator_id` and a required `expires_at`. | Keep the super admin token and add an `X-Act-As` header. | A. Policies, `ResolveTenant` and `User::permissions()` work unchanged. B would affect every `Auth::user()` read. |
| Impersonation lifetime | A short fixed expiry (for example 60 minutes) plus an explicit end endpoint. | No limit, which in practice means Sanctum's 7-day default. | A. Leaving it open is the most serious unresolved risk. |
| Suspension flag | New `suspended_at`, `suspended_reason` and `suspended_by` on `hotels` and `hotel_groups`, not fillable, written only by the superadmin service. | Reuse `is_active`. | A. `is_active` is writable by hotel admins and already means "operationally off" to housekeeping and proactive jobs. |
| Suspension depth | Read-only for staff. | Full block: no login, no API, no AI, no jobs. | **Author chose B in iteration 2.** Read-only was rejected because it needs a check on every write path and a list of exemptions. Zoom-out had suggested the full block as simpler. |
| Dashboard freshness | Per-request queries with a 60-second `Cache::remember` per section, split into one endpoint per section. | Purely per request, or snapshot tables. | A. Fewer than 50 hotels makes the queries cheap, but a short cache and per-section endpoints keep one slow widget from blocking the page on a 2 GB host. |
| Dashboard breadth | All four metric families. | Counts and a failing-hotels list only; link to the existing usage and AI cost endpoints. Defer signups and revenue. | Zoom-out recommends B. Everyone else accepts A if revenue is grouped by currency and every query is limited to a date window. This is the author's call. |
| Route move | Hard cut, documented in `docs/latest-changes-<date>.md`. | Register the same file under `api/admin` (deprecated) and `api/superadmin` for one release. | Design and structural recommend B to separate the two deploys. Zoom-out questions whether the rename adds value at all. If A is kept, record why B was rejected. |
| Account-level audit rows | `hotel_id = null` with a `platform.*` event_type prefix, written under `withoutScope`, plus a test that tenant readers never see them. | Attribute to a single hotel. | A. Account-level actions have no single hotel. |

## Key Decisions & Staging for Next Steps

### Decisions taken by the author (iteration 1)

- **D1/D2 Impersonation.** A Sanctum token is issued to the target user with `impersonator_id` and a short expiry (30–60 minutes). EventLog records both the target user and the real super admin. WhatsApp pairing and password or role changes are blocked during impersonation.
- **R2/D7 Bypass removal.** This is the last stage of v1, after administration and impersonation have shipped.
- **D4 Suspension storage.** New `suspended_at`, `suspended_by` and `suspended_reason` columns on `hotels` and `hotel_groups`. Only `api/superadmin` can write them. `is_active` is not reused.
- **D8 Dashboard scope.** No health and failures section; Sentry covers it. No revenue in v1. Remaining sections: platform counts, usage and AI cost, and activity counts per hotel.
- **D9 Route file.** A new `routes/superadmin.php` under `api/superadmin`. Every existing `api/admin` route moves there in a **hard cut**: no alias, the frontend and backend deploy together, and the change is documented in `docs/latest-changes-<date>.md`. The one-release alias was rejected by the author because both apps are released together.
- **D10 Reset access.** Covers two things: a password reset email (wire Laravel's password broker and mail) and revoking all of the user's tokens. A temporary password set by the super admin was rejected. "Revoke all tokens" **also unpairs the user's WhatsApp device**, so the user loses Admin Advisor access at the same time (closes the gap in R6). There is no separate unpair action.
- **D5 Suspension means a full stop (iteration 2, replaces "read-only").** Suspending an account or hotel blocks everything:
  - **Login is blocked.** A login attempt returns a "suspended" response, for example `{message: "Your account has been suspended.", code: 403, body: {reason: "suspended", scope: "account"|"hotel"}}`, and the frontend shows that message.
  - **Existing sessions end.** Tokens already issued are rejected on the next request with the same response, so a logged-in user is sent to the suspended message.
  - **No writes and no reads** for the hotel's staff. There are no exemptions, so the old exemption list (logout, guest check-out and cancel) is removed.
  - **The guest WhatsApp concierge and the Admin Advisor stop.** A guest who messages a suspended hotel gets a fixed reply that is not AI-generated, such as "This service is unavailable, please contact the hotel directly." The message itself is still stored.
  - **Users with several hotels.** If only one of a user's hotels is suspended, the user can still log in and sees only their active hotels. Requests for the suspended hotel return the suspended response. If the whole account is suspended, login is blocked.
  - **Every scheduled and queued job for the hotel stops.**
  - **Data is kept**, and reactivation restores access.
  - This makes enforcement simpler than read-only. Suspension becomes one check at login plus one in the token or tenant middleware, instead of a check on every write path.
- **User scope.** The super admin user list and create flow cover every user who is not a super admin: account owners (`group_role`), hotel admins and employees. The list can be filtered by account, hotel and role.
- **Role change.** A super admin may change `role` (admin or employee), `group_role`, hotel membership (the `hotel_user` pivot, limited to hotels in the user's own account) and `staff_role_id`. It can never grant `super_admin`; the existing `denySuperAdminGrant` guard stays.
- **Impersonation in a suspended hotel.** Allowed, read-only, for support only. This is the only way into a suspended hotel. The hotel's own staff are blocked entirely. The suspension middleware lets an impersonation token through only for safe methods (GET/HEAD); every other request returns the suspended response.
- **Dashboard freshness (changed during drafting).** Each section is cached for 15 minutes. This replaces "live", to keep load off the database.
- **Dashboard date window.** 30 days by default. `from` and `to` query parameters choose another range, capped at 12 months. This applies to activity per hotel and signups over time.
- **D6 Queued work at suspension (iteration 2).** Every hotel job checks suspension again in `handle()` and exits, guest-facing ones included. This replaces "drop AI, keep guest-facing".

### Decisions needed before writing the spec

| ID | Decision | Recommended default |
| --- | --- | --- |
| D1 | Impersonation expiry and end | 60 minutes, an end endpoint that revokes the token, and a "who am I impersonating" endpoint for the frontend banner. |
| D2 | Impersonation attribution | `impersonator_id` on the token. EventLogger stamps `context.impersonator_id`, or uses a new `ActorKind` case. Start and end events carry the target `hotel_id`. |
| D3 | What impersonation tokens may do | They cannot pair WhatsApp devices, change passwords, create tokens, or target super admins and soft-deleted users. In a suspended hotel they are read-only. |
| D4 | Suspension storage and rule | Use `suspended_*` columns. A hotel is suspended if the hotel or its group is suspended, decided by one method such as `Hotel::isSuspended()`. |
| D5 | Suspension enforcement points | 1. Tenant middleware: 403 "suspended" on non-safe methods, with a fixed list of exempt routes. 2. A check in `ProcessInboundWhatsAppMessageJob` before any agent turn. 3. One job middleware or guard that checks again in `handle()`. 4. AI write tools inherit the check. Each skip is logged or counted, and Sentry gets `suspended` and `impersonating` tags. |
| D6 | Queued work at the moment of suspension | Decided: every hotel job exits (see above). |
| D7 | Where hotel creation and user administration live after the bypasses go | Superadmin endpoints. This must ship before the bypasses are removed. |
| D8 | Dashboard definitions | Cache TTL; date windows; timezone; revenue per currency; WhatsApp health taken from inbound message status; failed jobs reported globally; a threshold per signal and a health enum per hotel. |
| D9 | Route move strategy | Hard cut or a one-release alias. Either way, update CLAUDE.md "Request flow" and the docblock in `bootstrap/app.php`. |
| D10 | Scope of "reset access" | Password reset (wire the broker and mail), revoke all tokens, optionally unpair WhatsApp, and change role (never to `super_admin`). |

### Staging (separate spec folders, in this order)

1. **Route move.** Move `routes/admin.php` to `routes/superadmin.php` with the guard applied to the whole file, and move the controller namespace. Update the three admin docs, CLAUDE.md and `bootstrap/app.php`, and add a latest-changes note. There is no schema change, so a plain revert rolls it back.
2. **Account, hotel and user administration plus reset access.** HotelGroup CRUD, hotel creation and user administration on the superadmin side, and reset access. Also settles the convention for platform audit rows (R5).
3. **Suspension.** Migration, the suspension rule, the HTTP, webhook, job and AI enforcement points, and an inventory column ("suspension-gated") enforced by `EntryPointInventoryTest`, the same way it enforces `scope`.
4. **Dashboard.** Endpoints for counts, usage and AI cost, and activity per hotel. Depends on 1, and on 3 for the active vs suspended counts.
5. **Impersonation.** Token migration, attribution, token restrictions, and the start, end and status endpoints.
6. **Removing super admin bypasses.** Keep this last. First inventory all the `isSuperAdmin` / `SUPER_ADMIN` files in `app/` and record a decision for each. Then add the edge 403 and a generic test that walks every `api.php` route as a super admin and expects 403. Then rewrite the super admin test suites to use admins or impersonation tokens. This must not ship before stages 2 and 5.

### Documentation each stage owes (per CLAUDE.md)

- **New docs:** superadmin dashboard, account administration and impersonation API docs.
- **Updated docs:** usage-metering, ai-cost-attribution, global-knowledge-admin, user-management, dashboard, auth and WhatsApp device docs.
- **Breaking-change notes:** `latest-changes-<date>.md` for stages 1 and 6 at minimum.
- **Staff-roles docs:** a note that these endpoints are admin-only and add no `Permission` cases.
- **Spec template:** the spec-kit template has no Alternatives or Risks sections, so add both to each spec. Each requirement should cite its decision ID from the table above.
