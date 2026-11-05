# Stage 8: Intermediate Zoom Out Report

*   **Target:** `docs/designs/super-admin-platform.md` (lines 1–1299; feature
    spec 1–735, implementation plan 736–1299)
*   **Reviewer:** egm-zoom-out (Skeptical Architect)
*   **Standard:** `zoom-out-standard.md`
*   **Context given:** `concepts_brief.md`, `zoom_out_report.md`
*   **Constraints given:** rollback and backfills are out of scope; mismatches
    between the spec and the not-yet-updated plan are not findings
*   **Date:** 2026-11-05

**Verdict: Needs revision.** 0 Blockers, 8 Major, 5 Nit, plus 6 earlier
findings that still apply (4 Major, 2 Nit).

The context is enough and the direction is sound. The problems are in the
fixes added during Stage 7. Several add machinery for narrow cases, and three
rest on a premise the code contradicts.

**Fix first:** finding 1, then finding 2.

## Corrections to the Stage 7 resolution

The Elephant checked findings 1, 3, 5 and 8 against the code, and all four
hold. Findings 1, 3 and 5 show that text proposed in `goldfish_review_report.md`
and accepted by the author is wrong:

| Text now in the doc | What the code does | Why the error happened |
| --- | --- | --- |
| "No endpoint changes a user's own password" (line 483, from M7) | `PUT /api/users/{user}` can. `password` is fillable (`User.php:19`), `GenericUpdateRequest` builds rules from fillable columns (`ModelColumnRules.php:67`), and the `hashed` cast stores it. | The Elephant searched only `routes/api.php` and did not follow the generic request rules. |
| Skipped jobs' `failed()` handlers put records in a final state (lines 86–88, R6, from M5) | `SendProactiveMessageJob::failed()` only touches `SENDING` rows, and a blocked job's row stays `SCHEDULED`. | The Elephant confirmed that `failed()` exists, but did not read what each handler does. |
| An explicit `hotel_id = null` protects the row from the create hook (lines 513–516, from C3) | The hook runs on `empty($model->hotel_id)`, so an explicit null is overwritten. | The report's wording missed this. The earlier code sketch cleared the current hotel instead, which is the approach that works. |

---

## Applied by the author's approval (2026-11-05)

Findings 1, 2, 3, 5 and 8 are applied to the feature spec. The other findings
are still open. Line numbers below refer to the doc before these edits.

---

## Verdict on each Stage 7 addition

| Addition | Verdict |
| --- | --- |
| Risks & Mitigations (691–704) | Keep. R1 and R6 claim protection that does not hold, and R5 is overstated on one path (findings 1, 3, 5). R3 and R10 add little (finding 13). |
| Success Criteria (120–132) | Revise. They lost sight of the root problems (findings 6, 7, 8). |
| `EventLogger::recordPlatform()` (511–519) | Keep. `record()` cannot produce these names or a null hotel. The stated mechanism is wrong (finding 5). |
| Failing jobs with `HotelSuspended` (86–88, 380–385) | Replace. It adds complexity and does not deliver its promise (findings 2, 3). |
| Impersonation restrictions (473–486) | Simplify. A route-level block is smaller and closes real holes (finding 1). |
| Fixed-reply rate limit (402–403) | Drop. It has no stated reason and blocks the simplest implementation (finding 9). |
| Alternative N (681–689) | Keep unchanged. Its claims check out. |

---

## Major

### 1. The impersonation restrictions rest on a false premise, and a field denylist is the wrong tool

*   **Affected scope:** lines 108–109, 217, 473–486, 695 (R1), 713–714.
*   **Status:** verified by the Elephant.
*   **Why:**
    *   An impersonation token for an admin can set a permanent password or
        email on that admin or a colleague through `PUT /api/users/{user}`.
        `UserPolicy::managesUser` allows it within their hotel. The access then
        outlives the 30 minutes, which R1 says cannot happen. It also breaks
        the brief's D1/D2 decision that password changes are blocked during
        impersonation.
    *   `POST /api/users` has the same hole: with `role` omitted, it creates an
        employee with a chosen password. `DELETE /api/users/{user}` is open too.
    *   The field list adds per-field logic to a tenant controller and must
        grow with every new access-related column. Goal 3 already gives super
        admins these operations directly.
