# Stage 7: Critic Review Report

*   **Target:** `docs/designs/super-admin-platform.md`, FeatureRange lines
    1–613
*   **Round:** 1
*   **Reviewers:** all three completed, none failed:
    *   `egm-design` (standard: `feature-spec-standard`)
    *   `egm-structural` (standard: `feature-spec-standard`)
    *   `egm-style` (no separate standard)
*   **Context given:** `concepts_brief.md`, `design_draft.md`
*   **Date:** 2026-11-04

**Result: 3 Blockers, 17 Majors, 24 Nits** after removing duplicates. The
three reviewers raised 73 findings between them (design 21, structural 11,
style 24). Duplicates are merged, and the IDs of the original findings are
listed on each item. The Traceability section at the end maps every raw
finding to an item.

**Checked by the Elephant against the code:**

*   **C3 (data leak): confirmed.**
    *   `ResolveTenant` sets `currentHotelId` to the super admin's
        `hotel_id`, because `available = hotelIds ?? [user->hotel_id]`.
    *   `TenantContext::withoutScope()` restores `currentHotelId` but never
        clears it.
    *   `BelongsToHotel`'s `creating` hook copies `currentHotelId` onto any new
        row whose `hotel_id` is empty.
*   **M1:** `POST /api/register` exists (`routes/api.php:64`).
*   **M14:** the diagram connectors sit at columns 58 and 71. Those columns
    fall under `tenant` (54–59) and `not_suspended` (64–76).

**Earlier stages raised these issues too.** Many Major items repeat findings
from `zoom_out_report.md` and `comprehension_report.md`. Each such item says
so with "Also raised in", so one fix can close all of them.

---

## 🔴 Blockers

### C1. There is no Risks & Mitigations section

*   **IDs:** design-1-B2, structural-1-1
*   **Scope:** the whole spec. The nearest content is the NFRs (583–612).
*   **Problem:** The feature-spec standard requires the spec to name its
    failure modes, security implications and scalability limits, and how each
    is mitigated. Risks appear only as the cons of rejected alternatives. The
    brief's R1–R7 are not carried over as risks. Open Question 1 blocks goal 8
    but has no mitigation and no owner.
*   **Proposed fix:** Add `## Risks & Mitigations` between Alternatives and
    NFRs. Give each risk one row with these columns: Risk | Category | Failure
    mode | Mitigation | Stage. At minimum include:
    *   impersonation token leak or misuse;
    *   tenant-scope queries silently returning zero rows after stage 6;
    *   the stage 1 hard cut failing to deploy, or a partial rollback;
    *   a route or job that misses its suspension check;
    *   leakage of platform audit rows (C3);
    *   loss of work in jobs deleted at suspension (M5);
    *   suspension evasion through `/api/register` (M1);
    *   the unresolved `api/admin` dependence on the `null` scope (Open
        Question 1).

### C2. Alternatives are rejected "by author" with no link, and several give no trade-off

*   **IDs:** design-1-B1, structural-1-2, style-1-N13
*   **Scope:** lines 454, 464, 477, 487, 496, 507, 520, 529, 540, 548, 558,
    567, 577.
*   **Problem:** The standard forbids naming a person as the reason for a
    rejection unless an issue, discussion or PR is linked. Twelve rejections
    say "Rejected by author." and H says "Rejected during brainstorming."
    Several only restate the chosen design and give no reason: A, C, I, J, K,
    and the revenue half of L.
*   **Proposed fix:** Remove the attribution. Rewrite each "Reason for
    Rejection" as one sentence that names the deciding trade-off. For example,
    J: "Each extension widens the window in which a stolen impersonation token
    works, and needs its own endpoint and audit event; restarting a 30-minute
    session costs one call." To keep the attribution, link the brief's
    decision IDs (D1–D10) instead.

### C3. Platform audit rows get the super admin's home hotel and appear on that hotel's event log

*   **IDs:** design-1-B3 (verified)
*   **Also raised in:** zoom-out #4 (on the `Hotel` / `User` subject path)
*   **Scope:** lines 104–107 (Goal 9), 286–288, 437–444.
*   **Problem:** The spec relies on writing platform events "under
    `withoutScope()`". That does not work:
    *   `withoutScope()` leaves `currentHotelId` set.
    *   `ResolveTenant` sets `currentHotelId` to a super admin's own
        `hotel_id` (super admins with a home hotel exist; see line 35).
    *   The `EventLog` creating hook then stamps that hotel onto
        `platform.account.suspended` and the `HotelGroup` rows.

    Those rows then appear on that hotel's tenant event log, which breaks
    Goal 9 and can expose platform data such as `suspended_reason`. The test
    at line 443 checks only rows whose `hotel_id` really is null, so it would
    not catch this.
