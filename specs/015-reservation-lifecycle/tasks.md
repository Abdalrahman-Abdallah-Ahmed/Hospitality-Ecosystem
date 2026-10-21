# Tasks: Reservation Lifecycle and No-Show

**Input**: Design documents from `specs/015-reservation-lifecycle/`

**Prerequisites**: [plan.md](plan.md), [spec.md](spec.md), [research.md](research.md), [data-model.md](data-model.md), [contracts/no-show-api.md](contracts/no-show-api.md), [quickstart.md](quickstart.md)

**Tests**: Required by the constitution (Principle VIII) and CLAUDE.md (permission and tenant-isolation tests for every new permission and tenant-scoped surface). Write each story's tests first and see them fail before implementing.

**Organization**: Tasks are grouped by user story after a shared foundation, so each story can be implemented and tested on its own.

## Format: `[ID] [P?] [Story] Description`

- **[P]**: can run in parallel (different files, no dependency on an unfinished task)
- **[Story]**: the user story the task belongs to (US1–US5)

---

## Phase 1: Setup

**Purpose**: Test fixtures shared by every story. No behaviour change.

- [X] T001 Add shared no-show fixtures to `tests/Pest.php`:
  - `nsReservation(Hotel $hotel, int $rooms = 1, array $attributes = [])`: a confirmed reservation with arrival yesterday in the hotel timezone, so each line has an `expected` stay. Build it with `createReservationWithRooms()` and `ReservationCreator::syncStays()`, as the front-desk fixtures do (`fdBook()`), rather than through `ReservationCreator::create()`, so the availability check never runs over past nights;
  - `nsEmployee(Hotel $hotel, array $permissions)`: reuse `fdEmployee()`;
  - `nsPost($test, User $user, string $uri, array $payload = [])`: API headers plus `actingAs`.

---

## Phase 2: Foundational

**Purpose**: The status value, permission, column, lock and lifecycle table that every story needs.

**⚠️ CRITICAL**: Finish this phase before any user story.

- [X] T002 Add `case NO_SHOW = 'no_show';` to `app/Enums/ReservationStatus.php`, and update the `holdingInventory()` docblock (the list itself is unchanged: `no_show` holds no inventory).
- [X] T003 [P] Add `case RESERVATIONS_NO_SHOW = 'reservations.no_show';` to `app/Enums/Permission.php`, grouped with the other `RESERVATIONS_*` cases.
  - Do **not** add it to `employeeDefaults()`.
  - Give it a label/description entry if the enum has them, following the neighbouring cases.
- [X] T004 [P] Create `database/migrations/2026_10_21_000001_add_status_before_no_show_to_reservations_table.php`.
  - Add `status_before_no_show`: "nullable string"; "Set to `pending` or `confirmed` when the reservation becomes `no_show`; read and cleared on undo. Null otherwise."
  - No backfill. `down()` drops the column.
- [X] T005 Update `app/Models/Reservation.php`:
  - add `status_before_no_show` to `$fillable`;
  - cast it to `ReservationStatus`;
  - add it to `eventLoggedAttributes()`.
- [X] T006 Extract `StayLifecycleService::lock()` into a new `app/Support/Reservations/ReservationLock.php`.
  - Lock order stays: reservation, then its lines and stays, then rooms.
  - Make `app/Services/StayLifecycleService.php` call it, with no behaviour change.
  - Run `php artisan test --filter='CheckIn|CheckOut|Stay'` to confirm nothing changed.
- [X] T007 Create `app/Support/Reservations/ReservationLifecycle.php`.
  - Hold the data-model lifecycle table as a constant: `from → [to → operation]`, with operations `create`, `edit`, `cancel`, `check_in`, `check_out`, `no_show`, `undo_no_show`, `import`.
  - Expose `allows(?ReservationStatus $from, ReservationStatus $to, string $operation): bool`.
  - Expose `assertEdit(?Reservation $reservation, ReservationStatus $to): void` (null for create). It throws `ValidationException` on `status`:
    - for `no_show`: `"Use POST /reservation/{id}/no-show."` (on create: `"Mark a no-show with POST /reservation/{id}/no-show after creating the reservation."`);
    - for `checked_in`/`checked_out`: always rejected here. The controller's deprecated path turns those into the check-in/out services, which use their own operation and never call `assertEdit()`;
    - for any other pair not in the table: `"A {from} reservation can't be changed to {to}."`.
