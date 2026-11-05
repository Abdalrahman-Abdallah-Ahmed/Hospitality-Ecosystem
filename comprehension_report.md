# Stage 6: Comprehension Test Report

*   **Target:** `docs/designs/super-admin-platform.md` (FeatureRange 1–613,
    DetailedRange 614–1177)
*   **Reviewer:** egm-comprehension (naive reader). It read the document alone,
    with no standard and no context files.
*   **Date:** 2026-11-04

**Result:** No blockers, 5 Major, 6 Nit. The reviewer could paraphrase every
section, and the parts connect logically.

## What this proposes

The document creates a separate API area, `api/superadmin`, for the internal
team that runs the platform. All existing `api/admin` routes move there in one
breaking release. In that area a super admin can:

*   create and edit accounts (`HotelGroup`), their hotels and their users who
    are not super admins;
*   reset a user's access, which sends a password reset email, deletes the
    user's tokens and unpairs their WhatsApp device;
*   suspend or reactivate a whole account or a single hotel.

Suspension is stored in new `suspended_*` columns that only a super admin
service can write. It blocks login and existing tokens with a machine-readable
403. It skips queued and scheduled hotel jobs, and it replaces the WhatsApp AI
with a fixed reply. A platform dashboard shows counts, AI usage and cost, and
activity per hotel, cached for 15 minutes.

Super admins get short-lived (30-minute) impersonation tokens issued to a hotel
user. The audit log records the super admin as the actor and the hotel user as
`on_behalf_of`. As the last step, super admins lose direct access to hotel
endpoints, and the roughly 45 `isSuperAdmin()` bypasses are removed. The work
ships as six ordered stages, each with a file-by-file plan.

## How the existing system works, per this document

Today a super admin is a hotel admin with no limits. Their tenant scope is
`null` (unrestricted), and about 25 policies short-circuit for them through
`before()`. Super-admin-only routes live in `routes/admin.php` under
`api/admin`, behind a file-level `super_admin` guard. The hotel dashboard gives
super admins either a 403 or a confusing mix of cross-hotel and single-hotel
figures.

There is no API to create accounts. Hotel creation works only through the
bypass, so onboarding takes seeders or SQL. The only flag is `is_active`: hotel
admins can change it themselves, jobs read it for a different purpose, and
`HotelGroup.is_active` is never read, so nothing can suspend a customer. Every
request goes through `ResolveTenant`, which loads the user's accessible hotels
into `TenantContext`. Audited writes go to `EventLog` through `EventLogger`,
but the log shows only that a super admin acted. It cannot show which hotel
user's view they were working in.

---

## Findings

### Major

#### 1. "Admin Advisor" and "WhatsApp device" pairing are never defined

*   **Lines:** 47–48, 75, 81, 98, 205–206, 348, 406, 575–576.
*   **Problem:** These two concepts drive several design decisions:
    *   revoking tokens must also unpair the device (Goal 4, Alternative M);
    *   impersonation tokens may not call `/connect`;
    *   staff senders get the fixed reply.

    A reader has to piece together from Alternative M (575–576) and line 348
    that staff pair a WhatsApp number to their user, and that this gives them
    an AI assistant ("Admin Advisor") that works without an API token. Nowhere
    does the document say that the paired device is a second way into the
    system that does not depend on an API token. Without that, the reason
    revoke must also unpair is unclear.
*   **Suggested fix:** Add a one- or two-sentence definition of the AI
    concierge, the Admin Advisor and device pairing in the Overview or the
    Problem section.

#### 2. The client-side flow for impersonation is not described

*   **Lines:** 94–100, 183–196, 385–400, 1020–1021.
*   **Problem:** The document never says how the frontend uses the
    impersonation token or how the super admin gets back out. Open points:
    *   Does the frontend swap its bearer token?
    *   Does the super admin's own token stay valid alongside it?
    *   Which token calls `POST /api/superadmin/impersonation/end`? It must be
        the super admin's own token, because the impersonation token belongs
        to a hotel user and would fail the `super_admin` guard.

    Line 395 says only that the end call "takes the impersonation token id".
    It also does not say whether one super admin can end another's session. A
    reader cannot trace a whole support session in CUJ 4.
*   **Suggested fix:** Describe the token handling on the client in CUJ 4 or
    Technical Plan §5.

#### 3. Behaviour for users who have no hotels is ambiguous

*   **Lines:** 303–305, 326, 890–891.
*   **Problem:** Login refuses when "every hotel the user can reach is
    suspended", and `not_suspended` returns the suspended 403 when "no hotels
    remain". Read literally, both are also true for a user who has zero hotels.
    An example is the account owner of a new account before any hotel exists
    (the order in CUJ 1). That user would be told "suspended" when nothing is
    suspended. Line 34 says such users currently get "You do not belong to any
    hotel".
