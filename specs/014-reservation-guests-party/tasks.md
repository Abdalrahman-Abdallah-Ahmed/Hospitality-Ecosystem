# Tasks: Reservation Guests and Party Composition

**Input**: Design documents from `/specs/014-reservation-guests-party/`

**Prerequisites**: [plan.md](plan.md), [spec.md](spec.md), [research.md](research.md), [data-model.md](data-model.md), [reservation-guests API contract](contracts/reservation-guests-api.md), [quickstart.md](quickstart.md)

**Tests**: Required by the constitution and specification. Write the listed Pest coverage before or alongside each implementation slice and verify it fails before the matching behavior is added.

**Organization**: Tasks are grouped by user story after the shared foundation so each story has a clear acceptance checkpoint.

## Phase 1: Setup

**Purpose**: Establish the feature test and documentation locations without changing application behavior.

- [X] T001 Create the Reservation Guests feature-test skeleton and reusable reservation/guest fixtures in `tests/Feature/ReservationGuestsTest.php` and `tests/Pest.php`
- [X] T002 [P] Create the legacy-data migration test skeleton in `tests/Feature/ReservationGuestsMigrationTest.php`
- [X] T003 [P] Add the planned API change headings to `docs/reservations-api-documentation.md` and create `docs/latest-changes-2026-10-14.md`

---

## Phase 2: Foundational Data and Domain Infrastructure

**Purpose**: Create the shared data model and transactional synchronization path required by every user story.

**Critical**: Complete this phase before exposing party data through staff, AI, import, or Concierge entry points.

- [X] T004 Create the `reservation_guests` table with UUID id, hotel/reservation/guest foreign keys, `is_primary`, timestamps, soft deletes, unique live `(reservation_id, guest_id)`, one live primary per reservation, and hotel lookup indexes in `database/migrations/2026_10_14_000001_create_reservation_guests_table.php`
- [X] T005 Create the `children_ages` JSON integer-array column with default `[]` in `database/migrations/2026_10_14_000002_add_children_ages_to_reservations_table.php`
- [X] T006 [P] Create `App\Models\ReservationGuest` with `BelongsToHotel`, UUIDs, soft deletes, event logging, casts, and `reservation()`/`guest()` relations in `app/Models/ReservationGuest.php`
- [X] T007 Extend reservation casts, event logging, eager-loadable guest-list relations, and primary-guest helpers in `app/Models/Reservation.php`
- [X] T008 Implement phone-first, unambiguous same-hotel email guest resolution in `app/Services/GuestIdentityService.php`, then implement shared resolved-guest, primary-selection, same-hotel, no-duplicate, party-size, and children-age invariant checks in `app/Support/Reservations/ReservationGuestSync.php`
- [X] T009 Integrate guest-list and age synchronization into the existing transactional create/update flow, maintaining `reservations.guest_id` and updating only non-departed stays on primary changes, in `app/Support/Reservations/ReservationCreator.php` and `app/Services/Reservations/ReservationCommands.php`
- [X] T010 Add focused domain tests for exactly one primary, same-hotel membership, duplicate rejection, phone-first and unambiguous-email matching, phone/email conflict rejection, party-size limit, ages 0–17, and legacy-age compatibility in `tests/Feature/ReservationGuestsTest.php`

**Checkpoint**: The domain can atomically maintain a valid primary guest, party list, and children ages without any new public request shape.

---

## Phase 3: User Story 1 - Record Everyone on a Reservation (Priority: P1) MVP

**Goal**: Staff can create a reservation with one primary guest and zero or more additional guests, resolving existing guests or creating them through the current guest identity rules.

**Independent Test**: Create a reservation with three named guests, read it back, confirm exactly one primary and no duplicate guest profile when a known phone is supplied.

### Tests

- [X] T011 [P] [US1] Add POST reservation tests for one implicit primary, multiple guests, zero/two primaries, duplicate guests, contact/id conflict, foreign-hotel guest rejection, and an employee who has `reservations.create` but lacks `guests.create` in `tests/Feature/ReservationGuestsTest.php`
- [X] T012 [P] [US1] Add reservation resource tests for primary marker, guest count, complete detail guest list, and a soft-deleted guest omitted from list views while the historical primary pointer remains intact in `tests/Feature/ReservationControllerTest.php`

### Implementation

- [X] T013 [US1] Add `guests` request validation for existing same-hotel guests and new guest contact data, including the single-guest primary compatibility rule, in `app/Http/Requests/StoreReservationRequest.php`
- [X] T014 [US1] Pass validated guest-list data to the shared reservation create operation and load guest-list relations in `app/Http/Controllers/ReservationController.php`
- [X] T015 [US1] Add `guests`, `guest_count`, and the primary marker to reservation detail/list rendering while retaining `guest` and `guest_id` in `app/Http/Resources/ReservationResource.php`

**Checkpoint**: The staff API creates and reads a valid reservation guest list under existing reservation-create authorization.

---

## Phase 4: User Story 2 - Record Children's Ages (Priority: P1)