*   **Proposed fix:** Specify one of these:
    *   (a) the super admin stack does not set `currentHotelId`, checked
        against the existing controllers; or
    *   (b) a dedicated platform write path, such as
        `EventLogger::recordPlatform()`, that forces `hotel_id = null`.

    Add a test in which a super admin who has a home hotel suspends an
    account, and assert that the row's `hotel_id` is null.

---

## 🟡 Major

### M1. Public self-registration is ignored: it already creates accounts, and it lets a suspended customer get around suspension

*   **IDs:** design-1-M1 (verified)
*   **Scope:** lines 39–42, 67–68, 87, 113, 358.
*   **Problem:** "There is no API for creating an account" is false.
    `POST /api/register` creates an admin and a hotel, and `Hotel::booted`
    then creates a `HotelGroup` automatically. As a result:
    *   "extend `/api/register`" is never evaluated as an alternative;
    *   a suspended customer can register again with a new email and get a
        fresh account;
    *   "signups" does not say whether self-registrations count.
*   **Proposed fix:**
    *   Correct the Problem statement.
    *   Add `/api/register` as an alternative.
    *   Decide whether to close it, gate it, or accept the evasion, and record
        that as a risk.
    *   Define signups.

### M2. A read-only impersonation token in a suspended hotel sees no data

*   **IDs:** design-1-M2
*   **Scope:** lines 100, 236–237, 324–328, 411–412.
*   **Problem:** `not_suspended` step 1 removes suspended hotels from the
    scope. Step 3 then lets the impersonation token through on GET, but the
    scope is now `[]`, so every read returns nothing. The read-only support
    access that Goal 7 promises cannot happen.
*   **Proposed fix:** Say that for impersonation tokens step 1 is skipped and
    only the method check applies. Add a CUJ in which a GET in a suspended
    hotel returns data.

### M3. The current hotel is not defined once a suspended default hotel is removed

*   **IDs:** design-1-M3
*   **Scope:** lines 176–181 (CUJ 3), 324–327.
*   **Problem:** With no `X-Hotel-Id`, `ResolveTenant` falls back to the
    user's `hotel_id`. If that hotel is the suspended one, the spec does not
    say what replaces it. "Their hotel list" (line 179) names no endpoint.
*   **Proposed fix:** Choose the first hotel that is not suspended. Name the
    endpoint behind the hotel list. Add a CUJ 3 case with no header.

### M4. Users with zero hotels are told they are "suspended"

*   **IDs:** design-1-M9
*   **Also raised in:** comprehension #3
*   **Scope:** lines 302–305, 326.
*   **Problem:** "Every hotel the user can reach is suspended" and "no hotels
    remain" are both true for a user who has no hotels, for example a new
    employee or the owner of a new account before any hotel exists.
*   **Proposed fix:** Reword both as "has at least one hotel, and all are
    suspended" or "at least one hotel was removed".

### M5. Deleting jobs at suspension loses work, skips `failed()` cleanup, and contradicts "reactivation restores everything"

*   **IDs:** design-1-M4, style-1-M1
*   **Scope:** lines 80, 85, 170–172, 335–342; also Alternative E (490–497).
*   **Problem:**
    *   A deleted job never calls `failed()`. The import, document indexing,
        voucher and proactive-message jobs use `failed()` to put their records
        in a final state, so their records stay "processing" forever.
    *   Queued or delayed work is gone for good, so reactivation does not
        restore everything.
    *   Alternative E was rejected for blocking cleanup paths, and the chosen
        design blocks them too.
*   **Proposed fix:**
    *   Define how each job ends under suspension: `fail()` with a "suspended"
        reason, a release with a delay, or an `onSuspended()` hook.
    *   Reword to "reactivation restores access and processing; work that was
        skipped is not replayed".
    *   Record this as a risk.

### M6. Impersonation attribution is lost in queued work, and expiry or logout record no end event