- [X] T008 [P] Add `noShow(User $user, Reservation|Stay $record): bool` to `app/Policies/ReservationPolicy.php`, returning `$this->allows($user, Permission::RESERVATIONS_NO_SHOW, $record)`. For a `Stay`, check its reservation's hotel through the same-hotel helper the policy already uses.

**Checkpoint**: `no_show` exists, the permission exists, the column is migrated, and the lifecycle table can answer any from/to/operation question.

---

## Phase 3: User Story 1 - Mark a guest who never arrived as a no-show (Priority: P1) 🎯 MVP

**Goal**: Staff with `reservations.no_show` mark a whole reservation a no-show. Its stays become no-show and its rooms are released.

**Independent Test**: A confirmed reservation arriving yesterday is marked no-show. It shows `no_show`, its stays are `no_show`, its rooms are free in availability for those nights, and the action is audited with the actor and reason.

### Tests for User Story 1

- [X] T009 [P] [US1] Create `tests/Feature/NoShowTest.php` with whole-reservation cases:
  - marked from the arrival day (hotel timezone); rejected when arrival is tomorrow;
  - rejected when any stay is `in_house` or `departed`;
  - rejected for `cancelled` and `checked_out`;
  - repeating the mark returns `200` and changes nothing;
  - stays become `no_show`, `status_before_no_show` is set, and `AvailabilityService` shows the nights free;
  - `reservation.no_show_marked` is audited with the reason;
  - an employee without the permission gets `403`;
  - no ledger row is written and no guest notification is queued;
  - the reservation is absent from `GET /api/stays/arrivals` (FR-016);
  - `PitchEligibilityService` finds it ineligible, `ProactiveGuardrails` blocks a proactive message, and no `ExtractGuestMemoriesJob` is queued (SC-004).
- [X] T010 [P] [US1] Add `POST /reservation/{reservation}/no-show` rows (allowed with `Permission::RESERVATIONS_NO_SHOW`, `403` without) to the dataset in `tests/Feature/PermissionAuthorizationTest.php`.

### Implementation for User Story 1

- [X] T011 [US1] Create `app/Services/NoShowService.php` with `markReservation(Reservation $reservation, ?string $reason = null): array{reservation, stays}`. It runs in one transaction:
  1. `ReservationLock` first.
  2. Check, after locking:
     - the arrival date ≤ `FrontDeskLists::today($hotel)`;
     - the status is `pending`/`confirmed`;
     - no stay is `in_house`/`departed`.
     Failures throw `CheckInOutException` keyed `stays`.
  3. Already `no_show`: return unchanged.
  4. Otherwise:
     - `StayService::markNoShow()` on each live `expected` stay;
     - set `status_before_no_show` to the current status and `status` to `no_show`;
     - `EventLogger::record($reservation, 'no_show_marked', changes: ['stays' => ids], reason: $reason)`.
- [X] T012 [P] [US1] Create `app/Http/Requests/NoShowRequest.php`: `reason` "optional, string, max 500"; `overbook_override` "optional boolean" (used by undo).
- [X] T013 [US1] Create `app/Http/Controllers/NoShowController.php` with `reservation(NoShowRequest, Reservation)`:
  - `authorize('noShow', $reservation)`;
  - call the service;
  - return `apiResponse('Marked as a no-show.', 200, ['reservation' => ReservationResource, 'stays' => StayResource::collection])`.
- [X] T014 [US1] Register `Route::post('/reservation/{reservation}/no-show', [NoShowController::class, 'reservation'])` next to check-in/out in `routes/api.php`.
- [X] T015 [P] [US1] Add `ReservationStatus::NO_SHOW` to the reservation statuses in `app/Jobs/ExpireRecommendationsJob.php` that end a stay, and remove the "no no-show status yet (SPEC-012)" note from its docblock. Add a case to `tests/Feature/ExpireRecommendationsJobTest.php`.
- [X] T016 [P] [US1] In `app/Jobs/MatchRecommendationOutcomesJob.php::expireUndecided()`, also select recommendations whose reservation `status = no_show`. Keep the stay-based match. Add a case to `tests/Feature/RecommendationOutcomeTest.php`.