*   **Proposed change:**
    *   Delete the field-rejection rule (478–481) and the sentence at 483–484.
    *   Apply `not_impersonating` to every write method on `/users` and
        `/staff-roles`, plus `/connect`.
    *   State the rule as: "To change users or access, use `api/superadmin`."

### 2. Failing the job is heavier than skipping it and finalizing its records

*   **Affected scope:** lines 86–88, 380–385, 582–585 (Alternative E), 700 (R6).
*   **Why:**
    *   A real job failure writes a `failed_jobs` row, whose payload holds guest
        data, for an expected business outcome.
    *   `AlertOnRepeatedJobFailures` and Sentry each need an exclusion.
    *   Staff-facing reasons do not say "hotel suspended", contrary to line 87.
        `SendGuestRequestNoticeJob:133` hardcodes `SEND_FAILED`, and imports
        report "stopped unexpectedly".
    *   `RetryGuestMemoryExtractionsJob` runs hourly with no hotel filter, and
        `ExtractGuestMemoriesJob` has no `failed()`. Each due row would add a
        `failed_jobs` entry every hour for the whole suspension.
*   **Proposed change:**
    *   The middleware calls `$job->failed(new HotelSuspended)` directly when
        the method exists, logs one line, and returns without `$next`. That
        keeps the cleanup and needs no `failed_jobs` row, listener change or
        Sentry rule.
    *   Update Alternative E's reason to match.
    *   Add `RetryGuestMemoryExtractionsJob` to the `notSuspended()` list.
    *   Drop the promised "hotel suspended" reason at line 87, or name which
        handlers change to deliver it.

### 3. R6 does not hold for proactive messages, which can be sent after reactivation

*   **Affected scope:** lines 86–88, 93–94, 188–189, 394–396, 700 (R6).
*   **Status:** verified by the Elephant.
*   **Why:** `SendProactiveMessageJob::failed()` only touches `SENDING` rows,
    and the row moves to `SENDING` inside `handle()`, which a blocked job never
    reaches. So the row stays `SCHEDULED`. After reactivation,
    `EvaluateProactiveTriggersJob:90-95` dispatches every overdue `SCHEDULED`
    row, including ones that came due during the suspension. Guardrails run
    again at send time, but the reviewer did not establish that they stop all of
    them. This contradicts the author's decision that skipped work is not
    re-run.
*   **Proposed change:** Add one statement to `Suspension`'s reactivate methods
    that sets that hotel's overdue `SCHEDULED` rows to `SKIPPED`, a status that
    already exists (`ProactiveMessageStatus.php:10`), with a suitable reason. If
    the author accepts guardrail-gated sending instead, reword lines 94 and 189
    and R6 to say so.

### 4. The fixed reply invents a route when the job already has one

*   **Affected scope:** lines 158–160, 397–405. Earlier finding 12 also applies.
*   **Why:**
    *   `ProcessInboundWhatsAppMessageJob::replyFor()` already returns fixed
        replies that are stored in `reply_text` and sent with idempotent retry.
    *   `SendWhatsAppMessageJob` has only `phoneNumber` and `text`, with no hotel
        and no message type. It should never have the middleware, so there is
        nothing to skip.
    *   Stopping before `replyFor()` would also stop STOP/opt-out handling.
    *   `UNAVAILABLE_REPLY` already says "Sorry, I can't answer messages right
        now. Please contact the hotel directly for assistance." That is close to
        the brief's draft wording, so Open Question 3 can be closed by reuse.
*   **Proposed change:**
    *   Replace 399–405 with: "`replyFor()` returns `UNAVAILABLE_REPLY` for a
        suspended hotel, after the not-paired and opt-out checks and before any
        AI run. `SendWhatsAppMessageJob` is unchanged and has no suspension
        middleware."
    *   Close Open Question 3 with one English platform-wide text.
    *   The author should confirm the wording suits staff senders, since it
        tells them to contact the hotel.