*   **Suggested fix:** Say whether "no hotels remain" means "had hotels and all
    were removed as suspended" or "has none at all".

#### 4. One success criterion has nothing behind it

*   **Lines:** 114.
*   **Problem:** "Failing hotels are spotted in Sentry" is listed as a success
    criterion, and the Non-Goals and Alternative L rely on Sentry for health.
    But no part of the design adds per-hotel signals to Sentry. The only Sentry
    work is the `suspended=true` tag (350–351, 851), and no impersonation tag
    is ever specified. A reader cannot tell what makes this criterion true, or
    whether it already holds today.
*   **Suggested fix:** Either say this already holds today and needs no work,
    or list the Sentry changes, including the impersonation tag.

#### 5. The "`null` vs `[]` rule in CLAUDE.md" is relied on but never explained

*   **Lines:** 428, 519; related: 136, 241, 318.
*   **Problem:** This rule is used to reject Alternative G and to justify
    keeping `accessibleHotelIds()` returning `null`. Line 241 implies `null`
    means unrestricted. What `[]` means, and why the rule is dangerous to touch
    ("queries that return zero rows without any error", 518), has to be
    inferred.
*   **Suggested fix:** State the rule inline in one sentence, or link to the
    relevant CLAUDE.md section.

### Nit

#### 6. Goals are never mapped to stages

*   **Lines:** 101–103 vs 607–610.
*   **Problem:** Goal 8 "ships last, after goals 2–4 and 7". Rollout says
    "Stage 6 must not ship before stages 2 and 5". These agree only once the
    reader works out that goals 2–4 are stage 2, goal 5 is stage 3, goal 6 is
    stage 4, goal 7 is stage 5 and goal 8 is stage 6.
*   **Suggested fix:** Add "(stage N)" next to each goal, or a small mapping
    table.

#### 7. The dashboard range is stated two ways, and the request class has two names

*   **Lines:** 92–93, 364–365, 976–980.
*   **Problem:** The feature section says "at most 12 months"; the detailed
    plan says "at most 366 days". Line 365 names the request class
    `SuperadminDashboardRequest`, but 4.3 names it `DashboardRequest`.
*   **Suggested fix:** Use one limit and one class name throughout.

#### 8. The third Open Question reads as a statement

*   **Lines:** 141–143.
*   **Problem:** "It is sent in the guest's language or in English only" seems
    to mean "Is it sent in the guest's language, or in English only?". Also,
    3.12 (929–932) already puts the text in `lang/en/suspension.php`, which
    partly answers the question. The question does not mention that staff
    senders get the same reply (349, 927).
*   **Suggested fix:** Rephrase it as a question, and mention the staff
    senders and 3.12.

#### 9. Counts in the summary and the lists don't match

*   **Lines:** 50, 429, 516, 1088–1097, 1122–1133.
*   **Problem:**
    *   Policy bypasses: "about 25" in the text, but 27 policies are listed in
        6.3.
    *   Test files: "about 33" in the text, but 24 are listed in 6.5, plus
        helpers.

    The text says "about", but a reader checking completeness cannot tell
    whether the lists are partial.
*   **Suggested fix:** Use the listed counts, or say the lists are partial.

#### 10. Several project-specific terms are used without a short explanation

*   **Terms:**
    *   `api.key` middleware (231, 247)
    *   `TenantContext::runForHotel()` (340, 490)
    *   `withoutScope()` (287, 441)
    *   `RecordsEvents` (442, 763, 767)
    *   `ActorKind` (533–539, 1027)
    *   `X-Hotel-Id` and "the current hotel" (180, 324–327)
    *   the job features "insights, proactive triggers, housekeeping day,
        expiry, outcomes" (341–342)
    *   the "cache key is `all`" detail (36)
*   **Problem:** Most can be guessed from context, but not all.
*   **Suggested fix:** A short glossary would let the document stand on its own.

#### 11. Reset access and password reset revoke differently

*   **Lines:** 74–75, 750–753, 575–579.
*   **Problem:** Alternative M says that after tokens are revoked, a device
    that is still paired would keep Admin Advisor access, so revoking must also
    unpair it. Yet the public `POST /api/reset-password` (2.6) deletes all
    tokens on success and says nothing about the WhatsApp device. A reader
    cannot tell whether that gap is intended.
*   **Suggested fix:** Say whether a completed password reset also unpairs the
    device.