**Checkpoint**: A whole reservation can be marked a no-show via the API, with release, audit, permission and recommendation effects.

---

## Phase 4: User Story 2 - One room of a group never arrives (Priority: P1)

**Goal**: Mark one room a no-show. Its room is released, and the reservation can still be fully checked out.

**Independent Test**: A three-room reservation; two rooms check in, the third is marked no-show, then both check out. The reservation ends `checked_out`, the third stay is `no_show`, and its room was released.

### Tests for User Story 2

- [X] T017 [P] [US2] Add room-level cases to `tests/Feature/NoShowTest.php`:
  - one expected stay marked; the others are unchanged;
  - rejected for an `in_house`/`departed` stay or before the arrival day;
  - the last live stay marked → reservation `no_show` with `status_before_no_show`;
  - the scenario of two in house, one no-show, both checked out → `checked_out` with no "Cancel the rooms that did not arrive" error;
  - the room of a no-show line can be assigned to another booking on the same nights;
  - the no-show room is absent from `GET /api/stays/arrivals` while the others are listed (FR-016).
- [X] T018 [P] [US2] Add `POST /stays/{stay}/no-show` rows to `tests/Feature/PermissionAuthorizationTest.php`.

### Implementation for User Story 2

- [X] T019 [US2] Add `markStay(Stay $stay, ?string $reason = null)` to `app/Services/NoShowService.php`.
  - Same lock.
  - Check: stay `expected` and live; reservation `pending`/`confirmed`/`checked_in`; arrival reached.
  - Mark it.
  - If every live stay is now `no_show`, roll the reservation up as in T011.
  - Audit `no_show_marked` with that stay's id and the reason.
- [X] T020 [US2] Add `stay(NoShowRequest, Stay)` to `app/Http/Controllers/NoShowController.php` (authorize `noShow` on the stay) and register `Route::post('/stays/{stay}/no-show', …)` in `routes/api.php`.
- [X] T021 [US2] In `app/Support/Reservations/RoomAssignmentRules.php::reason()`, exclude clashing lines whose stay is `no_show` or `departed` (left join `stays` on `reservation_room_id`, not deleted), so a no-show room is free for others.
- [X] T022 [P] [US2] In `app/Http/Resources/ReservationResource.php`, add `stay_status` to each reservation room line: `expected` | `in_house` | `departed` | `no_show` | `cancelled` | `null` for legacy lines without a stay. Make sure `ReservationController::RELATIONS` eager-loads the line's stay, so there's no N+1.
- [X] T022a [P] [US2] Add the same per-line `stay_status` to the Admin AI read tools `app/Ai/Tools/GetReservationTool.php` and `app/Ai/Tools/GetReservationsTool.php` (FR-015), eager-loading the stays. Add assertions to `tests/Feature/AdminReadToolsTest.php`.

**Checkpoint**: Room-level no-show works, and a partly arrived group can be checked out.

---

## Phase 5: User Story 3 - Status changes follow one clear lifecycle (Priority: P1)

**Goal**: Every edit-path status change is checked against the lifecycle table, and a no-show reservation's dates, party and rooms are locked.

**Independent Test**: Run every (from, to) pair through `PUT /api/reservation/{id}` and `UpdateReservationTool`/`CancelReservationTool`. Only pairs in the table succeed; every other pair returns `422` on `status`, naming both statuses.

### Tests for User Story 3

- [X] T023 [P] [US3] Create `tests/Feature/ReservationLifecycleTest.php`:
  - a dataset over all status pairs through the staff `PUT` (and `CancelReservationTool` for `cancelled`), asserting allowed vs `422` with the message from T007;
  - `status: no_show` via `PUT` → `422` pointing to the no-show endpoint;
  - deprecated `checked_in`/`checked_out` via `PUT` behaves as today;
  - import may record `checked_out`/`no_show`.
- [X] T024 [P] [US3] Add edit-lock cases to `tests/Feature/ReservationLifecycleTest.php`. While `no_show`:
  - `arrival_date`, `departure_date`, `adults`, `children`, `children_ages`, `rooms`, `status`, `reservation_id`, `source`, `reservation_value` and `currency` → `422` "Undo the no-show first.";
  - sending a locked field with its current value is accepted (not a change);
  - `guests` and `special_requests` are saved.

