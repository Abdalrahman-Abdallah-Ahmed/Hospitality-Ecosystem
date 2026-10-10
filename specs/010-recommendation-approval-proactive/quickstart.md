# Quickstart: Validating Recommendation Approval and Proactive Concierge

How to prove the feature works. Contracts: [approval API](contracts/recommendation-approval-api.md),
[proactive API](contracts/proactive-messaging-api.md), [AI tools](contracts/ai-tools.md).
Data: [data-model.md](data-model.md).

## Prerequisites

```bash
docker compose -f .postgres/compose.yaml up -d
php artisan migrate
php artisan test                       # baseline green before starting
```

## Automated checks

```bash
php artisan test --filter=RecommendationApproval      # US1, US6: transitions, bulk, races, expiry, edit reset
php artisan test --filter=PitchFromApprovedPool       # US2: candidates, inline generation, guest tool filter
php artisan test --filter=PitchActivityTool           # WP-17: staging, refusals, complete/abandon
php artisan test --filter=DeclineRetry                # US3: retry window, same activity, explicit requests
php artisan test --filter=ProactiveTriggers           # US4: each trigger and every guardrail
php artisan test --filter=ProactiveOptOut             # US5: keywords, tool, staff endpoint, completion notices
php artisan test --filter=PermissionAuthorization     # recommendations.approve allowed / 403
php artisan test --filter=TenantIsolation             # queue, decisions, proactive log, opt-out
php artisan test --filter=AdminTool                   # review/decide tools: permission, confirm, no bulk
php artisan test                                      # full suite
./vendor/bin/pint --test
```

Time-dependent tests use `Carbon::setTestNow()` in the hotel's timezone (e.g. `Asia/Riyadh`).

## Manual walkthrough (local)

1. **Approval gate.** `POST /api/reservation/{id}/recommendations` → run the queue →
   `GET /api/recommendation?reservation_id={id}`: every item `pending_approval`,
   `offerable: false`.
2. Approve one, reject one with a reason, bulk-approve two
   (`POST /api/recommendations/decide`). Check statuses, `reviewed_by`, and the
   `event_logs` rows (`approved`, `rejected_by_admin`).
3. As an employee without a role → approve returns **403**. Give their staff role
   `recommendations.approve` → **200**.
4. Edit the approved item's `reason` → it is `pending_approval` again; edit only
   `priority` on another approved item → still `approved`.
5. **Approved pool.** With `PITCHING_ENABLED=true`, an in-house stay and a fake Concierge
   (`GuestConciergeAgent::fake()`), send a guest message that opens a pitch: the decision's
   `candidates.shortlist` holds only approved items; after the send the decision is
   `pitched` and the recommendation is `sent`.
6. **Decline retry.** Record a refusal (`UpdateRecommendationTool`, `rejected`); 2 h later a
   second opening may stage a *different* activity with `is_retry = true`; a third opening
   is blocked with `retry_used`. Reset and move the clock 25 h after the first pitch: blocked
   with `retry_window_closed`.
7. **Proactive.** Turn proactive on (`PUT /api/hotel/{id}` with `proactive_settings.enabled`).
   For a guest who messaged 2 h ago and checked in yesterday, set the clock to 10:05 hotel
   time and run `php artisan schedule:run` (or dispatch `EvaluateProactiveTriggersJob`):
   one `proactive_messages` row `sent`, text from `lang/{locale}/proactive.php`, appended to
   the guest's conversation; running it again creates nothing.
8. Repeat with the clock at 22:00 → `scheduled`, `reason = quiet_hours`, `due_at` 09:00.
   With the guest's last message 30 h ago → `skipped / outside_window`.
9. **Opt-out.** Send `STOP` from the guest's number: fixed confirmation, no AI usage row,
   `guests.proactive_opted_out_at` set, next trigger `skipped / opted_out`, and the next
   guest turn's decision shows the `opted_out` gate.
10. `GET /api/proactive-messages` as a hotel admin lists all of the above with reasons; as an
    employee → 403; as another hotel's admin → none of these rows.

## Expected outcomes (spec success criteria)

| Check | Expectation |
| --- | --- |
| SC-001 | No pitch, contextual or proactive, of a non-approved recommendation in any test |
| SC-002 | Every approve/reject has one audit row with actor and reason |
| SC-004 | Never more than 2 unsolicited pitches in a flow with a decline |
| SC-005 | No send in quiet hours, to opted-out guests, outside the window, or twice per event |
| SC-007 | Every trigger evaluation leaves a row with status and reason |