*   **IDs:** design-1-M5, style-1-M2
*   **Also raised in:** zoom-out #7
*   **Scope:** lines 104–105, 191–194, 394–397, 401–404.
*   **Problem:**
    *   Jobs started from an impersonated request (imports, AI runs) record
        the hotel user as the actor, so the claim "every audited write gets
        this without changing its caller" is false.
    *   Expiry and `/api/logout` write no `impersonation.ended` event, but CUJ
        4 and Goal 9 say an end event is always recorded.
    *   `on_behalf_of` already means "the person an AI acts for" in
        `EventLogger`.
*   **Proposed fix:**
    *   Either carry the impersonator into dispatched jobs, or accept the gap
        as a risk.
    *   Have the prune job and logout record `impersonation.ended` with a
        reason (expired or logout), or narrow the claim.
    *   Rename the key to `context.impersonated_user_id`.

### M7. `not_impersonating` cannot be applied per route as described

*   **IDs:** design-1-M6, style-1-N12
*   **Scope:** lines 98–99, 405–409, 589–590.
*   **Problem:**
    *   Role and staff-role changes go through the general
        `PUT /api/users/{user}`. Route middleware cannot block one field.
    *   No endpoint exists for changing your own password.
    *   "Any endpoint that creates tokens" is not listed.
*   **Proposed fix:**
    *   List the exact routes.
    *   Check the role, group role, staff role and hotel fields inside the
        user requests.
    *   Decide whether editing `/staff-roles` is blocked.
    *   Drop the password item, or point it at the reset endpoint.
    *   Add a route-inventory test for routes that issue tokens.

### M8. `platform.*` event types cannot be produced by `EventLogger` or `RecordsEvents` as they are

*   **IDs:** design-1-M7
*   **Also raised in:** zoom-out #4 and #5
*   **Scope:** lines 437–442.
*   **Problem:** `record()` builds `event_type` from the subject's class name,
    so a `HotelGroup` subject gives `hotel_group.*`. Adding `RecordsEvents` to
    `HotelGroup` and `User` also produces a second set of rows, and makes
    tenant edits to those models into platform rows.
*   **Proposed fix:** Specify the API change (an `eventType` override or a
    prefix hook). Pick one recording mechanism. Decide what tenant-side edits
    to `HotelGroup` produce.

### M9. Dashboard cache key: the precision of `from` and `to` is not defined

*   **IDs:** design-1-M8
*   **Scope:** lines 364–372.
*   **Problem:** If `to` defaults to `now()` with a time, every request gets
    its own key. There are then no cache hits and the number of keys keeps
    growing. The cache store is not named.
*   **Proposed fix:** Accept dates only, normalised to UTC `Y-m-d`, before
    building the key. Name the cache store.

### M10. The success criteria cannot be checked, and the impersonation Sentry tag is never designed

*   **IDs:** design-1-M10, structural-1-4, style-1-M4
*   **Also raised in:** comprehension #4
*   **Scope:** lines 111–115, 350–351, §5 (381–412).
*   **Problem:**
    *   Only `suspended=true` is designed. No `impersonating` tag is designed.
    *   "Failing hotels are spotted in Sentry" is already true today (line
        122) and is not delivered by this work.
    *   None of the criteria can be tested.
*   **Proposed fix:**
    *   Add `impersonating=true` and `impersonator_id` tags to §5.
    *   Replace the criteria with checkable ones, for example:
        *   no `/api/admin` routes are registered;
        *   CUJ 1 passes end to end;
        *   every `auth:sanctum` tenant route carries `not_suspended`, enforced
            by a test;
        *   the stage 6 generic 403 test passes.

### M11. Goals, stages and CUJs are not linked to each other

*   **IDs:** structural-1-5, style-1-N6
*   **Also raised in:** comprehension #6
*   **Scope:** lines 101–103, 244–444, 607–610.
*   **Problem:**
    *   The same ordering rule is stated in goal numbers (line 102) and in
        stage numbers (line 610).
    *   Goal 9 and "Audit (all stages)" belong to no stage.
    *   The CUJs do not say which stage enables them.
*   **Proposed fix:** Add a Stage | Goals | CUJs | Depends on table at the top
    of the Technical Plan. Express ordering rules only in stage numbers.

### M12. "Hotel endpoints behave exactly as they do for that user" contradicts the same CUJ

