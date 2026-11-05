# Goldfish Annotated Review Report

Status: resolved

**Outcome (2026-11-05):** the author chose auto accept, with the recommended D1-D10
and `hotels.view` for D2. All 50 proposed fixes are applied. The CONTEST inside BK5
is upheld: `hotel_id` is not changed in memory. N6 and N10 are declined.

**Changes from the text below, made while applying:**

*   M12: a repeat fixed reply uses the existing `THROTTLED` status, so no new
    `InboundMessageStatus` case is added.
*   M13: `PurgeDeletedKnowledgeDocumentsJob` filters hotel documents; it does not
    skip whole hotels.

**Target:** `docs/designs/super-admin-platform.md` (lines 762–1325, DetailedRange,
plus the spec lines named below)
**Source:** `implementation_readiness_report.md` (Stage 9, round 1). The IDs and
full descriptions are there. The Stage 7 report is now
`goldfish_review_report_stage7.md`.

**Summary:** 16 Blockers, 25 Majors, 11 Nits.

| Suggestion | Count | Items |
| --- | --- | --- |
| PROPOSE FIX | 50 | All Blockers and Majors, and N1–N5, N7–N9, N11 |
| CONTEST (part of one finding) | 1 | Inside BK5 |
| ESCALATE | 0 | The author decisions are listed separately below |
| DECLINE | 2 | N6 (recorded as an accepted risk), N10 |

## Decisions needed first

Most proposed edits depend on these, so they come before the item list. **"Auto
accept" means accepting every recommendation below.**

| # | Decision | Recommendation | If you choose otherwise |
| --- | --- | --- | --- |
| D1 | How a user works in hotel B when default hotel A is suspended | `not_suspended` calls `setRelation('hotel', $newCurrentHotel)` on the user | Drop the "works in B" line from CUJ 3. The user is then blocked until an admin changes their default hotel |
| D2 | Who can list hotels with `GET /api/hotel` | Whoever holds a new `hotels.view` permission, limited to their own hotels. Admins hold it automatically. Employees get it only through a staff role, and it is not in `employeeDefaults()`. This changes today's rule that hotel settings are admin-only, because the list returns `HotelResource` | Keep `/api/hotel` admin-only. CUJ 3's hotel list then applies to admins only, and an employee with two hotels needs another source |
| D3 | Can a hotel be deleted after stage 6 | `Superadmin\HotelController::destroy` exists; the tenant route loses `destroy` | State that hotels cannot be deleted in v1 |
| D4 | Do account owners see a suspended hotel in account-level views | Hidden, the same as for other users | The owner's view needs its own rule |
| D5 | Fixed reply wording and language | Reuse `UNAVAILABLE_REPLY`: "Sorry, I can't answer messages right now. Please contact the hotel directly for assistance." One English text for the whole platform | Give the text and language rule |
| D6 | How the first admin gets a password in CUJ 1 | Random password plus `sendPasswordReset`, so the super admin never knows it | The super admin sets it and knows the credential, which Alternative M rejected |
| D7 | Six queued notifications when a hotel is suspended | Accept for v1 and record as a risk. They may still send | Add a gate to each, and extend the inventory test to `app/Notifications` |
| D8 | Do the moved knowledge and memory controllers write audit rows | Narrow Goal 9 to new super admin writes in v1 | Add `recordPlatform()` calls to those controllers in a stated stage |
| D9 | Does STOP/opt-out still work for a suspended hotel | Yes: the suspension check goes after the existing not-paired and opt-out checks in `replyFor()` | Opt-outs are ignored during a suspension |
| D10 | **New.** Keep the `accessibleHotels()` rewrite, or simplify it (zoom-out 3) | **Simplify.** Leave `User`, `ResolveTenant` and `TenantContext` unchanged. `EnsureNotSuspended` runs one query of the user's hotels with their suspension state, and skips super admins. This removes M16 and M17 and plan items 3.4a–3.4c, and the "adds no queries" NFR is dropped | Keep the rewrite, and apply the M16 and M17 fixes instead |

**Why D10 changes the picture:** two Stage 9 Majors, and most of the risk in plan
item 3.4a, exist only because the design rewrites the method that decides what
every tenant query can see, to save one query. BK5 already needs an exception to
"adds no queries".

## Code facts these fixes rely on (checked 2026-11-05)

*   `$user->hotel` is read in 18 places, and `resolveHotel()` is called in 28.
    `resolveHotel()` returns `$user->hotel`, and `ChecksPermissions::allows` reads
    it too, so `setRelation('hotel', ...)` covers all of them.
