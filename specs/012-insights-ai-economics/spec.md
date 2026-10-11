# Feature Specification: Insights Citations and AI Economics Verification

**Feature Branch**: `012-insights-ai-economics`

**Created**: 2026-10-11

**Status**: Draft

**Input**: Phase 12 — Insights + AI Economics verification, read from
docs/AI_Hospitality_Ecosystem_Implementation_Plan_v1_0.md. Covers the phase's two backlog
specs as one feature: SPEC-090 Insights Citations and SPEC-091 Usage Visibility Audit, plus
the phase's third item: metering for the AI operations added in Phases 7–11. Follows the
constitution's "AI Measurement, Usage, and Cost" rules (provider cost is never shown to
hotel admins) and plan §2.14 (insights, usage and cost are KEEP — verify, don't rebuild).

## Overview

The platform already produces AI insights for hotel staff, meters what each account uses,
and records what every AI call costs the platform. Phases 7–11 then added a lot of new AI
work — the Concierge's guest-service and booking tools, document reading for the knowledge
base, the recommendation approval flow, proactive WhatsApp messages, and guest memory
extraction. This phase checks that the measurement layer kept up, and closes the gaps
where it did not:

- **Insights tell you where they came from.** Every insight already carries an evidence
  level (fact, strong inference, hypothesis, unverified). Insights can now also draw on
  the hotel's knowledge base (policies, articles, documents) and, when they do, they name
  the exact sources, the same way the Concierge cites them. An insight never claims to
  be based on something it was not based on.
- **Hotel admins see their usage — never our cost.** Hotel admins can see how much AI
  their account used: requests, tokens, and per-feature counts, broken down by AI
  capability. They never see provider cost, prices, margin, or anything from which they
  could calculate them. This is verified by automated checks across every hotel-facing
  surface, including the Admin AI.
- **Super admins see the full economics.** Super admins see cost, model, AI capability
  (agent), account, and margin for every account, so they can tell which capability drives
  the bill.
- **Every AI operation is counted.** Each AI capability added in Phases 7–11 is metered
  and cost-attributed, and an automated check fails when a new AI capability ships without
  both.

What is already in place and is **kept unchanged**: the four evidence levels and how they
are assigned, the insights list and generation endpoints and their permissions, the
append-only usage events and counters, effective-dated model pricing, the per-call cost
recorder, the daily spend ceiling, the per-run AI activity log, and the super-admin usage
and cost reports.

## Clarifications

### Session 2026-10-11

- Q: Should hotel admins see which AI model and provider handled their requests? → A: No —
  super-admin only. Hotel admins see requests and tokens per AI capability, with no model or
  provider names, since model + tokens would let them derive provider cost.
- Q: Should guest memory extractions that ran before the new usage feature ships be
  backfilled? → A: No backfill. Counting starts at deployment, and usage reports state the
  date measurement of that feature began.

## User Scenarios & Testing *(mandatory)*

### User Story 1 - Hotel admin sees AI usage without provider cost (Priority: P1)

A hotel admin opens the usage page for their account and sees, for a chosen date range,
how many AI requests were made, how many tokens they used, and how that splits across AI
capabilities (Concierge, Admin AI, insights, recommendations, knowledge indexing, document
reading, guest memories). They also see the existing per-feature counts (AI messages,
recommendations generated and delivered, proactive messages sent, bookings created). Nowhere
on the page — or in any other hotel-facing response, or anything the Admin AI tells them —
is there a cost, price, currency amount for AI, margin, provider, or model name.

**Why this priority**: The constitution makes provider-cost confidentiality a MUST. A leak
is a commercial problem (an account that sees its cost of service can compute the
platform's margin on its contract) and cannot be undone once seen. Showing usage is also
the visible value of this phase for customers.

**Independent Test**: Generate AI activity for two accounts, then call every hotel-facing
endpoint as a hotel admin and as an employee, and ask the Admin AI about usage and cost.
Usage figures must match the recorded activity for the admin's own account only, and no
response may contain any cost, price, margin, provider or model field or value.

**Acceptance Scenarios**:

1. **Given** an account whose hotels made 120 AI requests using 450,000 tokens this month,
   **When** its hotel admin views usage for this month, **Then** they see 120 requests and
   450,000 tokens, split by AI capability, and the per-capability figures add up to the
   totals.
2. **Given** the same account, **When** the hotel admin views usage, **Then** the response
   contains no cost, price, margin, currency amount for AI, provider, or model value.
3. **Given** two accounts with AI activity, **When** a hotel admin of account A views
   usage, **Then** only account A's figures are included, and there is no way to request
   account B's figures.
4. **Given** an employee without account-level standing, **When** they request account
   usage, **Then** they are refused (unchanged rule: account usage is for admins and
   group-role holders).
5. **Given** a hotel admin, **When** they ask the Admin AI "how much did the AI cost us this
   month?", **Then** the Admin AI answers with usage counts only and states that cost is
   not available to them — it does not estimate, derive, or reveal a cost.
6. **Given** a hotel admin listing the AI activity log, **When** the entries are returned,
   **Then** they carry no cost, while the same list for a super admin includes each run's
   cost (unchanged behaviour, now covered by the visibility check).
7. **Given** a guest-facing reply, a notification, an error message (including the daily
   spend-ceiling message) or an export, **When** it is produced, **Then** it contains no
   cost, price or margin figure.

---

### User Story 2 - Every AI operation is metered and cost-attributed (Priority: P1)

Every AI capability the platform runs — including those added in Phases 7–11 — records
what it used (a usage event in the hotel's account) and what it cost (a cost record tagged
with the capability, the trigger kind and the account). When a developer adds a new AI
capability and forgets either, an automated check fails before the change can ship.

**Why this priority**: Usage and cost reports are only as true as what feeds them. A
capability that is not metered is invisible to the customer; one that is not
cost-attributed shows up as "unattributed" spend that no one can explain. Phases 7–11
added several capabilities, so this is where the reports are most likely to have drifted.

**Independent Test**: Run each AI capability once (Concierge turn with a service request,
Admin AI turn, insights, recommendation generation, inline pitch generation, knowledge sync,
document reading, guest memory extraction, proactive message) and confirm each one produced
a usage event in the right account and a cost record tagged with the right capability and
trigger kind. Confirm the "unattributed" capability and trigger counts are zero.

**Acceptance Scenarios**:

1. **Given** a guest who has checked out of their last room on a reservation, **When**
   guest memory extraction runs for them, **Then** one "guest memory extractions" usage
   event is recorded for the hotel's
   account, and its AI cost is attributed to the guest memory capability under the
   scheduled-job trigger.
2. **Given** a hotel uploads a scanned document to the knowledge base, **When** the document
   is read and indexed, **Then** the pages read and the chunks indexed are counted, and the
   reading cost is attributed to the document reading capability.
3. **Given** each AI capability the platform defines, **When** the automated coverage check
   runs, **Then** every capability is mapped to at least one usage feature and to a cost
   attribution, and the check fails naming any capability that is not.
4. **Given** all AI capabilities have run in a test, **When** the cost records are
   inspected, **Then** none is tagged as unattributed capability or unattributed trigger.
5. **Given** a queued AI job is retried after a failure, **When** it finally succeeds,
   **Then** its usage is counted once, not once per attempt (cost records may still show
   each attempt, since each attempt was paid for, and are marked as retries).
6. **Given** a usage feature with no source to measure yet (such as "conversations
   handled"), **When** usage is reported, **Then** it is shown as "not measured" with the
   reason, never as zero.

---

### User Story 3 - Insights cite the knowledge sources they used (Priority: P2)

A hotel admin or a permitted employee generates insights. Some insights are now informed by
the hotel's knowledge base — for example, "Late check-out requests are rising, but the
published policy still says late check-out is not offered." Each such insight lists the
knowledge sources it relied on, by title and location (page, sheet or section), and whether
the source is the hotel's own or shared general guidance. Staff can open the source to check
it. Insights that used no knowledge show no citations. The insight's evidence level is
still shown and still reflects the operational data behind it.

**Why this priority**: Citations make insights checkable and match how the Concierge already
answers from knowledge (Phase 8). It depends on no other story, but it carries less risk
than a cost leak or missing metering, so it is P2.

**Independent Test**: Seed a hotel with a policy and reservations that contradict it,
generate insights, and confirm that an insight referencing the policy lists it as a
citation, that the cited source belongs to this hotel or to global knowledge, and that
insights not based on knowledge carry no citations.

**Acceptance Scenarios**:

1. **Given** a hotel policy "No late check-out" and several recent late check-out requests,
   **When** insights are generated, **Then** an insight about late check-out cites the
   policy with its title and section, marked as the hotel's own source.
2. **Given** an insight grounded in a specific reservation and also citing a policy,
   **When** it is listed, **Then** it shows its evidence level as a strong inference (from
   the verified reservation) and lists both the reservation as evidence and the policy as a
   citation, kept separate.
3. **Given** an insight grounded only in knowledge and not in any hotel record, **When** it
   is saved, **Then** its evidence level is at most hypothesis level.
4. **Given** the AI names a knowledge source that does not exist, belongs to another hotel,
   or was not returned by its knowledge search during this run, **When** the insight is
   saved, **Then** that citation is dropped, and the insight is kept only if it still has
   valid grounding; it never shows a citation that cannot be traced.
5. **Given** a cited source is later deleted or deactivated, **When** the insight is listed,
   **Then** the citation is still shown with its recorded title and marked as no longer
   available, rather than disappearing silently or pointing to nothing.
6. **Given** a hotel with knowledge sources and another hotel with different ones, **When**
   the first hotel's insights are generated, **Then** they never cite the second hotel's
   sources.

---

### User Story 4 - Super admin sees cost by capability, model, account and margin (Priority: P2)

A super admin opens the AI cost report and sees, per account, the provider cost, number of
calls, cost per call, and breakdowns by trigger kind, by model, by hotel and — new — by AI
capability. Where a contract value is recorded, gross margin is shown; where it is not,
margin is empty with the reason. The report and the usage report use the same capability
names, so a super admin can put "what the customer used" next to "what it cost us".

**Why this priority**: Most of this report already exists. The missing piece is the
per-capability breakdown, which is what tells the business whether, for example, guest
memories or document reading are worth what they cost.

**Independent Test**: Generate AI activity across several capabilities for two accounts and
confirm that each account's per-capability costs add up to its total, that every
capability that ran appears, and that the report is refused to anyone who is not a super
admin.

**Acceptance Scenarios**:

1. **Given** an account with Concierge, insights and guest memory activity this month,
   **When** a super admin views the cost report, **Then** the account shows a cost line for
   each of those three capabilities, and their sum equals the account's total cost.
2. **Given** an account with no recorded contract value, **When** the report is viewed,
   **Then** margin is empty and a reason is given — never zero and never a guess.
3. **Given** a hotel admin or employee, **When** they request the cost report or the
   cross-account usage report, **Then** they are refused.
4. **Given** a model used on a day with no price on file, **When** the report is viewed,
   **Then** that model's calls are listed as unpriced with a note (unchanged), and the
   affected capability's cost is marked as estimated.

---

### Edge Cases

- **Usage period with no AI activity**: every capability and feature is listed with zero
  (or "not measured" where there is no source), never omitted.
- **Admin managing several hotels in one account**: usage is shown for the whole account,
  with a per-hotel split; the admin can't see hotels outside their account.
- **Super admin viewing the hotel-facing usage page**: they see the same cost-free view;
  cost lives only in the super-admin report, so the hotel-facing response stays identical
  for every caller.
- **AI call that failed at the provider**: tokens actually billed are counted; a call that
  was never sent (for example, blocked by the spend ceiling) counts no tokens and no
  request.
- **Spend ceiling reached**: the guest and staff see a neutral "temporarily unavailable"
  message; nothing in the message reveals the ceiling amount or the spend so far.
- **Insight generated while knowledge search is unavailable**: insights are still produced
  from operational data, with no citations, and the run does not fail.
- **Guest-message-derived insight citing knowledge**: guest messages are still summarized
  as themes, never quoted, whether or not knowledge is cited.
- **Global (shared) knowledge cited in a hotel's insight**: allowed, and marked as general
  guidance; the hotel's own source is preferred when both say different things (same
  precedence as the Concierge).
- **Old insights created before this feature**: they show no citations and keep their
  evidence level; nothing is backfilled or re-generated.
- **Usage range that starts before a feature was measured**: the report shows the feature's
  "measured since" date, so a range from before then is not read as zero activity.
- **Historic usage events**: feature codes already recorded are never renamed, so earlier
  months still add up; new capabilities get new codes.

## Requirements *(mandatory)*

### Functional Requirements

**Insights citations (SPEC-090)**

- **FR-001**: Insights MUST keep the existing four evidence levels, and the existing rule
  for assigning them (a verified hotel record → strong inference; otherwise hypothesis).
- **FR-002**: The insights capability MUST be able to search the hotel's knowledge base
  (hotel and global sources, with the same hotel-over-global precedence and the same
  audience rules the staff-facing Admin AI uses) when generating insights.
- **FR-003**: Every insight that used knowledge MUST store its citations: for each source,
  its type, identity, title, location (page, sheet or section, where available), scope
  (hotel or general), and the date the source was last updated, as of generation time.
- **FR-004**: A citation MUST be kept only if the source was returned by a knowledge search
  during the same insights run, still exists, and belongs to this hotel or to global
  knowledge. Any other citation MUST be discarded before the insight is saved.
- **FR-005**: Citations MUST be stored and shown separately from evidence (the operational
  records an insight is about). Citing knowledge MUST NOT raise an insight's evidence level;
  an insight grounded only in knowledge MUST be at most hypothesis level.
- **FR-006**: The insights list MUST return each insight's citations, and MUST mark a cited
  source that has since been deleted or deactivated as no longer available while keeping its
  recorded title.
- **FR-007**: An insight that used no knowledge MUST return an empty citation list (not a
  missing field), so the frontend can render both cases the same way.
- **FR-008**: If knowledge search fails during generation, insights MUST still be produced
  from operational data without citations, and the failure MUST be logged.
- **FR-009**: Viewing and generating insights MUST keep their existing permissions
  (`ai_insights.view`, `ai_insights.generate`); no new permission is added for citations.

**Usage visibility (SPEC-091)**

- **FR-010**: The hotel-facing account usage report MUST show, for the chosen date range:
  total AI requests, total tokens (input and output), and the same two figures per AI
  capability, in addition to the existing per-feature counts and seat counts. It MUST NOT
  break usage down by model or provider.
- **FR-011**: Per-capability and per-hotel figures MUST add up exactly to the account
  totals for the same range.
- **FR-012**: No hotel-facing response, AI tool result, Admin AI answer, guest message,
  notification, error message or export MUST contain provider cost, model price, cost per
  call, margin, contract value, provider name or model name. This MUST be enforced by an
  automated check that covers every hotel-facing endpoint and every Admin AI tool.
- **FR-013**: The Admin AI MUST answer cost questions from hotel admins by saying cost is
  not available to them, and MUST NOT estimate cost from token counts or any other figure.
- **FR-014**: The hotel-facing usage report MUST stay limited to the caller's own account,
  with no parameter that selects another account (unchanged rule, now covered by the
  visibility check), and MUST stay restricted to admins and account-level role holders.
- **FR-015**: The super-admin cost report MUST add a per-capability breakdown (cost, calls,
  tokens) for each account, alongside the existing breakdowns by trigger, model and hotel,
  and the existing margin, cost basis and currency notes.
- **FR-016**: Capability names MUST be the same in the hotel-facing usage report, the
  super-admin usage report and the super-admin cost report.
- **FR-017**: The super-admin usage and cost reports MUST stay reachable only by super
  admins.

**Metering for Phases 7–11 operations**

- **FR-018**: Every AI capability the platform runs MUST be counted by a usage feature (its
  own, or the feature of the operation it serves — e.g. turn-signal classification counts
  under the Concierge turn it belongs to) and MUST produce cost records tagged with that
  capability, its trigger kind, and its account. Specifically, these MUST be covered: Concierge turns (including service requests,
  bookings, cancellations and escalations), Admin AI turns (web and WhatsApp), turn-signal
  classification, insights, recommendation generation (scheduled and inline), proactive
  messages, knowledge indexing, document reading, and guest memory extraction.
- **FR-019**: A new usage feature "guest memory extractions" (unit: extractions) MUST be
  added, recorded once per guest per reservation when the AI extraction completes, whether
  or not any memory was found. Extractions skipped before any AI call (no messages, feature
  switched off) are not counted. It MUST NOT be backfilled for extractions that ran before it shipped; usage reports
  MUST state the date from which it is measured, so earlier months are not read as zero
  activity.
- **FR-020**: New usage features MUST get new permanent codes; existing codes MUST NOT be
  renamed or reused.
- **FR-021**: Usage events from retried jobs MUST be counted once per completed operation,
  not once per attempt.
- **FR-022**: An automated check MUST fail when any defined AI capability lacks a usage
  feature mapping or cost attribution, naming the capability.
- **FR-023**: After every capability has run in the automated suite, the count of cost
  records tagged as unattributed capability or unattributed trigger MUST be zero.
- **FR-024**: Usage features that still have no measurable source MUST continue to be
  reported as "not measured" with a reason, never as zero.

**Cross-cutting**

- **FR-025**: All usage, cost and insight data MUST stay hotel/account scoped: no hotel can
  see another account's usage, insights or citations, in HTTP responses, AI tools or jobs.
- **FR-026**: The insights and usage API documentation MUST be updated, and any change to
  existing response shapes summarized in a latest-changes note.

### Key Entities *(include if feature involves data)*

- **AI Insight** *(existing, extended)*: A short, actionable observation for hotel staff.
  Keeps title, description, category, type, evidence level and evidence (the operational
  records it is about). Gains **citations**: the knowledge sources it relied on.
- **Insight Citation**: One knowledge source an insight relied on — source type and
  identity, title, location, scope (hotel or general), and last-updated date, as recorded
  at generation time. Its availability (still live or not) is checked when shown.
- **Usage Event** *(existing)*: An append-only count of something an account used, with a
  permanent feature code. Gains the "guest memory extractions" feature.
- **AI Cost Record** *(existing)*: One provider call — account, hotel, capability, trigger
  kind, model, operation, tokens, and cost. Only super admins ever see its cost and model.
- **AI Capability** *(existing)*: The named AI agent or job that started the AI work a call
  belongs to (Concierge, Admin AI, Recommendation, Insights, Document Reading, Guest Memory,
  Knowledge indexing). Work done inside another capability's turn — turn classification, a
  knowledge search during a Concierge reply — counts under that capability. It is the
  shared key between usage and cost reports.

## Success Criteria *(mandatory)*

### Measurable Outcomes

- **SC-001**: Zero hotel-facing responses, Admin AI answers, guest messages or notifications
  contain a cost, price, margin, provider or model value, verified across 100% of
  hotel-facing endpoints and Admin AI tools by the automated visibility check.
- **SC-002**: 100% of defined AI capabilities have both a usage feature and cost
  attribution; the coverage check fails within one test run when one is missing.
- **SC-003**: In a full test run that exercises every AI capability, 0% of AI cost is
  unattributed (by capability and by trigger).
- **SC-004**: For any account and date range, per-capability usage and cost figures add up
  to the totals with zero discrepancy.
- **SC-005**: 100% of insights that cite knowledge cite only sources that exist (or existed
  at generation time) and belong to the same hotel or to global knowledge; zero citations
  to another hotel's sources.
- **SC-006**: A hotel admin can answer "how much AI did we use this month, and on what?"
  from the usage page alone in under one minute, without contacting support.
- **SC-007**: A super admin can identify the most expensive AI capability for any account
  and month from the cost report in under one minute.

## Assumptions

- "AI capability" means the agent or AI job that made a call, as already recorded on every
  cost record; no new classification is introduced.
- Tokens shown to hotel admins are the tokens the provider reported (input and output);
  cached tokens are included in the input figure rather than shown separately, since they
  only matter for pricing.
- Hotel admins see request and token counts per capability, but not model or provider
  names: the plan lists "model" only for super admins, and the model is a pricing input.
- Evidence level reflects the operational data an insight is grounded in. Knowledge
  describes how the hotel says it works, not what happened, so citing it never raises the
  level.
- Insights search knowledge with staff-level access (they are read by staff, not guests),
  so staff-only knowledge can be cited.
- Insights continue to be generated on demand (and by any existing schedule); this feature
  does not change when or how often they run, and adds no new insight categories.
- The existing per-run AI activity log already hides cost from hotel users; this feature
  verifies it rather than changing it.
- The daily spend ceiling and its amounts are unchanged; only its user-facing messages are
  covered by the visibility check.
- Contract values and margin calculation are unchanged; margin stays empty where no
  contract value is recorded.
- No billing, plans, limits or invoices are introduced (SaaS plans remain post-MVP).
- Frontend slice (in `ecosystem-frontend`): citations on the insights list, the usage page
  with requests and tokens per capability, and the per-capability section of the
  super-admin cost report.

## Dependencies

- Phase 8 (knowledge search with citations and hotel-over-global precedence) — reused for
  insight citations.
- Phases 7, 10 and 11 (Concierge tools, proactive messages, guest memory extraction, AI
  activity log) — the operations whose metering is verified here.
- Existing metering, cost attribution and reporting (PGRIP Phase 2 WP-8 and cost
  attribution) — extended, not replaced.

## Out of Scope

- Showing provider cost or margin to hotel admins in any form.
- Usage limits, plans, quotas, or billing based on usage.
- Measuring "conversations handled" (still needs a definition of when a conversation ends).
- Re-generating or backfilling citations on existing insights.
- New insight categories, scheduling changes, or insight approval workflows.