### Implementation for User Story 3

- [X] T025 [US3] In `app/Support/Reservations/ReservationCreator.php::update()`:
  - when `status` changes, call `ReservationLifecycle::assertEdit()` before the transaction;
  - when the reservation is `no_show`, apply an allow-list: only `guests` and `special_requests` may change. Any other attribute whose value differs from the stored one, or a non-null `$rooms`, is rejected with `ValidationException` keyed by that field: "Undo the no-show first.".
- [X] T026 [US3] Make `ReservationCreator::create()` in `app/Support/Reservations/ReservationCreator.php` call `ReservationLifecycle::assertEdit(null, $status)` when not `fromImport`, so `status: no_show` on create is rejected in the one write path. `ReservationController::store()` and `update()` need no separate check. Their deprecated check-in/out handling runs first and is unchanged.
- [X] T027 [US3] In `app/Support/Reservations/ReservationCreator.php::syncStays()`, add the import arm: with `recordStatus` and a reservation status of `no_show`, `StayService::markNoShow()` its `expected` stays. Also update the docblock line "No-show is not a reservation status". Add an import case to `tests/Feature/ReservationImportTest.php`.

**Checkpoint**: No entry point can make a status change outside the table, and no-show reservations are edit-locked.

---

## Phase 6: User Story 4 - Undo a mistaken no-show (Priority: P2)

**Goal**: Undo a whole no-show at any time, or one room's no-show while the reservation isn't checked out. Availability is re-checked.

**Independent Test**: Mark a reservation no-show, undo it, and check it in. It returns to its previous status and its stays are `expected`. Undo after its rooms were sold fails unless overbooked with permission.

### Tests for User Story 4

- [X] T028 [P] [US4] Add undo cases to `tests/Feature/NoShowTest.php`:
  - whole undo restores `status_before_no_show` (pending and confirmed both) and clears it;
  - stays become `expected`;
  - undo after the departure date succeeds and leaves `pending`/`confirmed`;
  - undo when the rooms were resold → `422` on `rooms`; with `overbook_override` plus `reservations.overbook` it succeeds; without the overbook permission it's `403`;
  - room undo works while `checked_in`, and is rejected after `checked_out` ("This reservation is checked out; its no-show rooms are final.");
  - undo on a non-`no_show` reservation → `422` on `status`;
  - `reservation.no_show_undone` is audited with the reason.
- [X] T029 [P] [US4] Add the two undo routes to `tests/Feature/PermissionAuthorizationTest.php`.

### Implementation for User Story 4

- [X] T030 [US4] Add `undoReservation(Reservation, ?string $reason, bool $overbook)` and `undoStay(Stay, ?string $reason, bool $overbook)` to `app/Services/NoShowService.php`.
  - Lock first.
  - Take the availability footprint before the change; set the stays back to `expected`.
  - Whole undo: set `status` from `status_before_no_show` and clear it.
  - Room undo: reject when the reservation is `checked_out`. If the reservation was `no_show`, restore it too.
  - Run `AvailabilityService::guard($reservation, $footprintBefore, $overbook)`.
  - Audit `no_show_undone` with the reason.
- [X] T031 [US4] Add `undoReservation` and `undoStay` actions to `app/Http/Controllers/NoShowController.php`.
  - When `overbook_override` is true, authorize `reservations.overbook` the same way `ReservationController::overbookOverride()` does.
  - Respond "No-show undone.".
  - Register `POST /reservation/{reservation}/undo-no-show` and `POST /stays/{stay}/undo-no-show` in `routes/api.php`.

**Checkpoint**: Mistakes can be reversed safely, with availability enforced.

---

## Phase 7: User Story 5 - The Admin AI can mark and undo no-shows (Priority: P3)

**Goal**: The Admin AI marks and undoes no-shows through the same service and permission. The Concierge can't.

**Independent Test**: Through `aatTool($user, 'NoShowTool')`, mark a whole reservation and one room. The same rules apply, a user without the permission is refused, and audit events carry the AI actor.

### Tests for User Story 5

