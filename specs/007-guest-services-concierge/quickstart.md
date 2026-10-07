# Quickstart: Validating Guest Services and the Concierge

**Feature**: [spec.md](spec.md) · **Contracts**: [concierge tools](contracts/concierge-tools.md),
[API and messaging](contracts/api-and-messaging.md) · **Data**: [data-model.md](data-model.md)

## Prerequisites

1. `006-activity-bookings` is merged into the base branch (R1).
2. Postgres with pgvector is running:
   `docker compose -f .postgres/compose.yaml up -d`
3. Migrations are applied: `php artisan migrate`. For the test database, the suite runs
   them itself.
4. Mail is captured locally (`MAIL_MAILER=log` or Mailpit). Tests use
   `Notification::fake()`, `Queue::fake()` and `Http::fake()` for the Graph API.

## Automated validation

```bash
php artisan test --parallel
./vendor/bin/pint --test
```

New or extended test files, one per behavior area:

| File | Proves | Spec |
| --- | --- | --- |
| `tests/Feature/GuestRequestRoutingTest.php` | Housekeeping, maintenance and "other" requests get the right category and team; foreign or deleted category gives no category; several rooms means asking first; `add_to_request_id` appends only to the guest's own open request | US1, FR-001–006 |
| `tests/Feature/GuestRequestNoticeTest.php` | The job runs inside the task's hotel context; a request closed through the housekeeping flow is notified; the text uses the kind label, never the task title; completion inside the window → WhatsApp; outside with email → email; outside with no email → `skipped/no_contact`; cancelled → `skipped/cancelled`; escalation or staff task → nothing; completing twice → one notice; WhatsApp failure → email fallback; the notice never changes task status; Arabic wording for `ar` | US2, FR-007–013 |
| `tests/Feature/CancellationOutcomeNoticeTest.php` | Approve → "cancelled" notice; decline → "still in place" plus the note; a staff cancel with an open request → approve notice; all follow the window rule | FR-010c |
| `tests/Feature/GuestEscalationTest.php` | High-priority task, admins emailed, pitching blocked in the same reply and for the stay; a second escalation appends rather than duplicates; a concurrent second escalation still gives one task; past-stay guests can escalate | US3, FR-014–018 |
| `tests/Feature/ConciergeReadToolsTest.php` | Own reservation with per-room stay status and `is_active`; own bookings with price, upcoming first; own open requests without team, notes or assignee; another guest's reference is treated as not found | US4, FR-019–024 |
| `tests/Feature/RoomChangeRequestTest.php` | In-house → request on the stay; before arrival → on the reservation; no active reservation → refused; one open per stay; admins emailed; room assignment unchanged | US5, FR-027–028 |
| `tests/Feature/ConciergeGuestRestrictionsTest.php` | Every write tool refuses without an active reservation (except escalation); no tool alters another guest's records; audit rows carry the AI actor | US6, US8, FR-030–034 |
| `tests/Arch/ConciergeToolsArchTest.php` | `GuestConciergeAgent::tools()` matches the allowlist; no guest tool depends on room assignment, stay check-in/out, booking cancel or status services | FR-031, SC-004 |
| `tests/Feature/WhatsAppUnknownSenderTest.php` (or extend the existing webhook test) | Unknown number → `ignored`, no job, no send, no hotel record; repeat → still ignored; throttling and pairing still work; guests and admins still answered | US7, FR-035–035a |
| `tests/Feature/TenantIsolationTest.php` (extend) | Hotel B staff cannot list, view or filter hotel A's guest requests or notice fields | FR-037 |
| `tests/Feature/PitchEligibilityTest.php` (extend) | An open maintenance or room-change request closes the pitching gate like a service request | R17 |

`PermissionAuthorizationTest` needs no new rows, because there are no new endpoints
(R18).

## Manual end-to-end check (local)

With WhatsApp configured against a test number, or by calling the tools from
`php artisan tinker`:

1. **Maintenance request routing.**
   - Seed a hotel with a checked-in guest in room 214 and a phone number.
   - Send "the AC in my room is broken".
   - **Expect**: a task with `guest_signal = maintenance_request`, the Maintenance
     category and team, room 214.
2. **Completion notice on WhatsApp.**
   - Mark that task `completed` through `PATCH /api/task/{id}`.
   - **Expect**: within 2 minutes, one WhatsApp message.
   - **Expect**: `guest_notice_status = sent` and `guest_notice_channel = whatsapp`.
3. **Completion notice by email.**
   - Make the guest's newest inbound message 25 hours old (update `created_at`).
   - Complete a second request.
   - **Expect**: an email in the log or Mailpit, no WhatsApp message, and
     `guest_notice_channel = email`.
4. **Room change.**
   - Send "can I move to a quieter room?".
   - **Expect**: a `room_change_request` task with no team, and an admin email.
   - **Expect**: the room assignment is unchanged.
   - Ask again. **Expect**: no second task.
5. **Escalation.**
   - Send "I want to talk to a person" twice.
   - **Expect**: one escalation task with both reasons, and no activity suggestions in
     either reply.
6. **Unknown sender.**
   - Message from a number with no guest record.
   - **Expect**: no reply, and the inbound row has `status = ignored` and
     `hotel_id = null`.

7. **AI routing sample (SC-001, FR-006, FR-018).**
   - Send 20 real messages to the Concierge as an in-house guest: 8 housekeeping, 8
     maintenance, 4 other. Repeat 2 of them as "still not fixed", and ask for a person once
     after an unanswerable question.
   - **Expect**: every housekeeping and maintenance request lands in the right category and
     team; the 2 repeats add to the open request instead of creating new ones; the Concierge
     escalates on the unanswerable question.
   - Record the result in the PR description.
8. **Notice latency (SC-002).**
   - With a queue worker running, complete 10 requests inside the window.
   - **Expect**: the job's latency log shows 2 minutes or less for at least 95% of them.

## Done when

- [ ] All of the tests above pass in a parallel run.
- [ ] Pint is clean.
- [ ] These docs are updated:
  - `docs/task-management-api-documentation.md`
  - `docs/whatsapp-device-api-documentation.md`
  - `docs/booking-entity-documentation.md`
  - `docs/latest-changes-<date>.md`
- [ ] The manual steps 1–8 behave as described on a local stack.