**Goal**: Staff can record a complete, valid age for every child in the reservation-wide party.

**Independent Test**: Create two children with ages 4 and 9, then prove one age, age 18, and ages with zero children each fail cleanly.

### Tests

- [X] T016 [P] [US2] Add create/update validation tests for ages count, integer bounds 0–17, zero-child emptiness, and changing child totals, plus one assertion that the party-capacity check still uses `adults` and `children` totals across all room lines (FR-013), in `tests/Feature/ReservationGuestsTest.php`
- [X] T017 [P] [US2] Add response-contract assertions for `children_ages` in `tests/Feature/ReservationControllerTest.php`

### Implementation

- [X] T018 [US2] Add `children_ages` validation and the create-time requirement that its length equals `children` in `app/Http/Requests/StoreReservationRequest.php`
- [X] T019 [US2] Add partial-update validation that enforces the resulting party ages only when `children` or `children_ages` is supplied in `app/Http/Requests/UpdateReservationRequest.php`
- [X] T020 [US2] Expose `children_ages` in reservation resources and preserve supplied order in `app/Http/Resources/ReservationResource.php`

**Checkpoint**: A new reservation's children total and ages always agree, without adding per-room party allocation.

---

## Phase 5: User Story 5 - Existing Reservations Carry Over Unchanged (Priority: P1)

**Goal**: The release safely gives every existing reservation one primary guest link without inventing children’s ages or blocking unrelated corrections.

**Independent Test**: Migrate legacy active and soft-deleted reservations, verify one primary link points to the prior `guest_id`, then rerun the backfill fixture without duplicates.

### Tests

- [X] T021 [P] [US5] Add migration tests for active and soft-deleted reservations, idempotent backfill, empty age arrays, and unchanged reservation fields in `tests/Feature/ReservationGuestsMigrationTest.php`
- [X] T022 [P] [US5] Add regression coverage for editing dates on a legacy reservation with children and no ages in `tests/Feature/ReservationGuestsTest.php`

### Implementation

- [X] T023 [US5] Backfill every reservation, including soft-deleted records, from its existing `guest_id` as the only primary membership and insert only when no membership exists in `database/migrations/2026_10_14_000003_backfill_reservation_guests.php`
- [X] T024 [US5] Ensure migration ordering and rollback behavior preserve the current reservation guest pointer and do not fabricate age data in `database/migrations/2026_10_14_000002_add_children_ages_to_reservations_table.php` and `database/migrations/2026_10_14_000003_backfill_reservation_guests.php`

**Checkpoint**: Existing reservations remain operational after migration, including legacy children totals without ages.

---

## Phase 6: User Story 3 - Change the Guest List After Booking (Priority: P2)

**Goal**: Authorized staff can replace the complete guest list, promote a new primary, or remove an additional guest while retaining guest-profile history and auditing the change.

**Independent Test**: Update a reservation to add, remove, and promote guests; confirm one primary, unchanged guest profiles, non-departed stay synchronization, and audit events.

### Tests

- [X] T025 [P] [US3] Add PUT/PATCH tests for add, remove, primary switch, primary-removal rejection, cancelled/checked-out correction, and event history in `tests/Feature/ReservationGuestsTest.php`
- [X] T026 [P] [US3] Add stay-sync tests proving primary changes update only non-departed stays in `tests/Feature/StaySyncTest.php`

### Implementation

- [X] T027 [US3] Add complete-list update validation and cross-field primary rules for `guests` in `app/Http/Requests/UpdateReservationRequest.php`
- [X] T028 [US3] Route updated guest-list data through the shared reservation command and load the resulting party relations in `app/Http/Controllers/ReservationController.php`
- [X] T029 [US3] Verify and, only where existing model event logging is insufficient, extend reservation and reservation-guest audit records so they contain permitted identifiers and primary/age changes in `app/Models/Reservation.php` and `app/Models/ReservationGuest.php`

**Checkpoint**: Guest-list updates are atomic, auditable, and preserve historical guest profiles and departed stays.

---

## Phase 7: User Story 4 - Admin AI and Concierge See the Guest List (Priority: P2)

**Goal**: Admin AI reads and writes the same party data under the same permissions, while the Concierge keeps reservation authority with the primary guest.

**Independent Test**: Create and read a family reservation through Admin AI, verify a denied actor cannot create it, and verify an additional guest's WhatsApp message does not expose the primary reservation.

### Tests

- [X] T030 [P] [US4] Add Admin AI create/update/read tests for guest lists, ages, shared validation, authorization refusal, and an audit entry recording the AI actor for an AI guest-list and children-ages change (FR-019, SC-004) in `tests/Feature/ReservationRoomsAiToolsTest.php` and `tests/Feature/CreateReservationToolTest.php`
- [X] T031 [P] [US4] Add primary-only own-reservation and additional-guest WhatsApp recognition tests in `tests/Feature/SenderRecognitionServiceTest.php` and `tests/Feature/ConciergeGuestRestrictionsTest.php`

### Implementation