*   `hotels.hotel_group_id` is `NOT NULL`, so a `whereNotIn` subquery cannot drop
    rows because of nulls.
*   `addToMiddlewarePriorityBefore` splices each entry directly before
    `SubstituteBindings`, so later entries run later. The order is `ResolveTenant`,
    `EnsureFeatureEnabled`, then whatever is added next.
*   `middlewareFor()` exists on resource routes in the installed framework.
*   `replyFor()` runs the not-paired check, then opt-out, then the AI.
*   `UsageReport` is in `app/Services/Metering/`. `app/Services/AiCost/` is on the
    `CostVisibilityTest` allowlist.
*   `User::account()` exists and resolves the account from `hotel_group_id` or
    `hotel_id`.

---

## Prioritized Findings & AI Suggestions

Each item's description and rationale are in
`implementation_readiness_report.md` under the same ID.

### Blockers

| ID | Suggestion | Proposed edit |
| --- | --- | --- |
| BK1 | PROPOSE FIX | New Stage 2 item, `app/Support/Audit/EventLogger.php` (Modify): `recordPlatform(string $eventType, Model $subject, ?array $changes = null, ?string $reason = null, array $context = [])`. It writes inside `TenantContext::runForHotel(null, ...)` and resolves the actor as `record()` does. 2.1, 2.2 and 2.4 name `platform.account.*`, `platform.hotel.*` and `platform.user.*`. |
| BK2 | PROPOSE FIX | 2.8 and 2.9: delete "Adds `RecordsEvents`". 2.9: delete the count helper. 2.1 uses `withCount('hotels')`. |
| BK3 | PROPOSE FIX | 3.8: "If the hotel is suspended, it calls `$job->failed(new HotelSuspended($hotelId))` when the method exists, logs `job.skipped_suspended`, and returns without calling `$next`. No `failed_jobs` row is written." Add `app/Exceptions/HotelSuspended.php`. Add a test that an import ends failed, and no `failed_jobs` row exists. |
| BK4 | PROPOSE FIX | New Stage 3 item: `suspend` and `reactivate` on `Superadmin\AccountController` and `Superadmin\HotelController`, `POST /accounts/{account}/suspend`, `/reactivate`, `POST /hotels/{hotel}/suspend`, `/reactivate`, `SuspendRequest` (`reason` required), and `routes/superadmin.php` (Modify). |
| BK5 | PROPOSE FIX, with one CONTEST | 3.4: when the current hotel is replaced, `$request->user()->setRelation('hotel', Hotel::find($id))`. Add tests for a dashboard read, a policy-guarded `GET` and a create, all acting on B. **CONTEST (part):** the reviewer also suggests setting `hotel_id` in memory, because `UserController:119` reads it. That line reads the *target* user, not the requester. `ResolveTenant` is the only other reader. Setting it would risk saving the wrong hotel if the user is later saved. Do not set it. |
| BK6 | PROPOSE FIX (D2, D3) | Stage 3 item: add `Permission::HOTELS_VIEW` (`hotels.view`), not in `employeeDefaults()`. `HotelPolicy::viewAny` checks it through `allows($user, Permission::HOTELS_VIEW)`, with no role check. `index` adds `whereIn('id', TenantContext::hotelIds())`. Update the "hotel settings are admin-only" text in `app/Enums/Permission.php` and `docs/staff-roles-api-documentation.md`, add the endpoint to the permission reference and to the `PermissionAuthorizationTest` dataset, with an employee allowed with it and `403` without it. `viewAny` is the only hotel ability this permission opens; `show`, `update` and `destroy` return the suspended `403` for a hotel outside the scope. 2.2 adds `destroy`. 2.10 removes `destroy` from the tenant route. Update `HotelControllerTest:37`. |
| BK7 | PROPOSE FIX | 3.6, 5.5, 6.2: `prependToPriorityList(SubstituteBindings::class, ...)` for `EnsureNotSuspended`, `EnsureNotImpersonating` and `DenySuperAdmin`, added in that order after the existing entries. Test that a suspended hotel's record id, without a header, does not return it. 6.6 enables feature flags. |
| BK8 | PROPOSE FIX | 5.4 and 5.8: use `context.impersonated_user_id`. 5.4: `$token = Auth::user()?->currentAccessToken(); if ($token instanceof PersonalAccessToken && $token->impersonator_id)`. |
| BK9 | PROPOSE FIX | 5.5: `POST /connect` plus `middlewareFor(['store','update','destroy'], 'not_impersonating')` on `/users` and `/staff-roles`. List `bootstrap/app.php` for the alias. 5.8 tests each write method, and `PUT /users/{id}` with `password`. |
| BK10 | PROPOSE FIX | New Stage 5 item, `AuthenticatedSessionController::apiLogout` (Modify). 5.2: `context.expires_at` on the start event; the response returns `token`, `token_id` and `expires_at`. |
| BK11 | PROPOSE FIX | Spec §3: "the first `EvaluateProactiveTriggersJob` run after reactivation may also create rows that are still inside the finder's normal window". No catch-up is built. The plan adds nothing. |
| BK12 | PROPOSE FIX (D4, D5) | Close Open Questions 2 and 3 in the spec. 3.11 uses `UNAVAILABLE_REPLY`. 3.12 holds the two API messages only, in `en` and `ar`, matching the existing `lang/` pairs. |
| BK13 | PROPOSE FIX | 3.4: check `$token instanceof PersonalAccessToken && $token->getAttribute('impersonator_id')`, which is null before stage 5. Stage 5 tests cover that branch. |
| BK14 | PROPOSE FIX (D6) | Add a route table for every super admin endpoint to 2.11. 2.2 `store` takes `{account}` from the path and shares creation through `HotelProvisioning`, including the trashed-slug restore. `UserAdministration::create` sets `hotels.owner_id` when it is null and `role=admin`, sets a random password, and calls `sendPasswordReset`. The CUJ 1 test calls `GET /hotel/{id}` as the new admin. |
| BK15 | PROPOSE FIX | 2.5: add `AccountIndexRequest` and `HotelIndexRequest`, overriding `modelClass()` and `filterColumns()` for the `suspended` filter, which the controller applies. |
| BK16 | PROPOSE FIX | 3.13: create `tests/Feature/JobSuspensionInventoryTest.php` with two lists: `SkipIfHotelSuspended`, and jobs that select with `notSuspended`. `ProcessInboundWhatsAppMessageJob` is allowlisted with its reason. |

