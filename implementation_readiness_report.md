# Stage 9: Implementation Readiness Report

*   **Target:** `docs/designs/super-admin-platform.md`, DetailedRange lines
    762–1325
*   **Round:** 1
*   **Reviewers:** all three completed, none failed:
    *   `egm-implementation-readiness` (standard:
        `implementation-readiness-standard`)
    *   `egm-codebase-details` (standard: `implementation-readiness-standard`)
    *   `egm-style` (no separate standard)
*   **Context given:** `concepts_brief.md`
*   **Constraint given:** rollback, backfills and existing rows are out of scope
*   **Date:** 2026-11-05

**Verdict: not ready to implement.** After merging duplicates: 16 Blockers,
25 Majors, 11 Nits, from 105 raw findings (implementation readiness 43,
codebase 37, style 25).

The findings fall into three groups:

1.  **The plan was never updated for the revised spec.** BK1–BK4, BK8–BK10, M1,
    M6, M9, M12. Mostly mechanical.
2.  **The design meets code it did not account for.** BK5–BK7, BK14–BK16, M3,
    M14, M16, M17. These are new information and need real changes.
3.  **Decisions only the author can make.** Listed next.

## Decisions needed from the author

| # | Decision | Recommendation |
| --- | --- | --- |
| D1 | **BK5:** how a user works in hotel B when default hotel A is suspended | `not_suspended` also replaces the in-memory `hotel` relation with the new current hotel |
| D2 | **BK6:** who can list hotels with `GET /api/hotel` (403 for everyone today) | Admins and employees, limited to their own hotels |
| D3 | **BK6:** can a hotel be deleted at all after stage 6 | Add `destroy` to `Superadmin\HotelController`, and remove it from the tenant route |
| D4 | **BK12:** do account owners still see a suspended hotel in account-level views (Open Question 2) | Hidden, the same as for other users |
| D5 | **BK12:** fixed reply wording and language (Open Question 3) | Reuse `UNAVAILABLE_REPLY`: one English text for the whole platform |
| D6 | **BK14:** how the first admin gets a password in CUJ 1 | Random password plus `sendPasswordReset`, so the super admin never knows it |
| D7 | **M22:** queued notifications when a hotel is suspended | Accept for v1, and record as a risk |
| D8 | **M20:** do the moved knowledge and memory controllers write audit rows (Goal 9 says every write) | Narrow Goal 9 to new super admin writes in v1 |
| D9 | **M12:** does STOP/opt-out still work for a suspended hotel | Yes |

## Checked by the Elephant against the code

*   **BK5, confirmed.** `ChecksPermissions::allows` compares the record's hotel
    with `$user->hotel?->id`, and `resolveHotel()` returns `$user->hotel`. Neither
    reads `TenantContext`.
*   **BK6, confirmed.** `HotelPolicy::viewAny` returns `false`.
*   **BK7, confirmed.** `bootstrap/app.php` lists only `ResolveTenant` and
    `EnsureFeatureEnabled` ahead of `SubstituteBindings`.
*   **BK14, confirmed.** `HotelPolicy::view` and `update` require
    `owner_id === $user->id`.
*   **BK15, confirmed.** `ResolvesModelFromRoute` maps the controller name to a
    model, and throws if none exists.
*   **M3, confirmed.** `CostVisibilityTest` has an allowlist of classes that may
    touch cost data. It does not include `app/Services/Reports/`, and it names
    `app/Http/Controllers/Admin/`, which stage 1 moves.
*   **M8, confirmed.** `User` does not use `SoftDeletes`.
*   **M22, confirmed.** Six notifications implement `ShouldQueue`.

**BK11 looks like a misreading, and part of the cause is Stage 8 wording.** The
reviewer read spec lines 404–409 as a requirement to catch up on messages missed
during a suspension. The intent was only that the first run may create rows that
are still inside the finder's normal window. Fix the wording; build nothing.