*   **IDs:** style-1-M3
*   **Scope:** line 190 vs 195, 411.
*   **Proposed fix:** "Hotel endpoints apply that user's permissions and
    tenant scope, except for the restrictions below."

### M13. The suspended message says "account" for hotel-level suspension

*   **IDs:** style-1-M6, structural-1-11 (part)
*   **Scope:** line 331.
*   **Problem:** In CUJ 3 the user's account is active, yet hotel A returns
    "Your account has been suspended."
*   **Proposed fix:** Use a different message for each scope, or one neutral
    string ("Access has been suspended.").

### M14. The architecture diagram attaches each note to the wrong middleware

*   **IDs:** structural-1-3 (verified)
*   **Scope:** lines 231–237.
*   **Problem:** The connectors fall under `tenant` and `not_suspended`, but
    the notes describe `not_suspended` and `no_super_admin`.
*   **Proposed fix:** Move each connector one middleware to the right, or use
    numbered markers with a legend.

### M15. Alternative D understates how many places enforce suspension

*   **IDs:** style-1-M7
*   **Scope:** line 488.
*   **Problem:** It lists three points. The plan also uses `notSuspended()` in
    jobs that loop over hotels, the inbound WhatsApp check, and the
    `SendWhatsAppMessageJob` exception. The understatement is the reason given
    for rejecting D.
*   **Proposed fix:** List all five points.

### M16. Alternative K's database-load reason is not measured and conflicts with the brief

*   **IDs:** style-1-M8
*   **Also raised in:** zoom-out #2
*   **Scope:** lines 555–556.
*   **Problem:** The brief says that with fewer than 50 hotels the queries are
    cheap. No number supports "puts load on the database".
*   **Proposed fix:** Give a measured or expected cost, or reduce the claim.
    Alternatively, reconsider the cache, as zoom-out suggests.

### M17. Rollback is incomplete

*   **IDs:** style-1-M9, design-1-N5
*   **Scope:** lines 611–612.
*   **Problem:**
    *   Rolling back stage 1 also requires rolling back the frontend.
    *   Reverting stage 3 silently lifts every suspension.
    *   Reverting stage 5 leaves live impersonation tokens. They then work as
        ordinary tokens for up to 30 minutes, with no restrictions and no
        attribution.
*   **Proposed fix:** State all three. Add a rollback step that deletes
    `impersonation` tokens.

---

## 🟢 Nits