### 5. An explicit `hotel_id = null` does not do what the Audit section says

*   **Affected scope:** lines 455–456, 511–519, 699 (R5).
*   **Status:** verified by the Elephant.
*   **Why:**
    *   The `BelongsToHotel` creating hook stamps the current hotel when
        `empty($model->hotel_id)`, so an explicit null is overwritten.
    *   On the super admin stack this is harmless, because super admins have no
        hotel and the current hotel is null.
    *   Logging out with an impersonation token runs on the tenant stack, where
        the current hotel is set. If that end event goes through
        `recordPlatform()`, it lands on that hotel's tenant log, which is the
        failure R5 exists to prevent.
*   **Proposed change:**
    *   Write the row inside `TenantContext::runForHotel(null, ...)`, which
        already exists and clears the current hotel.
    *   Replace 513–516 with: "Platform rows always have `hotel_id = null`,
        whatever the subject or current tenant."
    *   Add the logout path to the test at line 523.

### 6. The Success Criteria do not track the four root problems

*   **Affected scope:** lines 120–132, compared with 29–56.
*   **Why:** The criteria measure nothing for the dashboard, that a middleware
    is present rather than that a customer stops, and a Sentry tag rather than
    the `EventLog` row. They could all pass while impersonated writes are logged
    under the wrong actor or a suspended guest still reaches the AI.
*   **Proposed change:**
    1.  No `api/admin` route exists, and the frontend calls only
        `api/superadmin`.
    2.  CUJs 1 to 6 pass as feature tests.
    3.  The route and job inventory tests pass.
    4.  The stage 6 walk passes, with the exceptions in finding 8.

    Delete the Sentry criterion (finding 7).

### 7. The `suspended=true` Sentry tag has almost nothing to attach to

*   **Affected scope:** lines 130–131, 406–407, compared with 383–385.
*   **Why:** A blocked request returns a normal 403 with no exception, and a
    blocked job never runs and its exception is not reported. Only sampled trace
    events could carry the tag (`config/sentry.php:36`, default 0.1), so the
    criterion cannot be reliably tested. The `impersonating` tag is different:
    an impersonated request can raise real errors.
*   **Proposed change:** Delete 406–407 and the first sentence of 130–131. Keep
    470–472.

### 8. As written, stage 6 locks super admins out of `/logout` and `GET /user`

*   **Affected scope:** lines 128–129, 264, 490–492, 506–507.
*   **Status:** verified by the Elephant (`routes/api.php:69-72`).
*   **Why:** Both routes are in the tenant group, and the spec returns 403 on
    every route there, with a test enforcing it. The super admin frontend needs
    both.
*   **Proposed change:** Exempt both routes at 490–492, and state the exemption
    in the criterion at 128–129 and the test at 506–507.

---

## Nit

### 9. Drop the 24-hour fixed-reply limit

*   **Affected scope:** lines 402–403.
*   **Why:** No goal, CUJ or risk gives a reason for it. `UNAVAILABLE_REPLY` at
    the spend ceiling has no such limit, and the webhook already applies a
    per-sender burst limit. `replyFor()` must return a string, so "no reply"
    needs a second exit path and a status that does not exist.
*   **Proposed change:** Delete the two sentences. If there is a concrete reason,
    state it in the Risks table and key the limit by hotel and phone.

### 10. Event names disagree

*   **Affected scope:** lines 450 and 455, compared with 517.
*   **Proposed change:** Use `platform.impersonation.*`.

### 11. "One lookup per job" is understated

*   **Affected scope:** lines 381, 728–729.
*   **Why:** Several jobs carry only a record id, so `hotelId()` must first load
    that row.
*   **Proposed change:** Say "a small indexed lookup per job", or stop counting
    queries here.

### 12. The job inventory rule conflicts with the loop jobs

*   **Affected scope:** lines 126–127, 388–391, 397.
*   **Why:** `EvaluateProactiveTriggersJob` runs `runForHotel` without the
    middleware by design, and `ProcessInboundWhatsAppMessageJob` is deliberately
    excluded.