**Correction to an earlier fix.** The M3 text accepted in Stage 7 ("a request
without `X-Hotel-Id` works in B") cannot be delivered by changing `TenantContext`
alone, which is what M2 and M3 assumed. BK5 is the fix.

---

## 🔴 Blockers

### Plan contradicts or omits the revised spec

| ID | Scope | Problem | Proposed change | Raw IDs |
| --- | --- | --- | --- | --- |
| BK1 | 883, 985, 1160–1176 | No task creates `EventLogger::recordPlatform()`, though stages 2, 3 and 5 depend on it. 2.1 and 2.2 record nothing, though CUJ 1 needs a row per creation. | Add a Stage 2 `EventLogger` task with the signature, the `runForHotel(null, ...)` wrapper, the actor rule, and each `platform.*` event name. | impl-B1, code-B6, style-M13 |
| BK2 | 910–916 | 2.8 and 2.9 add `RecordsEvents`, which the spec forbids. | Delete both, and the `hotels()` helper. Use `withCount`. | impl-B2, code-B4, style-B1, style-N1 |
| BK3 | 1043–1045 | 3.8 deletes the job. The spec calls `failed()` with `HotelSuspended`. There is no task for the exception. | Rewrite 3.8. Add `app/Exceptions/HotelSuspended.php`. Test: an import is marked failed, and no `failed_jobs` row appears. | impl-B3, code-B8, style-B3 |
| BK4 | 846–859, 925–930, 980–988 | No routes or actions exist for suspend and reactivate. `Suspension` is unreachable. Hotel paths are named nowhere. | Add a Stage 3 task with actions, exact paths for account and hotel, and a request for `reason`. | impl-B4, code-B7, style-M13 |
| BK8 | 1175, 1200 | The plan uses `context.on_behalf_of`. The spec uses `context.impersonated_user_id`. Token access is not stated either. | Rename. Specify `currentAccessToken()` with an `instanceof PersonalAccessToken` check. | impl-B8, code-B5, style-B2 |
| BK9 | 1181–1183, 1203 | `not_impersonating` names password routes that do not exist, and leaves `POST`, `DELETE` and `PUT` with `password` open on `/users`. This is the lasting-access hole. | List `POST /connect` and every write method on `/users` and `/staff-roles`. Test `PUT /users/{id}` with `password`. List `bootstrap/app.php`. | impl-B9, code-M11, style-B4 |
| BK10 | 1154–1169 | There is no task for the logout end event. The start event lacks `expires_at`. The response shape is not stated. | Add an `apiLogout` task. Add `context.expires_at`. Return token, token id and expiry. | impl-B10, code-M10, code-M12, style-M13 |

### The design meets code it did not account for

| ID | Scope | Problem | Proposed change | Raw IDs |
| --- | --- | --- | --- | --- |
| BK5 | 992–1015, CUJ 3 | **Multi-hotel access does not follow `TenantContext`.** Policies and `resolveHotel()` use `$user->hotel`, the legacy default. With default A suspended, policy checks on B's records fail, creates attach to A, and the dashboard reads A. CUJ 3 cannot pass. | D1. `EnsureNotSuspended` calls `setRelation('hotel', ...)` when it replaces the current hotel. State the one-query exception to the "adds no queries" NFR. Test a dashboard read, a policy-guarded `GET` and a create. | impl-B5, code-B1 |
| BK6 | 918–923, CUJ 3 | `GET /api/hotel` is 403 for hotel users today and lists every hotel when allowed. `viewAny`, `delete`, `restore` and `forceDelete` return false for everyone, so after 6.3 they are unreachable. `Hotel` has no tenant scope, so A's owner can still read suspended A. | D2, D3. Scope `index` to `TenantContext::hotelIds()`. Return the suspended 403 for a hotel outside the scope. | impl-B6, code-B10 |
| BK7 | 1030–1033, 1228–1232 | New middleware runs after route-model binding. A suspended hotel's record is loaded before the hotel is removed, and a super admin gets 404 instead of 403. | Add `prependToPriorityList(SubstituteBindings::class, ...)` for the new middleware, and state the resulting order. Enable feature flags in the 6.6 walker. | impl-B7, code-B2 |
| BK13 | 992–998 | Stage 3 branches on `impersonator_id`, which stage 5 creates. | State a null-safe check that works before the column exists. Stage 5 tests cover that branch. | impl-B13, code-N7 |
| BK14 | 856–859, 925–948 | CUJ 1's route is not in the plan. The first admin gets 403 on their own hotel, because nothing sets `owner_id`. There is no password rule. `store` omits the trashed-slug restore. | Add a table of every super admin route. Take `{account}` from the path. `UserAdministration::create` sets `owner_id`. D6. Extend the CUJ 1 test to `GET /hotel/{id}` as the new admin. | impl-B14, code-M18 |
| BK15 | 846–857 | `GenericIndexRequest` throws for `AccountController` (no `App\Models\Account`), and `filter[suspended]` returns 422 because it is not a column. | Add `AccountIndexRequest` and `HotelIndexRequest`, overriding `modelClass()` and `filterColumns()`. | code-B3 |
| BK16 | 1094–1098 | The job inventory test fails as written. Four looping jobs call `runForHotel` without the middleware, by design. | Create `JobSuspensionInventoryTest` with two lists: middleware, and `notSuspended`. Allowlist the inbound job with a reason. | code-B9, impl-M15, style-N3 |

### Decisions and wording

| ID | Scope | Problem | Proposed change | Raw IDs |
| --- | --- | --- | --- | --- |
| BK11 | 1061–1066; spec 404–409 | **Elephant: misreading (see above).** The reviewer reads a requirement to catch up on missed messages. | Reword the spec to "may also create rows still inside the finder's normal window". No code. | impl-B11 |
| BK12 | 1077–1080 | Open Questions 2 and 3 block stage 3, and the plan does not settle them. | D4, D5. Put the texts in the lang file, including `ar` if translated, and both API messages. | impl-B12, code-N4, style-N5 |

---

## 🟡 Major

| ID | Scope | Problem | Proposed change | Raw IDs |
| --- | --- | --- | --- | --- |
| M1 | 883–894, 944, 950–953 | `denySuperAdminGrant` "moves" unchanged, but the spec refuses every caller. `CreateUserTest:454` asserts the opposite. | State the new condition. Apply it on create, update and access change. Flip the test. | impl-M1, style-M3 |
| M2 | 950–953, Stage 1 | Stages do not leave the suite green: `GenericCrudTest`, `OperationalDefaultsTest`, `entry_points.php:35`, and the `CostVisibilityTest` allowlist path. | Add these to the lists. Change the allowlist to `Superadmin/`. Expect 405, not "405 or 404". | impl-M2, code-M19, style-M5, code-N3 |
| M3 | 1114–1116 | `UsageReport` holds no cost, and `app/Services/Reports/` may not read cost under `CostVisibilityTest`. Models break the plain-array rule. | Put the cost query in `app/Services/AiCost/`. Map output to arrays. | impl-M3, code-M15 |
| M4 | 1120–1122 | `cached_at` added outside the closure shows the read time. | Store it inside `remember`. Test with frozen time. | impl-M4, code-N5 |
| M5 | 1124–1140 | The class name differs from the spec. 366 days differs from 12 months. `Y-m-d` and UTC are missing. `to` must become end of day. | Use the spec's name, `date_format:Y-m-d`, `addMonths(12)`, and `endOfDay`. Define active vs suspended users. | impl-M5, style-N4, code-N5 |
| M6 | 1185–1188 | The `impersonation` field lands on every serialized user. | Add it only on `GET /api/user`. | impl-M6, code-M14, style-M11 |
| M7 | 1161–1169 | The end endpoint has no request shape and could end any token. `impersonator_id` is not fillable. | Define `{token_id}`, limit to impersonation tokens, and use `forceFill`. State whether another super admin may end it. | impl-M7, impl-M8, code-M12 |
| M8 | 1157–1158; spec 459 | "Soft-deleted target": `User` has no soft deletes. | Remove the clause in both places. | impl-M8, code-M13 |
| M9 | Stage 5 | There is no task for the `impersonating` Sentry tag. `suspended=true` is also not in 3.8. | Add to `ResolveTenant`. State how tests observe it, given `TagsSentryScope` does nothing without a DSN. | impl-M9, code-M10, code-B8 |
| M10 | 1049–1059 | `hotelId()` is unspecified for jobs holding only an id. Global documents have a null hotel. `middleware()` could overwrite `WithoutOverlapping`. | Add a job-to-lookup table. `hotelId(): ?string`, and null means run. Append, with the skip first. Update the "one lookup" NFR. | impl-M10, code-M6 |
| M11 | 1054–1059 | `SendWhatsAppMessageJob` has no hotel and no message type. | Remove it from 3.9, leave it ungated, and update the inventory reason. | impl-M11, code-M5 |
| M12 | 1068–1075 | The inbound task omits placement, status and the 24-hour limit storage, and misses reuse of `replyFor()`. STOP handling is undecided. | Return through the existing fixed-reply path, after the not-paired and opt-out checks. D9. Name the throttle store. *Stage 8 finding 9 suggests dropping the limit; the author kept it.* | impl-M12, code-M9, style-M10 |
| M13 | 1061–1066 | The looping job list has gaps. `AiCost/` and `Metering/` hold nothing that fits. `RetryGuestMemoryExtractionsJob` queries rows, not hotels. Purge and prune jobs are undecided, and purge deletes data. | Replace with a closed list and one decision per job. | impl-M10, impl-M13, code-M7, style-M9 |
| M14 | 975–978 | A join in `scopeNotSuspended` makes `is_active` ambiguous and lets `hotel_groups.id` overwrite `$hotel->id`. | Use `whereNull` plus a `whereNotIn` subquery, with no join. Test `$hotel->id`. | impl-M14, code-M4 |
| M15 | 1099–1101 | The "suspension-gated" inventory column has no design and duplicates the route test. | Delete it. | impl-M16, code-M19 |
| M16 | 1003–1015, 1037–1039 | `$accountSuspended` has no definition. "Same results" is false for one joined query. Unaliased columns collide. 3.7 still counts zero hotels as suspended. | Derive it from `User::account()`. Spell out the query shapes and aliases. Use "at least one hotel". *Zoom-out 3 would avoid most of this by running one query in the middleware.* | impl-M17, code-M1, code-M2, style-M8 |
| M17 | 990–1015 | `not_suspended` refuses unresolved state, but nothing is resolved for super admins. So every super admin hotel request fails in stages 3–5, and about 33 test files with it. | 3.4b calls `setSuspension(false, [])` for a null scope. | code-M3 |
| M18 | 1030–1033, 1230–1232 | The exemption mechanism for logout and `/user` is unstated. | Give the exact nested route structure. Also answers R-N4. | impl-M18 |
| M19 | 848–894 | Stage 2 lacks update requests and field lists, and does not say where shared hotel and staff-role logic goes. `HotelResource` is shared, so adding `suspended_reason` exposes it to hotel staff. Filters and counts miss users attached through a hotel. Super admin targets are undefined. | Add field lists, a `HotelProvisioning` service, super-admin-only resources, the seat query from `MeteringService`, and a status for super admin targets. | impl-M19, code-M16, code-M17, style-M4, style-M6 |
| M20 | 1208–1211 | The audit visibility test is in stage 5, but rows start in stage 2. No endpoint is named. Moved controllers have no audit task. | Move the base test to 2.13 and extend it in 5.8. Name `GET /api/history/{type}/{id}`. D8. | impl-M20 |
| M21 | 1234–1296 | 6.4 defers every decision to a PR description. 6.5 misses tests. `entry_points.php` cites test names that the rewrites change. | Write the per-file decisions in the plan. Add the missing tests. List `entry_points.php`. Remove no-op targets. | impl-M21, style-M12, code-N3, code-N8, impl-N8, style-N7 |
| M22 | 1047–1059 | Six queued notifications are outside the job middleware and still send. | D7. | code-M8, impl-N6 |
| M23 | 794–824 | 1.4's "code does not change" is imprecise. The comment list misses files. 1.7 refers to entries that do not exist. | Fix the lists. Delete 1.7. | style-M1, style-M2, code-N1, code-N2, impl-N1, impl-N2 |
| M24 | 975–978, 1087 | Only `suspended_at` is named as not fillable. The shared heading blurs which model gets which method. | List all three columns and each model's methods. Extend the test. | style-M6 |
| M25 | 997–998 | 3.4's summary reads as a 403 on every request. | Remove it, or restate it with conditions. | style-M7 |

---

## 🟢 Nits

| ID | Scope | Issue | Proposed change | Raw IDs |
| --- | --- | --- | --- | --- |
| N1 | 1192–1193 | "if it is not already scheduled": it is not. `entry_points.php` needs the entry. | Drop the clause, and add the file. | style-N2, code-N3, impl-M21 |
| N2 | 869, 881, 929, 946 | `revokeTokens` vs `revokeAccess`. | Use one name. | style-M4 |
| N3 | 903–911, 934 | `ResetPassword::createUrlUsing()` removes the custom notification. `.env.example` needs `FRONTEND_URL`. | Reuse it, and add the line. | code-N6, impl-N3 |
| N4 | 896–901 | Deleting tokens on reset is not in the spec, and the device is not mentioned. | Decide, and say so. | impl-N4 |
| N5 | 1160; spec 463 | `impersonation.started` vs `platform.impersonation.started`. | Use the `platform.` form in the spec. | impl-N5, style (outside range) |
| N6 | Stage 3 | A pairing code issued before suspension can still pair for 15 minutes. | Accept it, or add a check. | impl-N6 |
| N7 | 955–962, 1103–1108, 1213–1218 | Docs lists miss `whatsapp-device`, `user-management`, `staff-roles` and fixed-reply behaviour. | Add them. | impl-N7 |
| N8 | 774, 785, 842, 985, 1028, 1080 | Vague or tautological phrases. | Use the replacements in the style review. | style-N5 |
| N9 | 1256–1257 | Bare filenames are ambiguous after stages 1–2. | Use full paths. | style-N6 |
| N10 | 771, 3.4a–c, route forms, "superadmin" vs "super admin" | Formatting and term consistency. | Use one convention. | style-N8 |
| N11 | 1315–1317 | "403" does not apply to services or job middleware. | Limit it to HTTP endpoints. | style-N8 |

---

## Verified correct

*   1.4's six controllers; 1.6's ten test files; 1.8's eight API docs.
*   6.3's 27 policies; 6.4's file list against `isSuperAdmin()`.
*   3.4c's save-and-restore description.
*   `TagsSentryScope`, `GenericQuery::apply`, `throttle:login`, the
    `password_reset_tokens` table and `personal_access_tokens.expires_at`.

**Round status:** not clean.

---

# Stage 9 re-audit (2026-11-05)

This is the one re-audit allowed for this stage, run after the 50 fixes were
applied. Line numbers refer to the doc at 1,489 lines (feature spec 1–777,
implementation plan 778–1489).

**Verdict: still not ready, and much closer.** After merging duplicates:
5 Blockers, 20 Majors, 16 Nits, from 65 raw findings (implementation readiness
22, codebase 21, style 22). In round 1 there were 16 Blockers. All three reviewers
report that most round 1 findings are fixed. Almost everything new sits where the
rewrite's new rules meet existing code.

**The review has not converged.** Each round has found new defects, including
defects in fixes proposed by the Elephant (R1, R4).

## Checked by the Elephant against the code

*   **R1.** `accessibleHotelIds()` never reads `owner_id`, so a hotel outside the
    scope is not necessarily suspended.
*   **R3.** `HotelController::store` compares `owner_id`, restores, then updates
    with the submitted values, so a new `hotel_group_id` would be written.
*   **R4.** `ResolveTenant:29` already makes the header's hotel current.
*   **R5.** `$data` holds `insight_type` and `user`, and the job reads `$user->hotel`.
*   **M1, M2, M3, M9, M11, M14.** `load()` on `GET /user`; the inventory entry at
    `entry_points.php:70`; `GenericCrudTest:82`; `HistorySubjects` has no hotel or
    user type; the `(string)` cast in `TagsSentryScope`; the uuid `subject_id`
    against the bigint token id.
*   **R2** was not traced by running anything. It follows from the `BelongsToHotel`
    and `ChecksPermissions` code already read.

## 🔴 Blockers

| ID | Scope | Problem | Proposed change | Raw IDs |
| --- | --- | --- | --- | --- |
| R1 | 1115–1117; spec 395–396 | **Elephant's text.** "A hotel outside the filtered scope returns the suspended 403" gives a false "suspended" to an owner linked only by `owner_id`, and to a stranger probing an active hotel. Breaks `HotelControllerTest:112,155` and `GenericCrudTest:91,103`. | Run `authorize` first, then return the suspended 403 only when `$hotel->isSuspended()`, and not for an impersonation token on `show`. Add `use ChecksPermissions` to `HotelPolicy`. Add tests. | impl-B1, code-B2 |
| R2 | 1090–1097; spec 374–375 | An impersonation token can write into a suspended hotel when the target has several hotels: no block condition fires, and the row is stamped with the suspended hotel. Breaks Goal 7. | State the rule by method. `GET`/`HEAD`: skip narrowing. Other methods: the normal-user path, including the relation swap. Test default A with no header, a `PUT` on A's record with `X-Hotel-Id: B`, and a `POST` in B. | impl-B2, code-M4 |
| R3 | 883, 895–897 | Moving `store` unchanged lets a slug reuse restore a deleted ownerless hotel and write the new `hotel_group_id`, moving its guests and reservations to another customer. A deleted hotel with an owner always returns 422, so the promised restore rarely happens. | Restore only when `$trashed->hotel_group_id === $account->id`, otherwise 422. Test that another account's slug returns 422 and the hotel stays where it was. | impl-B3 |
| R4 | 1086–1089; spec 365–370 | **Elephant's text.** The relation is replaced only "when it replaces the current hotel". With default A suspended and `X-Hotel-Id: B`, nothing is replaced, so `$user->hotel` stays A: creates land in the suspended hotel, and B's records return 403. | Trigger on "`$user->hotel_id` is among the removed hotels", and set the relation to the resulting current hotel. Add that test. | code-B1 |
| R5 | 1161 | "The hotel in `$data`" does not exist. | `hotelId()` returns `$this->data['user']->hotel?->id`. | code-B3, impl-N1 |

## 🟡 Major

| ID | Scope | Problem | Proposed change | Raw IDs |
| --- | --- | --- | --- | --- |
| M1 | 1086–1089, 1140–1143 | `load(['hotel'])` on `GET /user` and login undoes the relation swap, so the response shows the suspended hotel. | `loadMissing` on `/user`. At login, set the relation to the first active hotel. Test `body.hotel.id`. | impl-M2, code-M1 |
| M2 | 1022–1025 | `DELETE api/hotel/{hotel}` also stays in the inventory, so stage 2 ends red. | Remove it too. | impl-M1, code-M2 |
| M3 | 1102–1106, 1120 | `GenericCrudTest:82` expects 403 from an admin on the list. | Add it to 3.4a and flip it. | code-M3 |
| M4 | 902–904, 945 | `filter[hotel_id]` adds an exact `WHERE`, so pivot users are dropped. No index request is named. | Add `UserIndexRequest`. The controller applies those two filters and removes them before `GenericQuery`. Test a pivot-only user. | impl-M4, code-M6, style-22 |
| M5 | 867–869 | The `MeteringService` seat query is inside a method that writes counters, and ignores pivot users. | A subselect counting distinct users across all three attachments, excluding super admins. | impl-M5, code-M7 |
| M6 | 938–955, 874 | Field lists are missing. `contract_value_monthly` in `Requests/Superadmin/` or `Resources/Superadmin/` fails `CostVisibilityTest`. | List the fields. **Decision D11.** | impl-M6, code-M8 |
| M7 | 906–907, 917–929, 954–955 | The access change has no payload. `group_role` does nothing unless `hotel_group_id` is set, so Goal 3's account owners cannot be created. Pivot values, service signatures, statuses and the staff role rule are missing. | Specify all of them. **Decision D12.** | impl-M7 |
| M8 | 1239–1252, 1276 | The cost method has no class, name or currency. No endpoint has its JSON keys. `PlatformSummary` may not contain `cost_usd`. Who merges the two is unnamed. The suspended-user rule ignores zero hotels. | Name `app/Services/AiCost/AiCostReport::perAccount(from, to): array`, the merge point, the JSON, and the 3.7 rule. | impl-M8, code-M9, style-6, style-8 |
| M9 | 1013–1014, 1363–1365 | The audit test cannot fail: `HistorySubjects` has no hotel, user or account type, so it returns 404. | Use a `Guest` subject, and assert `EventLog::query()` under `runForHotel($hotelId)` shows no `platform.*` rows. | impl-M9, code-N4 |
| M10 | 1221–1224, 1180–1182 | The job test has no rule for finding jobs, and one job fits neither list. | Every class from `queuedJobs()` must be on the middleware, `notSuspended`, or exempt list, with reasons for exempt jobs. | impl-M10, style-4 |
| M11 | 1098, 1150, 1316–1318 | No test covers the Sentry criteria, and `true` becomes tag `1`. | Pass `'true'`. Add tests with a DSN configured. | impl-M11, code-M11 |
| M12 | 874, 889, 926, 1073, 1243 | The tenant `UserController`, the resources, `SuspendRequest`, the AiCost class and the login change have no file heading. | Add them. | impl-M12 |
| M13 | 1176–1179 | The purge filter stops purging global documents and those of soft-deleted hotels. | `where(fn ($q) => $q->whereNull('hotel_id')->orWhereIn('hotel_id', Hotel::withTrashed()->notSuspended()->select('id')))`. Add a test. | impl-M3, code-M10 |
| M14 | 934, 1289–1295, 1310–1312 | The impersonation event subject is unnamed. The token is a bad choice: its bigint id cannot go in the uuid `subject_id`. | The subject is the target user. The end endpoint passes `impersonated_user_id` explicitly. | code-M5, impl-N3 |
| M15 | 869, 874–875, 882, 889–890, 947 | Stage 2 uses `suspended_*` columns that stage 3 creates. | Move the filter and resource fields to 3.3a. | style-1 |
| M16 | 1421; spec 530 | 6.4 keeps `User::permissions()`, and the spec says it is removed. | Remove it from the spec's list. | style-2, code-N7 |
| M17 | 1415–1416 | Open Question 1 covers `api/admin` controllers, not the 6.4 rows. The promised inventory has no step. | Re-run the `isSuperAdmin` search at the start of stage 6, and record changes in 6.4. | style-3 |
| M18 | 917–925 | `create` plus `sendPasswordReset` writes two rows. The public reset has no actor. | `create` records only `platform.user.created`. **Decision D13.** | style-5, code-N5 |
| M19 | 926–928, 1024 | "Only a super admin can assign..." becomes false. `CreateUserTest:352` and the docs assert it. | New message: "The super admin role cannot be assigned through the API." Add both to the lists. | style-7, impl-M7 |
| M20 | 1124–1126, 1115 | `SuspendedResponse` also has a third caller. | Name all three. | style-9 |

## 🟢 Nits

| ID | Scope | Issue | Raw IDs |
| --- | --- | --- | --- |
| N1 | 1194–1198 | Nothing calls `setLocale`, so `lang/ar/suspension.php` is never served. Drop it, or give keys and text. | impl-N2 |
| N2 | 1140–1143 | The login check should skip super admins. | impl-N4 |
| N3 | 871, 1059–1073, 959–962 | Unstated: re-suspending, response bodies, `forceFill`, the refused delete status, reset password rules. | impl-N5, code-N6 |
| N4 | 1348–1349, 490–491 | No schedule cadence. The inventory entry must match exactly. It prunes all expired tokens. | impl-N6, code-N3, style-13 |
| N5 | Stage 3 | Record the 15-minute pairing window as accepted. | impl-N7 |
| N6 | 1190–1192 | `replyFor()` becomes `?string`. | code-N1 |
| N7 | 1079–1082 | Use an `addSelect` subquery, not a join. | code-N2 |
| N8 | 1431 | `KnowledgeDocumentResource` should say Keep. | code-N7, style-12 |
| N9 | 3.13, 5.8 | Call `forgetGuards()` between token requests. | code-N7 |
| N10 | 1066 | "No cache to clear" ignores the dashboard. | style-10 |
| N11 | 1079, 1083–1085, 768 | The source of account state reads two ways. | style-11 |
| N12 | 1437–1450, 537 | 27 test files listed, "about 33" in the spec. | style-14 |
| N13 | 1189, 1109–1110, 926–927, 1120, 859–860 | Wording. | style-15 to 19 |
| N14 | 1171–1182 | The 3.10 heading does not fit its contents. | style-20 |
| N15 | 1479–1481 | Coverage overclaims for the public reset endpoint. | style-21 |
| N16 | 787, 984–993, 1023, 1226, 856 | Labels and notation. | style-22 |

## Decisions needed from the author

| # | Decision | Recommendation |
| --- | --- | --- |
| D11 | Can super admins see and set contract value on accounts | Leave it out of v1. Otherwise the `CostVisibilityTest` allowlist grows |
| D12 | How account owners are created | `changeAccess` sets `hotel_group_id` from `User::account()` when `group_role` is set. Allowed values: `owner` and `group_admin`, the two the code base already uses. Pivot rows take `role` from the user, with `is_primary` false |
| D13 | Does a completed public password reset write an audit row | No platform event, because no super admin acted |

**Round status:** not clean. This stage's one re-audit is used.

## Outcome

The author chose to resolve the re-audit with the recommended D11-D13. R1-R5, M1-M20 and N1-N12, N14 and N15 are applied. N13 and N16 (wording and labels) are left open. **These fixes have not been reviewed:** this stage's one re-audit was already used, and the review did not converge. Each stage's tests are the next check.
