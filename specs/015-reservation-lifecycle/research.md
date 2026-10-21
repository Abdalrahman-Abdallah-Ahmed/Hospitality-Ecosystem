# Research: Reservation Lifecycle and No-Show

**Feature**: [spec.md](spec.md) · **Plan**: [plan.md](plan.md)

Findings come from reading the code on `main` (2026-10-21). Each decision lists what was
chosen, why, and what was rejected.

## R1. Where the lifecycle table lives

**Decision**: A new `App\Support\Reservations\ReservationLifecycle` holds the transition
table (spec FR-001) as data: `from → to → operation`. It exposes
`assertEdit(Reservation, ReservationStatus $to)` and `allows(from, to, operation)`.
`ReservationCreator::update()` calls `assertEdit()` before writing a status change.

**Rationale**: Every status write already goes through `ReservationCreator` (staff edit,
`UpdateReservationTool`, `CancelReservationTool`, `ReservationCommands`), and check-in/out
through `StayLifecycleService`. One table, checked at the one write path, covers every
entry point (SC-001). Today `update()` accepts any enum value except leaving `checked_in`
while someone is in house.

**Alternatives**: Validation rules in the form requests (rejected: misses the AI tools and
services); a state-machine package (rejected: one small table doesn't justify a
dependency, constitution II).

## R2. Status values and storage

**Decision**: Add `ReservationStatus::NO_SHOW = 'no_show'`. `reservations.status` is a
plain string column, so no schema change is needed for the value. `holdingInventory()` is
unchanged (it never listed `no_show`; its docblock already anticipates it).

**Rationale**: Request rules are derived from the enum cast (`ModelColumnRules`), so the
new case becomes a valid value automatically. That is why FR-003 needs an explicit
rejection in the edit path (R1), not just validation.

## R3. Remembering the status to restore on undo

**Decision**: New nullable column `reservations.status_before_no_show` (string). Set when a
reservation becomes `no_show`, read and cleared on undo.

**Rationale**: FR-009 returns the reservation to "the status it had before" (pending or
confirmed). Reading it back from the audit log is fragile (rows can be redacted or
filtered). A column is explicit and testable.

**Alternatives**: Always restore to `confirmed` (rejected: changes a pending booking's
meaning); derive from `event_logs` (rejected above).

## R4. Room-level no-show: stay status or line status

**Decision**: The **stay** carries the no-show (`StayStatus::NO_SHOW`, which already exists,
via the existing `StayService::markNoShow()`). `ReservationRoomStatus` is not extended.

**Rationale**: The rest of the system already reads stays for this:
- `AvailabilityService` already excludes stays in `departed` / `no_show` from held nights,
  so a room-level no-show releases inventory with no change (FR-007, SC-002).
- `FrontDeskLists` arrivals only list `expected` stays (FR-016 holds automatically).
- `PitchEligibilityService` and `ProactiveGuardrails` only consider `in_house` / `expected`
  stays, so a no-show gets no pitch or proactive message (FR-010, SC-004).
- `StayLifecycleService` FR-012a only blocks the last check-out on `expected` stays, so a
  no-show room no longer blocks it (FR-008, SC-003).

**Gap found**: `RoomAssignmentRules::reason()` counts a clash on any live line of a holding
reservation, ignoring its stay. A no-show line with an assigned room would still block that
room for others. It must also skip lines whose stay is `no_show` (or `departed`).

**Alternatives**: `ReservationRoomStatus::NO_SHOW` (rejected: availability, assignment,
`active()` scopes and capacity all filter on line status and would each need changes,
duplicating what the stay already says).

## R5. Rolling room no-shows up to the reservation

**Decision**: After a room-level no-show or undo, recompute the reservation status:
- every live stay `no_show` → reservation `no_show` (save `status_before_no_show`);
- otherwise unchanged (it stays `pending`/`confirmed`/`checked_in`).
The existing check-out rollup (`no stay in_house` → `checked_out`) already gives FR-008's
"some departed, rest no-show → checked_out".

**Edge**: the check-out rollup currently sets `checked_out` even if every other stay is a
no-show and none departed. That can't happen, because a reservation with no in-house stay
can't be checked out.

## R6. Service and locking

**Decision**: A new `App\Services\NoShowService` with `markReservation`, `markStay`,
`undoReservation`, `undoStay`. Each runs in one transaction and locks in the same order as
`StayLifecycleService` (reservation, then its lines and stays, then rooms). That class's
private `lock()` is moved to a small shared helper (`ReservationLock`) used by both.

**Rationale**: A no-show and a check-in racing on one stay must serialize (the deferred
concurrency item from clarify). The same lock order prevents deadlocks.

**Rules checked after locking**:
- arrival date reached in the hotel's timezone (`FrontDeskLists::today()`);
- whole reservation: status `pending`/`confirmed`, no stay `in_house`/`departed`;
- room: stay `expected` and live, reservation `pending`/`confirmed`/`checked_in`;
- undo room: reservation not `checked_out` (clarification 2026-10-21);
- undo: availability re-checked through `AvailabilityService::guard()`, with the existing
  `reservations.overbook` override.

Errors use the existing `CheckInOutException` shape (`422`, field-keyed reasons), so the
frontend handles them like check-in errors.

## R7. Edits while a reservation is a no-show (FR-010a)

**Decision**: In `ReservationCreator::update()`, when the reservation is `no_show`, apply an
allow-list: only `guests` and `special_requests` may change. Any other attribute whose
value differs from the stored one, or a non-null `$rooms`, is rejected with "Undo the
no-show first." on that field. Reservations have no notes field, so the clarification's
"notes" is the free-text `special_requests`. An allow-list means a field added later is
locked by default.

## R8. Recommendations and outcomes (FR-010)

**Decision**:
- `ExpireRecommendationsJob`: add `ReservationStatus::NO_SHOW` next to `cancelled` and
  `checked_out`. Its docblock note "Reservations have no no-show status yet" is removed.
- `MatchRecommendationOutcomesJob::expireUndecided()`: also match reservations whose status
  is `no_show` (reservation status first; stays still count for room-level no-shows).
- Guest memory extraction is triggered on check-out only, so a no-show never triggers it.
  No change.

## R9. Import (Phase 13 dependency)

**Decision**: The import keeps bypassing the lifecycle table (`fromImport`).
`ReservationCreator::syncStays(recordStatus: true)` gains one arm: a reservation recorded
as `no_show` marks its expected stays no-show. A legacy status mapping (e.g. "NS" →
`no_show`) is Phase 13's job.

## R10. Permission, AI and audit

- New `Permission::RESERVATIONS_NO_SHOW = 'reservations.no_show'`, grouped with the
  reservation cases. Not in `employeeDefaults()` (FR-012).
- `ReservationPolicy::noShow()` → `$this->allows($user, Permission::RESERVATIONS_NO_SHOW, $record)`.
- Admin AI: one `NoShowTool` (`action`: `mark`/`undo`; `code`; optional `room_number`;
  optional `reason`), registered with
  `self::write(..., [Permission::RESERVATIONS_NO_SHOW], [Reservation::class, Stay::class])`.
  The Concierge toolset doesn't get it.
- Audit: model events already record the status and stay changes with the actor (the AI
  actor via `AiActionRecorder`). The optional reason is passed to
  `EventLogger::record($reservation, 'no_show_marked' | 'no_show_undone', changes, reason: $reason)`,
  which already takes a `reason` (FR-014a).

## R11. Spec correction

The first spec draft allowed `pending → checked_in`. The built check-in (SPEC-024) only
accepts `confirmed` reservations. The spec's table was corrected to match (constitution
II: keep the built rule).