*   **Proposed change:** Every job has the middleware, or appears on an allowlist
    that states its reason.

### 13. R3 and R10 add little

*   **Affected scope:** lines 697, 704.
*   **Proposed change:** Delete R3 and refer to Rollout. R10 goes away if the
    cache does.

---

## Earlier zoom-out findings: current status

**Still apply:**

| Earlier # | Severity | Current scope | Status and change |
| --- | --- | --- | --- |
| 1 | Major | 416–417, 242 | `/dashboard/usage` still repeats `Admin/UsageController.php:40-42`. Drop it and call the moved endpoint, adding the 12-month cap there if needed. |
| 2 | Major | 245–246, 424–437, 647–657, 704, 721 | The cache remains, and Alternative K now says "Nothing has been measured". Serve live, or clear `counts` on suspension and account creation. This is the author's call. |
| 3 | Major | 346–356, 720–727 | `accessibleHotels()` and `TenantContext::setSuspension` remain, to save one query. Leave `User`, `ResolveTenant` and `TenantContext` alone, and run one query in `EnsureNotSuspended`. |
| 6 | Major | 297–302 | The super admin hotel path still risks copying or skipping the trashed-slug restore and the housekeeping and proactive logic. Limit its update to platform fields, and share creation through a small service. |
| 11 | Nit | 313–315 | The spec still calls for "a reset notification". Use the framework's `ResetPassword::createUrlUsing()`. |
| 13 | Nit | 283–284 | `Superadmin` conflicts with `EnsureSuperAdmin` and `no_super_admin`. Choose one spelling, or keep the namespace. |

**Also still applying, covered above:** 8, in part (finding 2), and 12, for the
`SendWhatsAppMessageJob` exception (finding 4).

**Resolved in the feature spec:** 4 (apart from finding 5), 5, 7 (accepted as
R8), 9.

**Not rechecked:** 10. Its detail is mostly at plan level. Line 310 now uses
"always refuse", so its main concern looks addressed.

---

# Stage 8 re-audit (2026-11-05)

This is the one re-audit allowed for this stage, run after findings 1, 2, 3, 5
and 8 were applied. Line numbers refer to the doc at 1,316 lines (feature spec
1–752, implementation plan 753–1316).

**Verdict: Needs revision.** 0 Blockers, 3 Major, 4 Nit.

| Fix | Result |
| --- | --- |
| 1. `not_impersonating` | Holds for lasting access. It leaves a capability gap (R-N2). |
| 2. Direct `failed()` call | Holds. |
| 3. Reactivation sets `SKIPPED` | **Does not hold as stated** (R-N1). |
| 5. `runForHotel(null)` | The mechanism holds. It leaves an attribution gap (R-N3). |
| 8. `/logout`, `GET /user` exemptions | Holds, with wording gaps (R-N4, R-N7). |

The Elephant checked R-N1, R-N2 and R-N3 against the code, and all three hold.

## Major

### R-N1. The `SKIPPED` step does not stop late sends, and it treats identical cases differently

*   **Affected scope:** lines 94, 189–190, 400–405, 716 (R6).
*   **Why:**
    *   The step covers only rows that already exist at reactivation. No rows
        are inserted during a suspension, because the sweep skips that hotel.
    *   The first sweep after reactivation inserts rows whose `due_at` fell
        inside the suspension, then dispatches them at once
        (`EvaluateProactiveTriggersJob:76-95`). `upcomingActivity` does this for
        a booking still ahead (`ProactiveTriggerFinder:105-127`).
    *   So take two guests with the same booking time. If the row existed, it
        becomes `SKIPPED` and can never be inserted again, so that guest loses a
        reminder that was still valid. If the row did not exist, it is inserted
        and sent late. "So they are not sent late" in R6 is false for the second
        guest.
    *   `ProactiveGuardrails::check` already limits late sends at send time:
        `valid_until`, opted-out guests, the WhatsApp window, quiet hours, the
        daily cap, and whether the booking is still confirmed.
    *   `ProactiveMessageObserver` writes `guest_outreach` on model saves, so a
        bulk update would silently skip it.