### Majors

| ID | Suggestion | Proposed edit |
| --- | --- | --- |
| M1 | PROPOSE FIX | 2.4: "It refuses `role=super_admin` from every caller; today super admins are allowed." Applies to create, update and `changeAccess`. Flip `CreateUserTest:454`. |
| M2 | PROPOSE FIX | Add `GenericCrudTest`, `OperationalDefaultsTest` and `entry_points.php` to 2.14. Add `CostVisibilityTest`'s path change to 1.6. Expect 405. |
| M3 | PROPOSE FIX | 4.1: the cost query is a new method in `app/Services/AiCost/`, and `PlatformSummary` calls it. Output is mapped to arrays. |
| M4 | PROPOSE FIX | 4.2: store `cached_at` inside `remember`. Test with frozen time. |
| M5 | PROPOSE FIX | 4.3: `SuperadminDashboardRequest`, `date_format:Y-m-d`, compare with `addMonths(12)`, and `to` converted with `endOfDay`. Add a signups test. A user counts as suspended when all their hotels are. |
| M6 | PROPOSE FIX | 5.6: add `impersonation` in the `GET /user` closure in `routes/api.php`, not in `UserResource`. |
| M7 | PROPOSE FIX | 5.2 and 5.3: `POST /impersonation/end {token_id}`, limited to rows with an `impersonator_id` (404 otherwise), and any super admin may end it. Use `forceFill` for `impersonator_id`. |
| M8 | PROPOSE FIX | Remove "soft-deleted" from 5.2 and spec line 459. |
| M9 | PROPOSE FIX | Stage 5: add the `impersonating` tag in `ResolveTenant`, tested by mocking `TagsSentryScope`. Tag `suspended=true` in 3.8. |
| M10 | PROPOSE FIX | 3.8/3.9: `hotelId(): ?string`, and null means the job runs. Add a job-to-lookup table, each with `withoutGlobalScope('hotel')`. Append the middleware with the skip first. |
| M11 | PROPOSE FIX | Remove `SendWhatsAppMessageJob` from 3.9 and leave it ungated. Remove the exception from the spec. |
| M12 | PROPOSE FIX (D9) | 3.11: in `replyFor()`, after the not-paired and opt-out checks, return `UNAVAILABLE_REPLY` for a suspended hotel, through the existing send path. Limit: `Cache::add('suspended-reply:'.$hotelId.':'.$phone, 1, 86400)`; on a repeat, store with no reply and a new `InboundMessageStatus` case. The spec's `SendWhatsAppMessageJob` wording is replaced. |
| M13 | PROPOSE FIX | 3.10, closed list: `notSuspended()` for `EvaluateProactiveTriggersJob`, `ExpireRecommendationsJob`, `MatchRecommendationOutcomesJob`, `StartHousekeepingDayJob`. `RetryGuestMemoryExtractionsJob` uses `whereIn('hotel_id', Hotel::notSuspended()->select('id'))`. **Run regardless:** `FlagAiCostOverrunsJob`, `RebuildUsageCountersJob`, `PruneReservationImportPreviewsJob`. `PurgeDeletedKnowledgeDocumentsJob` skips suspended hotels, because data is kept. |
| M14 | PROPOSE FIX | 3.2: `whereNull('hotels.suspended_at')->whereNotIn('hotel_group_id', HotelGroup::withTrashed()->whereNotNull('suspended_at')->select('id'))`. Test `$hotel->id`. |
| M15 | PROPOSE FIX | Delete the "suspension-gated" column from 3.13. |
| M16 | PROPOSE FIX (D10) | With D10: `EnsureNotSuspended` reads the account's state from `User::account()` when the user has no hotel rows, and from each row's group otherwise. Login shares this rule. 3.7 uses "at least one hotel". 3.4a–3.4c are deleted. |
| M17 | PROPOSE FIX (D10) | With D10: the middleware skips a null scope, so the `suspensionResolved()` check and the "refuses if `tenant` did not run" rule go away. |
| M18 | PROPOSE FIX | 3.6 and 6.2: `/logout` under `tenant` alone; `GET /user` adds `not_suspended`; all other routes nest under `not_suspended` and, from stage 6, `no_super_admin`. |
| M19 | PROPOSE FIX | 2.2: `update` covers platform fields only (`name`, `slug`, `hotel_group_id`). Add `app/Services/Superadmin/HotelProvisioning`. 2.5: use `GenericUpdateRequest` where it fits, and list fields otherwise. Add super-admin-only resources holding `suspended_*`. Counts use the `MeteringService` seat query. A super admin target returns 404. |
| M20 | PROPOSE FIX (D8) | Move the base audit test to 2.13, naming `GET /api/history/{type}/{id}`, and extend it in 5.8. |
| M21 | PROPOSE FIX | 6.3: replace the conditional with "these controllers do not authorize through policies, so they do not change". 6.4: **the plan holds an initial decision per file, to be confirmed by Open Question 1's check at the start of stage 6.** 6.5: add `KnowledgeDocumentControllerTest`, `TransactionsFeatureFlagTest`, `CostVisibilityTest`. Remove `EntryPointDiscovery.php`. Delete 6.7. Add `entry_points.php`. |
| M22 | PROPOSE FIX (D7) | Add to Non-Goals and the risks table. |
| M23 | PROPOSE FIX | 1.4 and 1.5: add the missed files, and allow comment changes. Delete 1.7. |
| M24 | PROPOSE FIX | 3.2: list all three columns as not fillable, and each model's methods. Extend the test at 3.13. |
| M25 | PROPOSE FIX | 3.4: restate the steps with their conditions. |