- [X] T032 [US4] Extend Admin AI create and update schemas/handlers with guest-list and children-age arguments, then delegate to the shared reservation command in `app/Ai/Tools/CreateReservationTool.php` and `app/Ai/Tools/UpdateReservationTool.php`
- [X] T033 [US4] Add primary-marked guest lists and ages to admin read-tool results in `app/Ai/Tools/GetReservationTool.php` and `app/Ai/Tools/GetReservationsTool.php`
- [X] T034 [US4] Keep `GetOwnReservationTool` and sender recognition bound to the primary `reservations.guest_id`, preventing additional guests from receiving reservation details, in `app/Ai/Tools/GetOwnReservationTool.php`, `app/Models/Reservation.php`, and `app/Services/SenderRecognitionService.php`

**Checkpoint**: AI uses the same party rules as staff, with no new route around authorization or guest privacy.

---

## Phase 8: Import, Documentation, and Cross-Cutting Verification

**Purpose**: Bring imports, public documentation, tenant isolation, and release validation into line with the shared party contract.

- [X] T035 Add import parsing for named guests and optional complete children ages, delegate to the shared reservation command, and save children without ages only when the row supplies no ages at all (FR-011 import exception) in `app/Imports/ReservationsImport.php`
- [X] T036 [P] Add import party-data tests in `tests/Feature/ReservationImportTest.php`: a row with children and no ages is imported with an empty ages list; a row with a wrong number of ages or an age outside 0–17 is skipped with its reason
- [X] T037 [P] Add cross-hotel attach, list/resource, AI-tool, and import isolation cases in `tests/Feature/TenantIsolationTest.php`
- [X] T038 Update request/response examples, validation errors, AI behavior, and import notes in `docs/reservations-api-documentation.md`
- [X] T039 [P] Document the additive guest-list and children-ages API change in `docs/latest-changes-2026-10-14.md`
- [X] T041 [P] Describe the `ecosystem-frontend` slice (reservation form guest list with a primary selector, add/remove guests, children's age inputs that follow the children count, detail view with primary marker and ages, the new validation messages) in `specs/014-reservation-guests-party/frontend-changes.md`
- [X] T042 Lock the reservation row before a guest-list change in `ReservationGuestSync::sync()` so two concurrent edits apply one after the other and the later complete list wins, then add a test that two sequential full-list saves leave exactly one primary and the second list, in `app/Support/Reservations/ReservationGuestSync.php` and `tests/Feature/ReservationGuestsTest.php`
- [X] T040 Run the scenarios in `specs/014-reservation-guests-party/quickstart.md`, then run `./vendor/bin/pint` and `php artisan test`

---

## Dependencies and Execution Order

- Phase 1 can start immediately.
- Phase 2 blocks all story work.
- US1 and US2 can proceed after Phase 2, but both modify reservation request/resource files and should be sequenced in one working branch.
- US5 depends on the foundational schema and should complete before production deployment; it can be developed alongside US1/US2 after T004–T010.
- US3 depends on US1 because it updates the guest-list request/response behavior.
- US4 depends on US1 and US2 because AI must use the settled staff contract.
- Phase 8 depends on all selected stories; T035 also depends on the shared domain path from Phase 2.

## Parallel Opportunities

- T002 and T003 can run alongside T001.
- After T004–T005, T006 can run alongside non-conflicting model test preparation; T010 follows T006–T009.
- Test tasks marked `[P]` in each story can be written in parallel when they do not edit the same file.
- T021/T022, T025/T026, T030/T031, and T036/T037/T039/T041 are independent-file groups.
- T042 follows T025 (both edit `tests/Feature/ReservationGuestsTest.php`); T040 runs last.

## Implementation Strategy

### MVP

Deliver Phases 1–5: foundational invariants, staff creation, children ages, and safe legacy migration. At that point the reservation API can record a complete party safely and existing bookings remain intact.

### Incremental Delivery

1. Ship the schema, primary-pointer synchronization and staff create/read behavior.
2. Add ages and complete migration validation.
3. Add guest-list edits and audit coverage.
4. Add AI and Concierge parity.
5. Update import and release documentation, then complete the full quickstart verification.

## Format Validation

Every task uses the required checkbox, sequential task id, optional parallel marker, user-story label for story work, and explicit repository path.

## Implementation Notes (2026-10-21)

- T012, T017 and T026: the resource, `children_ages` and stay-sync assertions live in
  `tests/Feature/ReservationGuestsTest.php` beside the rest of the party coverage, not in
  `ReservationControllerTest.php` / `StaySyncTest.php`.
- T031: covered by `tests/Feature/SenderRecognitionServiceTest.php` ("keeps reservation
  authority with the primary guest…"); `ConciergeGuestRestrictionsTest.php` needed no change.
- Found while implementing: the `guests.*.guest_id` rule used `required_without`, which
  rejected a new guest named by phone only (or email only). Changed to
  `required_without_all` in both reservation requests.
- Added with T042: an update that lowers `adults`/`children` below the named guest list is
  rejected (FR-006), via `ReservationGuestSync::assertListFitsParty()`.