- [X] T032 [P] [US5] Create `tests/Feature/NoShowToolTest.php`:
  - `mark`/`undo`, whole and by `room_number`;
  - rule failures return the service messages;
  - refused without `reservations.no_show`;
  - the events' `actor_kind` is `ai_agent`;
  - the tool isn't in the Concierge toolset;
  - an unknown code or room number is refused without changes;
  - after the tool marks a room, `GetReservationTool` shows that line's `stay_status` as `no_show`.

### Implementation for User Story 5

- [X] T033 [US5] Create `app/Ai/Tools/NoShowTool.php`.
  - Arguments: `action` (`mark`|`undo`, required), `code` (required), `room_number` (optional), `reason` (optional).
  - Resolve the reservation within the tool's hotel only, and the stay by the line's room number.
  - Call `NoShowService` and return a short JSON result or the error message, following `CheckInTool`.
- [X] T034 [US5] Register it in `app/Ai/Tools/Admin/AdminToolset.php` next to the check-in/out tools: `self::write(new NoShowTool($hotel), [Permission::RESERVATIONS_NO_SHOW], [Reservation::class, Stay::class])`. Add it to any tool-name dataset in `tests/Feature/AdminCreateToolsTest.php` / `ConciergeToolsArchTest.php` that lists Admin tools.

**Checkpoint**: The Admin AI matches the API, with the AI audited as the actor.

---

## Phase 8: Polish & Cross-Cutting Concerns

- [X] T035 [P] Add tenant-isolation cases to `tests/Feature/TenantIsolationTest.php`:
  - another hotel's admin gets `403` on all four endpoints and nothing changes;
  - `NoShowTool` built for hotel A can't find hotel B's reservation code.
- [X] T036 [P] Update `docs/reservations-api-documentation.md`:
  - the lifecycle table;
  - `no_show` in Status Values;
  - the four endpoints with request, response and errors from the contract;
  - the edit lock;
  - `stay_status` on lines.
- [X] T037 [P] Update `docs/stays-api-documentation.md`: the room no-show and undo endpoints, and that no-show rooms no longer block the last check-out (FR-012a note).
- [X] T038 [P] Add `reservations.no_show` (all four endpoints and `NoShowTool`) to the permission reference in `docs/staff-roles-api-documentation.md`.
- [X] T039 [P] Write `docs/latest-changes-2026-10-21.md`: the new status, the endpoints and the permission, plus the newly rejected status edits (the only behaviour change for existing clients).
- [X] T040 [P] Write `specs/015-reservation-lifecycle/frontend-changes.md` and append a SPEC-012 section to `docs/frontend-changes-2026-10-21.md`:
  - "Mark no-show" and "Undo no-show" actions with an optional reason, on the reservation and on each room;
  - the `no_show` status badge and list filter;
  - the stays screen's room action;
  - the edit-lock messages;
  - rejected status changes;
  - `stay_status` per line.
- [X] T041 Run the scenarios in `specs/015-reservation-lifecycle/quickstart.md`, then `./vendor/bin/pint` and `php artisan test --parallel`.

---

## Dependencies & Execution Order

- **Phase 1 → Phase 2 → stories.** Phase 2 blocks every story.
- **US1** (P1, MVP) needs T002–T008.
- **US2** needs US1's `NoShowService` and controller (T011, T013), since it extends them.
- **US3** needs T002, T005 and T007 only. It can run alongside US1/US2, but it edits `ReservationCreator.php` and `ReservationController.php`, so sequence it after US1 in one branch.
- **US4** needs US1 and US2 (it undoes both).
- **US5** needs US1, US2 and US4 (the tool exposes mark and undo).
- **Polish** comes last. T041 runs after everything.

## Parallel Opportunities

- T003, T004 and T008 alongside T002/T005; T006 and T007 are independent files.
- Each story's test tasks marked [P] (T009/T010, T017/T018, T023/T024, T028/T029, T032).
- T015, T016, T022 and T022a touch separate files from the service work.
- All Phase 8 doc tasks (T036–T040) and T035.

## Implementation Strategy

### MVP

Phases 1–3 (US1). Whole-reservation no-show with release, audit, permission and
recommendation effects: the front desk's main need.

### Incremental Delivery

1. Foundation plus US1: whole no-show.
2. US2: room-level no-show (unblocks the specs/004 check-out case).
3. US3: lifecycle enforcement and the edit lock.
4. US4: undo.
5. US5: the Admin AI tool.
6. Docs, isolation and the full run.