| ID | Scope | Issue | Proposed fix | Raw IDs |
| --- | --- | --- | --- | --- |
| N1 | 132–143 | Open Questions: item 3's last sentence is a statement, not a question. Item 1 mentions review history ("Reviewers disagreed"). No item has an owner or a stage gate. Item 2 blocks stage 3. | Rewrite item 3 as a question. Use "It is not yet verified". Add "(blocks stage N)" to each item. *Style rated the statement Major. It is listed here because it is a wording fix.* | style-1-M5, style-1-N7, structural-1-10, design-1-N6, design-1-N7 |
| N2 | 366 | "(author decision, which replaces 'live')" refers to an earlier draft. | Drop it, or say "see Alternative K". | structural-1-11, style-1-N9 |
| N3 | 333–334 | The login route is listed among `auth:sanctum` routes, but it is not one. | Reword the exemption list. | structural-1-11 |
| N4 | 27, 225, 61/117 | Section titles differ from the standard's names. | Use `Problem Statement` and `Proposed Architecture`; optionally `Goals & Non-Goals`. | structural-1-6 |
| N5 | 55–59, 109–115 | The solution summary sits inside The Problem, and Success criteria is a bold label, not a heading. | Delete or move the summary. Make Success criteria a heading. | structural-1-7 |
| N6 | 227, 244–414, 435 | Technical Plan H3s mix numbered and unnumbered headings. | Use one convention. | structural-1-8 |
| N7 | 581 | The horizontal rule makes the NFRs look like an appendix. | Remove it, or move it to just before line 614. | structural-1-9 |
| N8 | 37 | "a confusing mix" is subjective. | State the facts only. | style-1-N1 |
| N9 | 52 | "clear boundary" is vague. | "Nothing separates 'platform operator' from 'hotel user'." | style-1-N2 |
| N10 | 21–22, 101, 414 | "Super admins leave hotel endpoints" describes a 403 rule as a behaviour. | "Super admins lose direct access to hotel endpoints (403 without an impersonation token)." | style-1-N3 |
| N11 | 49 | The contraction "can't" in a heading. | Use "cannot". | style-1-N4 |
| N12 | 96 | "short fixed expiry" is vague. | "expires after 30 minutes, cannot be extended, and can be ended early". | style-1-N5 |
| N13 | 166 | `scope` should be `body.scope`. | Use the full path. | style-1-N8 |
| N14 | 370–372 | "incomplete class" is imprecise. | Name `__PHP_Incomplete_Class`. | style-1-N10 |
| N15 | 336, 378–379 | Filler words: "small" and "stays thin". | State what the controller does instead. | style-1-N11 |
| N16 | 602–605 | The NFR text is wordy. "One indexed lookup" is really a join or two lookups. | Shorten it, and say "one primary-key lookup of the hotel and its account". | style-1-N14, design-1-N3 (part) |
| N17 | 80, 338 | "Hotel job" is used before it is defined. | Define it on first use. | style-1-N15 |
| N18 | 315–318 | The new suspension state in `TenantContext` must be cleared in `reset()` and saved and restored in `withoutScope()` / `runForHotel()`. | State it. | design-1-N1 |
| N19 | 311–314 | "One joined query" has to cover three sources (group, pivot, legacy `hotel_id`). | Specify how. *Zoom-out #3 proposes dropping this rewrite.* | design-1-N2 |
| N20 | 604–605 | A running job is not interrupted by a suspension that happens mid-run. | State it. | design-1-N3 |
| N21 | 343–349 | The guest fixed reply has no rate limit. | Send it at most once per conversation per N hours. | design-1-N4 |
| N22 | 261–270, 419–422 | After stage 6, super admin controllers break if they call tenant policies (`HotelPolicy::create()` returns false). | State that they rely only on the route guard. | design-1-N8 |
| N23 | 333–334 vs inventory column | The suspension route check is enforced twice. | Keep one. *(Also zoom-out #9.)* | — (also zoom-out) |
| N24 | 454–456, 535 | Alternative A's reason is circular, and "read naturally" is vague. | Covered by C2. | style-1-N13 (part) |

---

## Traceability

| Raw finding | Consolidated |
| --- | --- |
| design-1-B1 | C2 |
| design-1-B2 | C1 |
| design-1-B3 | C3 |
| design-1-M1 | M1 |
| design-1-M2 | M2 |
| design-1-M3 | M3 |
| design-1-M4 | M5 |
| design-1-M5 | M6 |
| design-1-M6 | M7 |
| design-1-M7 | M8 |
| design-1-M8 | M9 |
| design-1-M9 | M4 |
| design-1-M10 | M10 |
| design-1-N1 | N18 |
| design-1-N2 | N19 |
| design-1-N3 | N16, N20 |
| design-1-N4 | N21 |
| design-1-N5 | M17 |
| design-1-N6 | N1 |
| design-1-N7 | N1 |
| design-1-N8 | N22 |
| structural-1-1 | C1 |
| structural-1-2 | C2 |
| structural-1-3 | M14 |
| structural-1-4 | M10 |
| structural-1-5 | M11 |
| structural-1-6 | N4 |
| structural-1-7 | N5 |
| structural-1-8 | N6 |
| structural-1-9 | N7 |
| structural-1-10 | N1 |
| structural-1-11 | M13, N2, N3 |
| style-1-M1 | M5 |
| style-1-M2 | M6 |
| style-1-M3 | M12 |
| style-1-M4 | M10 |
| style-1-M5 | N1 |
| style-1-M6 | M13 |
| style-1-M7 | M15 |
| style-1-M8 | M16 |
| style-1-M9 | M17 |
| style-1-N1 | N8 |
| style-1-N2 | N9 |
| style-1-N3 | N10 |
| style-1-N4 | N11 |
| style-1-N5 | N12 |
| style-1-N6 | M11 |
| style-1-N7 | N1 |
| style-1-N8 | N13 |
| style-1-N9 | N2 |
| style-1-N10 | N14 |
| style-1-N11 | N15 |
| style-1-N12 | M7 |
| style-1-N13 | C2, N24 |
| style-1-N14 | N16 |
| style-1-N15 | N17 |

**Round status:** not clean. There are unsettled Blocker and Major findings.