*   **Proposed change (author's call, because it reverses an approved fix):**
    *   Remove the `SKIPPED` step at 400–405 and the new `ProactiveSkipReason`
        case.
    *   Replace it with: "Proactive messages still valid at reactivation may be
        sent late. Send-time guardrails decide, including `valid_until`, the
        WhatsApp window and quiet hours."
    *   Reword lines 94 and 190 to exclude proactive messages, and update R6.
    *   A strict "nothing due during a suspension is sent" would need a stored
        reactivation time and a change to the finder. The reviewer recommends
        against it.

### R-N2. After stage 6, a super admin has no way to write staff roles, and line 493 says otherwise

*   **Affected scope:** lines 305–308, 487, 493; also 112–114, 499–502.
*   **Why:** Line 493 says super admins change users or access through
    `api/superadmin`, but that area has no staff-role endpoints, and
    `routes/admin.php` has none to move. After stage 6 the super admin's own
    token gets 403 on `/api/staff-roles`, and the impersonation token is blocked
    there too.
*   **Proposed change:** Keep the block. Add to Non-Goals and line 493: "Staff
    role definitions are changed only by the hotel's own admins. Super admins
    can assign an existing role but cannot create or edit one in v1." If support
    needs this, add it to `Superadmin` in stage 2 instead.

### R-N3. The logout end event can name the hotel user as actor, not the super admin

*   **Affected scope:** lines 215–216, 465, 472–474, 522–531, 539–540.
*   **Why:** The actor swap is specified only for `record()`, which takes the
    actor from `Auth::user()` (`EventLogger.php:100`). `recordPlatform()` is a
    new method with no actor rule. On `POST /logout` with an impersonation token,
    `Auth::user()` is the hotel user, so the end event could show no sign of the
    super admin. The test at 538–540 checks only `hotel_id`.
*   **Proposed change:** Add: "`recordPlatform()` resolves the actor the same way
    as `record()`, including the impersonator swap. The in-memory token still has
    `impersonator_id` after `apiLogout` deletes it." Extend the test to assert the
    actor and `context.impersonated_user_id`.

## Nit

*   **R-N4 (126–127, 266, 271–274, 359–360, 380–381, 499–502).** The exemptions
    are described three ways, with no mechanism. The body of §3 HTTP and footnote
    [1] never mention the logout exemption, and without it CUJ 4 line 215 fails in
    a suspended hotel. State the route structure once: `/logout` under `tenant`
    alone, `GET /user` adding `not_suspended`, everything else nested with
    `not_suspended` and `no_super_admin`.
*   **R-N5 (94, 190, 392–394).** `RetryGuestMemoryExtractionsJob` queries
    extraction rows, not hotels, so `notSuspended()` does not fit directly. Due
    retries resume after reactivation, which contradicts "not replayed". Say the
    job filters its rows to non-suspended hotels and that retries resume.
*   **R-N6 (385–387).** The import jobs' `failed()` handlers call `Log::error`. If
    production logs to Sentry, as the local `.env` does, each blocked import sends
    one error-level entry. Record it as accepted.
*   **R-N7 (382–383, 501, 505–506).**
    *   `IndexKnowledgeDocumentJob` also handles global documents with no hotel,
        so a null hotel id must mean the job runs.
    *   Removing the branch in `User::permissions()` makes `GET /user` and login
        return `permissions: []` for super admins. The frontend repository was
        not available, so this is unconfirmed.

## Still open from the first report

*   **Intermediate report:** 4, 6, 7, 9, 10, 11, 12, 13.
*   **Earlier zoom-out table:** 1, 2, 3, 6, 11, 13.

## Outcome

The author approved R-N1, R-N2 and R-N3, and they are applied to the feature spec. The `SKIPPED` step from finding 3 is removed. The other open findings are recorded as accepted for now: R-N4 to R-N7, intermediate 4, 6, 7, 9, 10, 11, 12, 13, and earlier zoom-out 1, 2, 3, 6, 11, 13.