## Format Validation

Every task has a checkbox, a sequential ID, a [P] marker only where files don't overlap, a story label inside story phases, and an exact repository path.

## Implementation Notes (2026-10-21)

- **T001**: no new helpers were needed. `fdHotel()`, `fdBook()` (arrival today, which
  already qualifies), `fdStays()`, `fdPost()` and `fdEmployee()` cover every no-show
  fixture.
- **T007/T026**: `ReservationLifecycle` has `assertEdit(Reservation, $to)` for edits and a
  separate `assertCreate($status)`. `assertCreate()` refuses only `no_show`, because the
  import and test fixtures (`gsPast()`) create past `checked_out` reservations directly.
  Leaving `checked_in` through an edit keeps the existing message "Check out the in-house
  rooms first." (two existing tests rely on it).
- **T025**: the edit lock leaves `status` to `ReservationLifecycle`, so a status edit on a
  no-show names the undo endpoint instead of "Undo the no-show first."
- **T027**: the import case lives in `tests/Feature/ReservationLifecycleTest.php`.
- **T016**: the outcome case lives in `tests/Feature/RecommendationMatchingTest.php`, where
  the matcher's expiry tests are.
- **T033**: `NoShowTool` takes `reservation_id` (like the check-in/out tools) and asks the
  admin to confirm before **marking** (`ConfirmsBeforeRunning`), like check-out and cancel.
  `AdminToolsetArchTest`'s gated list was updated. Undo runs without confirmation.
- **T034**: the Concierge exclusion is enforced in `ConciergeToolsArchTest` (guest tools may
  not use `NoShowTool` or `NoShowService`).
- **T009**: the proactive guardrail isn't called directly in a test. It only sends for
  `in_house`/`expected` stays, which a no-show never is; pitching and the arrivals list are
  asserted.
- **T030**: room types are locked before the reservation on undo (`lockTypesOf`), the same
  order every reservation write uses, so an undo and a booking for the same type can't
  deadlock.
- **Found while implementing**: none of the existing behaviour broke. The full suite passes
  (`php artisan test --parallel`: 1,834 tests).

## Code review fixes (2026-10-21)

1. **Undo and room clashes**: a restored line whose room was assigned elsewhere for
   overlapping nights meanwhile loses that room (audited) and the response's `warnings`
   say so, so the room is never on two bookings.
2. **Undo on cancelled records**: `undoStay` refuses a cancelled reservation and a stay
   whose line was cancelled.
3. **Stale stays on undo**: restored stays are re-synced (`StayService::syncForReservation`)
   to the reservation's current dates, room and revenue share.
4. **Edit racing a no-show**: `ReservationCreator::update()` re-reads the status under a
   row lock (after the room-type locks, the same order `NoShowService` uses) and stops if
   it changed since the checks.
5. **AI room lookup**: `NoShowTool` searches live stays only and takes `room_type` for a
   room with no number yet (unassigned first).
6. `roomsForAi()` eager-loads `stay` in its fallback, `UpdateReservationTool` loads it, and
   the docblock lists `stay_status`.
7. `markStay` reads the locked instances instead of `fresh()` per stay.
8. `GetOwnReservationTool` no longer re-maps `stay_status` (now in `roomsForAi()`).
9. `NoShowService` asks `ReservationLifecycle::allows()` for whole-reservation no-shows and
   undo; the class docblock says which rows are enforced where.
10. `Stay::isLive()` and `Stay::reservationOrFail()` replace the copies in
    `StayLifecycleService`, `NoShowService` and `NoShowController`.

Regression tests: `NoShowTest` (review-fix cases) and `NoShowToolTest` (room by type).
Full suite: 1,839 tests passing.

## Controller consolidation (2026-10-21)

At the owner's request, no front-desk controllers of their own:
`ReservationController` holds the whole-reservation actions (`checkIn`, `checkOut`,
`noShow`, `undoNoShow`) and `StayController` holds the per-room ones.
`StayCheckInController`, `StayCheckOutController` and `NoShowController` were removed.
Response shapes and overbook handling are shared through the
`Concerns\HandlesFrontDeskActions` trait. Routes and responses are unchanged. Earlier
mentions of `NoShowController` in this file refer to these methods.
