# Goldfish Annotated Review Report

Status: resolved

## Resolution outcome (2026-11-05)

**State:** Stage 7 (Critic Review) is resolved. The accepted edits are applied to
`docs/designs/super-admin-platform.md`. FeatureRange is now lines 1-735, and
DetailedRange is lines 736-1299.

| Outcome | Items |
| --- | --- |
| Accepted as proposed | C1, C2, M1, M2, M3, M4, M6, M7, M8, M9, M10, M11, M12, M13, M14, M15, M16, N1, N2, N3, N5 (heading only), N8, N9, N10, N11, N12, N13, N14, N15, N16, N17, N20, N21, N22 |
| Accepted, changed | C3 (super admins have no hotel links, and no API path grants `super_admin`; no data migration). M5 (no automatic re-run; staff redo one-off work). |
| Dropped by the author | M17: rollback is not considered, because the project is in dev with no users. The rollback bullet is deleted, and R3 no longer mentions reverting. |
| Contest upheld | N18 (plan item 3.4c already covers it) |
| Declined | N4, N6, N7, N19, N23, N24 |

**Offered and not added:**

*   C1: the frontend keeps the impersonation token in memory only.
*   M6: a `401` after expiry means impersonation ended, and the end endpoint does nothing on an expired token. The client-side flow stays an open gap (comprehension #2).
*   M9: `from` and `to` are handled the same way as `UsageController`.

**Not yet applied to the implementation plan (DetailedRange), left for Stage 9:**
C3 (2.4, 2.8, 2.9, 2.13, 3.3, 5.2), M5 (3.8), M6 (5.4, 5.8), M7 (5.5, 5.8), M13 (3.5).

---

**Target:** `docs/designs/super-admin-platform.md` (lines 1–613, FeatureRange)
**Source:** `critic_report.md` (Stage 7, round 1)

**Summary:** 3 Blockers, 17 Majors, 24 Nits.

*   **PROPOSE FIX:** 36
*   **CONTEST:** 1
*   **ESCALATE:** 0
*   **DECLINE:** 7

**Three fixes are design choices, not just wording.** Please check these
before you accept everything:

*   **M1 (self-registration):** keep `POST /api/register` as it is, and accept
    that a suspended customer can sign up again as a v1 risk.
*   **M5 (jobs under suspension):** fail the job with a `HotelSuspended`
    reason so its `failed()` handler runs. The alternative is to delete it
    (loses cleanup) or release it with a delay (it eventually runs out of
    retries).
*   **M6 (attribution in queued work and expiry):** accept both gaps as v1
    risks instead of building more code.

**Code facts the fixes rely on:**

*   Only `POST /api/login` and `POST /api/connect` call `createToken`.
*   No endpoint lets a user change their own password.
*   `apiLogout` deletes only the current token.
*   These jobs define `failed()`: `IndexKnowledgeDocumentJob`,
    `PrepareReservationImportJob`, `WriteReservationImportJob`,
    `SyncKnowledgeChunksJob`, `SendReservationVoucherEmailJob`,
    `SendProactiveMessageJob`, `SendGuestRequestNoticeJob` and
    `ProcessInboundWhatsAppMessageJob`.
*   The hotel list is `GET /api/hotel`.
*   `POST /api/register` creates a user and a hotel and issues no token.

**Effect on the detailed plan (lines 614 onward, out of scope here).** Some
fixes change it. Stage 9 will check that range against the new feature text:

*   C3/M8 drop `RecordsEvents` on `User` and `HotelGroup` (2.8, 2.9).
*   M5 changes 3.8.
*   M7 changes 5.5.

---

## Prioritized Findings & AI Suggestions

### [Issue C1] [`egm-design`, `egm-structural`] - Severity: Blocker

*   **Affected Scope:** [Lines 446-612](docs/designs/super-admin-platform.md#L446-L612) (between Alternatives and NFRs)
*   **Discrepancy Description:** There is no Risks & Mitigations section.
*   **Rationale:** The feature-spec standard requires failure modes, security
    and scalability risks to be listed with how each is mitigated.
*   ***AI Suggestion:*** PROPOSE FIX
*   ***Justification:*** The finding is valid. Risks exist only as the cons of
    rejected alternatives.
*   ***Proposed Edit:***
    *   Location: insert a new H2 after Alternative M (after line 579, before
        the `---` on line 581).
    *   Change:

```markdown
## Risks & Mitigations

| # | Risk | Failure mode | Mitigation | Stage |
| --- | --- | --- | --- | --- |
| R1 | An impersonation token is leaked or misused | Whoever holds it acts as a hotel user | 30-minute expiry with no extension; it cannot pair WhatsApp or change access; every write is attributed to the super admin; the start event records `expires_at` | 5 |
| R2 | Super admin paths return zero rows after stage 6 | A super admin controller still relies on the tenant scope and gets an empty list with no error | `accessibleHotelIds()` keeps returning `null`; super admin controllers use `withoutScope()` explicitly; Open Question 1 is settled per controller before stage 6 | 2, 6 |
| R3 | The stage 1 hard cut is not deployed together | The frontend calls `api/admin` and gets 404 | Both apps deploy in one release and are reverted together; `latest-changes` maps every old URL | 1 |
| R4 | A route or job misses the suspension check | A suspended hotel keeps working through that path | A route test for `not_suspended`; a job inventory test for `SkipIfHotelSuspended` | 3 |
| R5 | Platform audit rows appear on a tenant event log | Hotel staff see account-level events, such as a suspension reason | Platform events are written with `hotel_id = null` explicitly (see Audit); a test uses a super admin who has a home hotel | 2 |
| R6 | Work queued at suspension is lost | Skipped messages and vouchers are never sent | Skipped jobs fail with a "hotel suspended" reason, so `failed()` puts their records in a final state; reactivation does not replay them, and the docs say so | 3 |
| R7 | A suspended customer registers again through `POST /api/register` | They get a new, empty account | Accepted for v1: the new account has none of the old data and can be suspended too | 3 |
| R8 | Writes from jobs started during impersonation are attributed to the hotel user | An import started by a super admin shows the hotel user as actor | Accepted for v1; the `impersonation.started` event shows who held the session at that time | 5 |
| R9 | Removing about 45 bypasses breaks a path nobody listed | A hotel feature fails for a role that should have access | An inventory with one decision per file before any edit; the generic stage 6 test; the rewritten test files | 6 |
| R10 | Dashboard figures are up to 15 minutes old | A super admin does not see their own suspension in the counts | Every response carries `cached_at` | 4 |
```

### [Issue C2] [`egm-design`, `egm-structural`, `egm-style`] - Severity: Blocker

*   **Affected Scope:** [Lines 454-579](docs/designs/super-admin-platform.md#L454-L579) (Alternatives A–M)
*   **Discrepancy Description:** The rejections say "Rejected by author" or
    "Rejected during brainstorming", with no link, and several only restate
    the chosen design.
*   **Rationale:** The standard forbids naming a person as the reason without
    a link. A rejection must name the trade-off that decided it.
*   ***AI Suggestion:*** PROPOSE FIX
*   ***Justification:*** The finding is valid. The trade-offs exist in the
    Cons, but the rationale field does not state them. This also covers M15
    (D) and M16 (K).
*   ***Proposed Edit:***
    *   Location: each `**Reason for Rejection**` line, and Alternative K's
        Cons.
    *   Change: replace the whole rationale with:
        *   **A:** "The `admin` prefix reads as "hotel admin". Keeping it would
            put new platform endpoints under a misleading name for good, while
            renaming is a one-time cost because both apps release together."
        *   **B:** "Both apps release together, so an alias adds a second live
            prefix and a later removal step without making any deploy safer.
            The hard cut is documented in `docs/latest-changes-<date>.md`."
        *   **C:** "A hotel admin could clear their own suspension through
            `PUT /api/hotel/{id}`, and jobs would mix two meanings of one
            column. New `suspended_*` columns are written only by the super
            admin service and record who suspended, when and why."
        *   **D:** "Read-only needs a check on every write path and an exemption
            list that keeps growing. A full stop needs only the enforcement
            points in §3: login, `not_suspended`, `SkipIfHotelSuspended`,
            `notSuspended()` in jobs that loop over hotels, and the inbound
            WhatsApp job."
        *   **E:** "Blocking inside `runForHotel()` would also block the failure
            and cleanup paths that run inside it. A job middleware is declared
            per job, is enforced by an inventory test, and lets the job's
            `failed()` handler run."
        *   **F:** "Hiding suspended hotels in `accessibleHotelIds()` changes what
            every future caller sees. `ResolveTenant` would need exceptions for
            super admins and impersonation. One middleware keeps suspension in
            one class, and a route test checks that it is present."
        *   **G:** "An edge block alone leaves about 45 bypass branches that
            nothing exercises any more. A scope change risks silent zero-row
            results and touches the `null` vs `[]` rule. The edge block plus
            bypass removal, with an inventory first, avoids both."
        *   **H:** "A header would have to be understood by every `Auth::user()`
            read, every policy, `ResolveTenant` and `User::permissions()`. A
            token issued to the target user makes all of them work unchanged."
        *   **I:** "With the hotel user as actor, the log looks as if that user
            acted unless the reader checks `context`. A new `ActorKind` needs a
            new case in every `ActorKind` switch. The super admin as actor, with
            the user in `context.impersonated_user_id`, avoids both."
        *   **J:** "Each extension widens the window in which a leaked token
            works, and needs its own endpoint and audit event. Starting a new
            30-minute session costs one call."
        *   **K:** "Snapshots need new tables, a scheduled job and a backfill. A
            15-minute cache per section limits the cross-hotel queries to one
            run per section per window, at the cost of figures up to 15 minutes
            old."
        *   **K Cons (replace):** "Live re-runs three grouped queries across
            every hotel on each page load. Nothing has been measured. With fewer
            than 50 hotels each run is expected to be cheap, so the cache limits
            repeated loads; it does not fix a known slow query. Snapshots need
            new tables, a scheduled job and a backfill."
        *   **L:** "There is no per-hotel WhatsApp connection to report on,
            `failed_jobs` has no `hotel_id`, and revenue is in mixed currencies.
            Sentry already covers failures and WhatsApp health. Revenue needs a
            currency decision first."
        *   **M:** "With a temporary password, the super admin knows the user's
            credential. A separate unpair action leaves a gap: a paired device
            keeps Admin Advisor access after the tokens are revoked."

### [Issue C3] [`egm-design`] - Severity: Blocker

*   **Affected Scope:** [Lines 435-444](docs/designs/super-admin-platform.md#L435-L444) (Audit), plus Goal 9 at 104–107
*   **Discrepancy Description:** Platform audit rows get the super admin's
    home hotel and appear on that hotel's event log.
*   **Rationale:** `withoutScope()` keeps `currentHotelId`. `ResolveTenant`
    sets `currentHotelId` to a super admin's `hotel_id`. The `BelongsToHotel`
    creating hook copies it onto `EventLog` rows.
*   ***AI Suggestion:*** PROPOSE FIX
*   ***Justification:*** Verified in `app/Concerns/BelongsToHotel.php:26-30`,
    `TenantContext.php:54-66` and `ResolveTenant.php:26-30`. I chose option
    (b), a dedicated write path, because option (a) depends on the unresolved
    Open Question 1. This also fixes M8 and zoom-out #4 and #5.
*   ***Proposed Edit:***
    *   Location: replace the whole body of `### Audit (all stages)`.
    *   Change:

```markdown
*   Super admin writes are recorded through a new
    `EventLogger::recordPlatform()`:
    *   it writes `hotel_id = null` explicitly. It cannot rely on
        `withoutScope()`, because that keeps `TenantContext::currentHotelId()`.
        On the super admin stack, that value is the super admin's own home
        hotel, and the `EventLog` create hook would copy it onto the row;
    *   it takes the `event_type` as an argument, so names start with
        `platform.` (for example `platform.account.suspended`). Today
        `record()` builds the name from the subject's class name.
*   Super admin services call it explicitly. `RecordsEvents` is not added to
    `HotelGroup` or `User`. Each action writes one row, and tenant edits to
    users do not start producing platform rows.
*   A test has a super admin who has a home hotel suspend an account. It
    asserts that the row's `hotel_id` is null and that the row does not
    appear on that hotel's event log endpoint.
```

### [Issue M1] [`egm-design`] - Severity: Major

*   **Affected Scope:** [Lines 39-42](docs/designs/super-admin-platform.md#L39-L42), 358, Alternatives
*   **Discrepancy Description:** Self-registration already creates accounts,
    and it lets a suspended customer get around suspension.
*   **Rationale:** `POST /api/register` exists (`routes/api.php:64`), and
    `Hotel::booted` creates the group.
*   ***AI Suggestion:*** PROPOSE FIX (**design choice:** keep self-registration
    and accept the evasion as R7)
*   ***Justification:*** "There is no API for creating an account" is false
    for single-hotel self-service accounts.
*   ***Proposed Edit:***
    *   Location: Problem item 2, lines 39–42. Replace with:

        "**Onboarding needs the database.** The public `POST /api/register`
        creates one admin, one hotel and a single-hotel account (`HotelGroup`)
        for whoever signs up. There is no API for a super admin to create an
        account for a customer, add hotels to an existing account, or create
        its first admin. Hotel creation through `POST /api/hotel` works only
        because super admins skip every policy (`HotelPolicy::create()` returns
        `false` for everyone else). Onboarding a multi-hotel customer means
        seeders or SQL."
    *   Location: line 358. Append: "Accounts created through
        `POST /api/register` count as signups."
    *   Location: new Alternative N, after M:
        *   **Alternative N: Extend `POST /api/register` for super admin
            onboarding**
        *   **Pros:** one onboarding path, and no new controller.
        *   **Cons:** it is public and creates exactly one hotel for the person
            registering. Super admin options there would put platform actions
            behind a public endpoint.
        *   **Reason for Rejection:** creating an account for another customer
            is a platform action, so it belongs behind the `super_admin` guard.
            Self-registration stays as it is (see R7).

### [Issue M2] [`egm-design`] - Severity: Major

*   **Affected Scope:** [Lines 324-328](docs/designs/super-admin-platform.md#L324-L328), CUJ 4
*   **Discrepancy Description:** A read-only impersonation token in a
    suspended hotel sees no data.
*   **Rationale:** Step 1 removes the hotel from scope before step 3 lets the
    GET through.
*   ***AI Suggestion:*** PROPOSE FIX
*   ***Justification:*** The finding is valid. As written, Goal 7's read
    access cannot happen.
*   ***Proposed Edit:***
    *   Location: §3 HTTP, steps 1 and 3.
    *   Change:
        *   Step 1: "removes suspended hotels from `TenantContext::hotelIds()`
            and from the current hotel. If the current hotel is removed, the
            first remaining hotel becomes current. An impersonation token keeps
            its full scope;"
        *   Step 3: "lets an impersonation token through on `GET` and `HEAD`
            only. Its suspended hotels stay in scope, so support can read them."
    *   Location: CUJ 4 Then. Add: "In a suspended hotel, a `GET` with the token
        returns the hotel's data, and any other method returns the suspended
        `403`."

### [Issue M3] [`egm-design`] - Severity: Major

*   **Affected Scope:** [Lines 176-181](docs/designs/super-admin-platform.md#L176-L181)
*   **Discrepancy Description:** Which hotel is current is not defined after a
    suspended default hotel is removed, and the hotel list endpoint is not
    named.
*   ***AI Suggestion:*** PROPOSE FIX
*   ***Justification:*** The re-pick rule is covered by the M2 step 1 text.
    CUJ 3 needs the endpoint and the case with no header.
*   ***Proposed Edit:***
    *   Location: CUJ 3 Then. Change it to:
        *   "The user can still log in, and their hotel list
            (`GET /api/hotel`) shows only B."
        *   "A request without `X-Hotel-Id` works in B, even when A is the
            user's default hotel."
        *   "A request with `X-Hotel-Id: A` returns the suspended `403` with
            `body.scope = "hotel"`."

### [Issue M4] [`egm-design`] - Severity: Major

*   **Affected Scope:** [Lines 302-305](docs/designs/super-admin-platform.md#L302-L305), 326–327
*   **Discrepancy Description:** Users with zero hotels are told they are
    suspended.
*   ***AI Suggestion:*** PROPOSE FIX
*   ***Proposed Edit:***
    *   Line 305: "the user has at least one hotel, and every one of them is
        suspended."
    *   Step 2, line 326–327: replace "no hotels remain" with "suspended hotels
        were removed and none remain".
    *   Add after the step list: "A user with no hotels at all keeps today's
        behaviour and is not told they are suspended."

### [Issue M5] [`egm-design`, `egm-style`] - Severity: Major

*   **Affected Scope:** [Lines 80-85](docs/designs/super-admin-platform.md#L80-L85), 170–172, 335–338
*   **Discrepancy Description:** Deleting jobs loses work, skips `failed()`
    cleanup, and contradicts "restores everything".
*   ***AI Suggestion:*** PROPOSE FIX (**design choice:** fail the job, rather
    than delete it or release it)
*   ***Justification:*** Eight jobs have `failed()` handlers that put records
    in a final state. Failing the job keeps that cleanup and needs no new hook
    in each job. Releasing it would keep the work, but the job would reach its
    retry limit during a long suspension.
*   ***Proposed Edit:***
    *   Line 80: "jobs that act on one hotel stop, including ones already
        queued. A stopped job is marked failed with a "hotel suspended" reason,
        so its `failed()` handler puts its records in a final state;"
    *   Line 85: "data is kept. Reactivation restores login, API access, AI
        replies and job processing. Jobs skipped during the suspension are not
        replayed."
    *   Lines 171–172: "...`reactivate` restores access and processing. Skipped
        jobs are not replayed."
    *   Lines 336–338: "When the hotel is suspended, it fails the job with a
        `HotelSuspended` exception instead of running it. The job's `failed()`
        handler then runs. This exception is not reported to Sentry and does not
        count toward the repeated-failure alert. It logs one line."

### [Issue M6] [`egm-design`, `egm-style`] - Severity: Major

*   **Affected Scope:** [Lines 191-194](docs/designs/super-admin-platform.md#L191-L194), 104–105, 394–404
*   **Discrepancy Description:** Attribution is lost in queued work. Expiry
    and logout record no end event. `on_behalf_of` already has another meaning.
*   ***AI Suggestion:*** PROPOSE FIX (**design choice:** accept both gaps as
    v1 risks, R8)
*   ***Justification:*** Zoom-out #7 advised against carrying the impersonator
    into jobs. Sanctum's `sanctum:prune-expired` cannot write events, so the
    claim is narrowed rather than built.
*   ***Proposed Edit:***
    *   Goal 9 (104–105): "...and impersonation start and end (when ended
        through the end endpoint or logout)".
    *   CUJ 4 (191–194):
        *   "Every write in the request is logged with the super admin as actor
            and `context.impersonated_user_id` set to the user."
        *   "`POST /api/superadmin/impersonation/end` or `POST /api/logout`
            ends the session and records an end event. A session that reaches
            30 minutes expires without an end event; the start event records
            when it expires."
    *   §5 End (394–397): after "...records `impersonation.ended`", add:
        "Logging out with an impersonation token records the same event. Expiry
        is enforced by Sanctum's `expires_at` and writes no event; the
        `expires_at` stored on the start event shows when the session ended."
    *   §5 Attribution (401–404): rename `context.on_behalf_of` to
        `context.impersonated_user_id`. `on_behalf_of` already means "the
        person an AI acts for" in `EventLogger`. Replace the last sentence with:
        "Every write that is already audited inside the request gets this
        without changing its caller. Writes made later by jobs the request
        dispatched, such as reservation imports and AI runs, are still recorded
        as the hotel user (risk R8)."

### [Issue M7] [`egm-design`, `egm-style`] - Severity: Major

*   **Affected Scope:** [Lines 405-412](docs/designs/super-admin-platform.md#L405-L412), 98–99, 195–196, 589–590
*   **Discrepancy Description:** `not_impersonating` cannot be applied per
    route as described.
*   ***AI Suggestion:*** PROPOSE FIX
*   ***Justification:*** Verified:
    *   `/connect` is the only authenticated route that creates tokens.
    *   No endpoint changes your own password.
    *   Role and staff-role changes go through `/api/users`.
*   ***Proposed Edit:***
    *   §5 Restrictions: replace with:

```markdown
*   **Restrictions.** An impersonation token cannot:
    *   pair a WhatsApp device. A new `not_impersonating` middleware returns
        403 on `POST /api/connect`, the only authenticated route that creates
        tokens. `POST /api/login` is unauthenticated, so an impersonation token
        never reaches it;
    *   change access. `POST /api/users` and `PUT /api/users/{user}` reject
        `role`, `group_role`, `staff_role_id` and hotel membership fields when
        the token has an `impersonator_id`. `not_impersonating` also covers
        the write routes of `/api/staff-roles`.

    No endpoint changes a user's own password today, so there is nothing to
    block there. The public `POST /api/reset-password` uses no token.
    In a suspended hotel, `not_suspended` limits the token to `GET` and
    `HEAD`.
```

    *   Goal 7 (98–99): "the token cannot pair WhatsApp devices or change any
        user's role, group role, staff role or hotel membership;"
    *   CUJ 4 (195–196): "The token cannot connect WhatsApp or change a user's
        access."
    *   NFR (589–590): "...and cannot pair WhatsApp devices or change user
        access."

### [Issue M8] [`egm-design`] - Severity: Major

*   **Affected Scope:** [Lines 437-442](docs/designs/super-admin-platform.md#L437-L442)
*   **Discrepancy Description:** `platform.*` event types cannot be produced as
    the logger works today.
*   ***AI Suggestion:*** PROPOSE FIX
*   ***Justification:*** Covered by the C3 rewrite: `recordPlatform()` takes
    `event_type`, and there is no `RecordsEvents` on `HotelGroup` or `User`.
*   ***Proposed Edit:*** none beyond C3.

### [Issue M9] [`egm-design`] - Severity: Major

*   **Affected Scope:** [Lines 364-372](docs/designs/super-admin-platform.md#L364-L372)
*   **Discrepancy Description:** The precision of `from` and `to` in the cache
    key is not defined.
*   ***AI Suggestion:*** PROPOSE FIX
*   ***Proposed Edit:***
    *   Lines 364–365: "`from` and `to` are dates (`Y-m-d`, UTC), not
        timestamps. They default to the 30 days ending today, and the range can
        be at most 12 months."
    *   Line 369: "the key is `superadmin:dashboard:v1:{section}:{from}:{to}`
        with both dates as `Y-m-d`, so requests on the same day share one entry
        per section. It uses the default cache store;"

### [Issue M10] [`egm-design`, `egm-structural`, `egm-style`] - Severity: Major

*   **Affected Scope:** [Lines 109-115](docs/designs/super-admin-platform.md#L109-L115), §5
*   **Discrepancy Description:** The success criteria cannot be checked, and
    the impersonation Sentry tag is not designed.
*   ***AI Suggestion:*** PROPOSE FIX (also covers Nit N5, the heading)
*   ***Proposed Edit:***
    *   Location: replace lines 109–115 with:

```markdown
### Success Criteria

*   No `api/admin` route is registered, and the frontend's super admin area
    calls only `api/superadmin`.
*   CUJ 1 passes as an end-to-end feature test with no seeder or SQL step.
*   A route test proves every `auth:sanctum` tenant route has
    `not_suspended`, and a job inventory test proves every hotel job has
    `SkipIfHotelSuspended`.
*   The stage 6 test proves every hotel endpoint returns 403 to a super
    admin's own token.
*   Sentry events from a request or job blocked by suspension carry
    `suspended=true`. Events from a request made with an impersonation token
    carry `impersonating=true` and `impersonator_id`.
```

    *   Location: §5, new bullet after Attribution: "**Sentry.** When the
        current token has an `impersonator_id`, the request's Sentry scope is
        tagged `impersonating=true` and `impersonator_id`, through the existing
        `TagsSentryScope`."

### [Issue M11] [`egm-structural`, `egm-style`] - Severity: Major

*   **Affected Scope:** [Lines 101-103](docs/designs/super-admin-platform.md#L101-L103), 225–227
*   **Discrepancy Description:** Goals, stages and CUJs are not linked.
*   ***AI Suggestion:*** PROPOSE FIX
*   ***Proposed Edit:***
    *   Goal 8, lines 102–103: "This ships last, as stage 6, after stages 2 and
        5."
    *   Location: insert under `## Technical Plan (Architecture)`, before
        `### Request paths after v1`:

```markdown
| Stage | Goals | CUJs | Depends on |
| --- | --- | --- | --- |
| 1. Route move | 1 | — | — |
| 2. Administration and reset access | 2, 3, 4 | 1, 5 | 1 |
| 3. Suspension | 5 | 2, 3 | 2 |
| 4. Dashboard | 6 | 6 | 1, 3 |
| 5. Impersonation | 7 | 4 | 2 |
| 6. Super admins lose hotel access | 8 | — | 2, 5 |
| Audit (every stage) | 9 | 1, 2, 4, 5 | — |
```

### [Issue M12] [`egm-style`] - Severity: Major

*   **Affected Scope:** [Line 190](docs/designs/super-admin-platform.md#L190)
*   ***AI Suggestion:*** PROPOSE FIX
*   ***Proposed Edit:*** "Hotel endpoints apply that user's permissions and
    tenant scope, except for the restrictions below."

### [Issue M13] [`egm-style`, `egm-structural`] - Severity: Major

*   **Affected Scope:** [Line 331](docs/designs/super-admin-platform.md#L331)
*   **Discrepancy Description:** The message says "account" even when the
    scope is a hotel.
*   ***AI Suggestion:*** PROPOSE FIX
*   ***Proposed Edit:*** "The suspended response is `apiResponse($message, 403,
    ['reason' => 'suspended', 'scope' => 'account'|'hotel'])`. The message is
    "This account has been suspended." for `account` and "This hotel has been
    suspended." for `hotel`."

### [Issue M14] [`egm-structural`] - Severity: Major

*   **Affected Scope:** [Lines 229-242](docs/designs/super-admin-platform.md#L229-L242)
*   **Discrepancy Description:** The diagram connectors fall under `tenant`
    and `not_suspended`, one middleware to the left of what they describe.
*   ***AI Suggestion:*** PROPOSE FIX
*   ***Justification:*** Verified: the connectors are at columns 58 and 71.
    Numbered markers cannot drift out of line the way connectors can.
*   ***Proposed Edit:*** Replace the fenced block with:

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

### [Issue M15] [`egm-style`] - Severity: Major

*   **Affected Scope:** [Line 488](docs/designs/super-admin-platform.md#L488)
*   ***AI Suggestion:*** PROPOSE FIX
*   ***Justification:*** Covered by the C2 text for D, which lists all five
    enforcement points.

### [Issue M16] [`egm-style`] - Severity: Major

*   **Affected Scope:** [Lines 555-556](docs/designs/super-admin-platform.md#L555-L556)
*   ***AI Suggestion:*** PROPOSE FIX
*   ***Justification:*** Covered by the C2 text for the K Cons and K reason.
    The cache is kept, because it was the author's decision. Zoom-out #2
    suggested dropping it; that is still open for the author if wanted.

### [Issue M17] [`egm-style`, `egm-design`] - Severity: Major

*   **Affected Scope:** [Lines 611-612](docs/designs/super-admin-platform.md#L611-L612)
*   ***AI Suggestion:*** PROPOSE FIX
*   ***Proposed Edit:*** Replace the last Rollout bullet with:
    *   "Migrations only add nullable columns, so the columns can stay when the
        code is reverted. Reverting has these side effects:
        *   stage 1 must be reverted together with the frontend;
        *   reverting stage 3 lifts every suspension;
        *   reverting stage 5 must also delete tokens named `impersonation`.
            Otherwise they keep working as ordinary user tokens, with no
            restrictions, until they expire."

---

### Nits

| ID | Suggestion | Proposed edit / reason |
| --- | --- | --- |
| N1 | PROPOSE FIX | Open Questions. Item 1: "**(blocks stage 6)** It is not yet verified which of them rely on…". Item 2: add "**(blocks stage 3)**". Item 3: "**(blocks stage 3)** What is the wording, and is it one platform-wide text or set per hotel? Is it sent in the guest's language, or in English only? Staff senders get the same reply." |
| N2 | PROPOSE FIX | Line 366: "**Cached for 15 minutes** (see Alternative K):" |
| N3 | PROPOSE FIX | Lines 333–334: "A route test fails if any `auth:sanctum` route, other than the super admin routes and the logout route, is missing `not_suspended`." |
| N4 | DECLINE | The section names follow the EGM draft template the doc was written from. Renaming them adds nothing a reader needs. |
| N5 | PROPOSE FIX (part) | The Success Criteria heading is done in M10. Keeping the "Proposed solution" paragraph (DECLINE): it is a one-paragraph bridge from the problems to the goals. |
| N6 | DECLINE | Numbered stage headings plus two unnumbered framing headings is readable. The M11 table now gives the stage map. |
| N7 | DECLINE | The rule marks where the feature spec ends and the NFRs begin. It is cosmetic. |
| N8 | PROPOSE FIX | Lines 36–37: "One with a home hotel gets cross-hotel task, arrival and VIP counts … next to single-hotel occupancy." (drop "a confusing mix") |
| N9 | PROPOSE FIX | Lines 52–53: "Nothing separates "platform operator" from "hotel user", and there is no way to see a hotel exactly as its staff see it." |
| N10 | PROPOSE FIX | Line 21: "**Separation.** Super admins lose direct access to hotel endpoints." Goal 8 (101): "**Super admins lose direct access to hotel endpoints.**" Heading 414: "### 6. Super admins lose hotel access (stage 6)" |
| N11 | PROPOSE FIX | Line 49: "can't" becomes "cannot". |
| N12 | PROPOSE FIX | Line 96: "the token expires after 30 minutes, cannot be extended, and can be ended early through an end endpoint;" |
| N13 | PROPOSE FIX | Line 166: "`body.reason = "suspended"` and `body.scope = "account"`" |
| N14 | PROPOSE FIX | Lines 370–372: "the cached value is plain arrays only (no models or objects), following the same rule as `DashboardController`, so reading it back never yields `__PHP_Incomplete_Class`;" |
| N15 | PROPOSE FIX | Line 336: drop "small". Lines 378–379: "`App\Services\Reports\PlatformSummary` owns the queries; the controller only validates, reads the cache and delegates." |
| N16 | PROPOSE FIX | Lines 602–605: replace the last two sentences with "The suspension state is not cached; it is read fresh on every request." and "The job middleware adds one lookup of the hotel and its account per job, with no cache." |
| N17 | PROPOSE FIX | Covered by M5 ("jobs that act on one hotel"). Line 338: "Every job that acts on one hotel declares the middleware in `middleware()`." |
| N18 | CONTEST | Detailed plan 3.4c ([lines 871-874](docs/designs/super-admin-platform.md#L871-L874)) already says "`reset()` clears them, and `runForHotel()` and `withoutScope()` save and restore them". The reviewer was limited to lines 1–613. |
| N19 | DECLINE | 3.4a ([lines 855-860](docs/designs/super-admin-platform.md#L855-L860)) names the three sources. How the query combines them is implementation detail for Stage 9. Zoom-out #3 questions the rewrite altogether. |
| N20 | PROPOSE FIX | §3 Jobs, new sub-bullet: "A job that is already running when its hotel is suspended finishes. The check runs only when a job starts." |
| N21 | PROPOSE FIX | §3 WhatsApp, first sub-bullet: add "The fixed reply is sent at most once per conversation every 24 hours; later messages are stored with no reply." |
| N22 | PROPOSE FIX | §2, new bullet: "Super admin controllers rely only on the route guard. They never call tenant policies, which stop granting super admins anything in stage 6." |
| N23 | DECLINE | It is about the detailed plan (3.13). Stage 9 will review it. |
| N24 | DECLINE | Duplicate of C2. |