### Nits

| ID | Suggestion | Proposed edit or reason |
| --- | --- | --- |
| N1 | PROPOSE FIX | 5.7: "Schedules `sanctum:prune-expired --hours=1`." Add the entry to `entry_points.php`. |
| N2 | PROPOSE FIX | Use `revokeAccess` for the service and `/revoke-tokens` for the route, as the spec's CUJ 5 has it. |
| N3 | PROPOSE FIX | Replace 2.7 and the 2.8 override with `ResetPassword::createUrlUsing()` in `AppServiceProvider`. Add `FRONTEND_URL` to `.env.example`. |
| N4 | PROPOSE FIX | 2.6: a completed reset deletes tokens and also unpairs the device, so a password reset closes the same gap as `revokeAccess` (comprehension #11). |
| N5 | PROPOSE FIX | Spec §5 and R8: use `platform.impersonation.*`. |
| N6 | DECLINE | A pairing code issued before a suspension can pair for up to 15 minutes. The paired device still gets the fixed reply, so it gains nothing. |
| N7 | PROPOSE FIX | Add the missing docs to 2.15, 3.14 and 5.10. |
| N8 | PROPOSE FIX | Use the style review's replacement phrases. |
| N9 | PROPOSE FIX | 6.4: use full paths. |
| N10 | DECLINE | Formatting only. |
| N11 | PROPOSE FIX | Limit the 403 requirement to HTTP endpoints. |

---

**Not re-verified:** the proposed edits rely on the code facts above and on the
reviewers' reading of the source. No code has been run. The one allowed re-check
of this stage will test them.
