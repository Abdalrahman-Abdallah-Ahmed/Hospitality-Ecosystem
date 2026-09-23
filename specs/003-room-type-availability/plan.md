# Implementation Plan: Room-Type Availability

**Branch**: `003-room-type-availability` | **Date**: 2026-09-23 | **Spec**: [spec.md](spec.md)

**Input**: Feature specification from `specs/003-room-type-availability/spec.md` (SPEC-020, Phase 3)

## Summary

This plan adds a new `AvailabilityService`. For a hotel, some room types and a date range
it returns a grid with one row per type and one column per night. Each cell shows the
type's rooms, how many are out of order, how many are booked, and how many are sellable or
overbooked. The service needs a fixed three queries per call. The nights of each booked
line are expanded in Postgres (a lateral `generate_series`), so the query cost does not grow
with rooms × nights.

The grid is used in four places:

- `GET /api/availability`, gated by the new `availability.view` permission, which is also
  given to employees without a role by default.
- An Admin AI tool that returns the full grid.
- A Concierge tool that answers only yes or no per room type.
- An overbooking guard inside `ReservationCreator`.

**How the guard works**:

- It compares the reservation's own per-type, per-night footprint before and after the
  write, and checks only the cells that increased.
- It fails with a 422 that lists structured shortfalls, unless an admin or a staff member
  with the new `reservations.overbook` permission sent `overbook_override`. An override is
  audited, and the AI can never override.
- Bookings that compete for the same room type run one at a time, because the transaction
  locks the affected `room_types` rows.
- The import records legacy data as it is.

There are no new tables, only one date index on `reservations`. The details are in
[research.md](research.md), decisions R1–R15.

## Technical Context

**Language/Version**: PHP 8.3

**Primary Dependencies**: Laravel 13, Sanctum, `laravel/ai` (tools), Pest 4

**Storage**: PostgreSQL. The calculation uses `generate_series` and `FILTER`, which are
specific to Postgres, and bookings are serialized with `SELECT … FOR UPDATE`.

**Testing**: Pest feature tests against real Postgres. The concurrency test uses a second
DB connection.

**Target Platform**: Laravel JSON API. The frontend slice (availability grid and override
prompt) is built in `ecosystem-frontend` (D13).

**Project Type**: Multi-tenant hospitality PMS backend (web service)

**Performance Goals**: 90 nights × all types for 500 rooms in under 2 s (SC-005). The
number of queries is fixed and does not depend on the range length.

**Constraints**:

- Tenant isolation, including in AI tools.
- No stored availability.
- An override needs a permission, and the AI can never override.
- The import is not checked.
- Nothing may be oversold when bookings for the same room type arrive at the same time.

**Scale/Scope**: Hotels of up to about 500 rooms. At most 90 nights per lookup. At most 50
lines per reservation (SPEC-010).

## Constitution Check

*GATE: Must pass before Phase 0 research. Re-check after Phase 1 design.*

| Principle / rule | Status | How |
| --- | --- | --- |
| I. PMS is system of record | ✅ | Availability is worked out from rooms and lines on every request. The AI gets it only through tools, never from RAG (FR-017) |
| II. Preserve and evolve | ✅ | Extends `ReservationCreator`, `ReservationPolicy`, `RoomTypePolicy`, both agents and the `Permission` enum. One new service, because there are four callers |
| III. Tenant isolation | ✅ | Every query filters on the resolved hotel. The tools are built with a hotel. Other hotels' `room_type_ids` are rejected without a hint. Isolation tests are included |
| IV. Permission-based auth | ✅ | `availability.view` and `reservations.overbook` go through `allows()` in the policies. The employee default is justified in the enum (clarification Q5) |
| V. AI through tools | ✅ | Two read tools. `CreateReservationTool` goes through the same guard and cannot override |
| VI. Auditability | ✅ | `reservation.overbooking_overridden` records the actor and shortfall. Reads are not audited (FR-022) |
| VII. Integrity and idempotency | ✅ | The guard runs inside the existing transaction, so a rejection rolls back. Row locks prevent overselling when bookings compete (R7) |
| VIII. Tested at domain boundary | ✅ | Tests for the service, endpoint, guard, concurrency, tools, isolation and permission dataset |
| IX. Spec-driven | ✅ | Spec clarified (5 answers) → this plan |
| Rule: no room required at creation | ✅ | Unassigned lines use up type inventory |
| Rule: shared dates | ✅ | A line's nights come from its reservation |
| Rule: incrementally deployable | ✅ | No breaking change. `overbook_override` and `shortfalls` are additions. Works before SPEC-003 (R4) |

**Post-design re-check**: ✅ no violations. Complexity Tracking is empty.

## Project Structure

### Documentation (this feature)

```text
specs/003-room-type-availability/
├── spec.md
├── plan.md                         # this file
├── research.md                     # decisions R1–R15
├── data-model.md
├── quickstart.md
├── contracts/availability-api.md
├── checklists/requirements.md
└── tasks.md                        # /speckit-tasks (not created here)
```

### Source Code (repository root)

```text
app/
├── Enums/
│   ├── Permission.php                          # + AVAILABILITY_VIEW (employee default), RESERVATIONS_OVERBOOK
│   ├── ReservationStatus.php                   # + holdingInventory()
│   └── RoomStatusesEnum.php                    # + outOfOrder()
├── Services/AvailabilityService.php            # NEW: forHotel(), assertRange(), footprint(), lockTypes(), guard()
├── Exceptions/InsufficientAvailabilityException.php  # NEW: extends ValidationException, renders shortfalls
├── Support/Reservations/ReservationCreator.php # lock types, footprint before/after, guard; $skipCapacity → $recordAsIs
├── Imports/ReservationsImport.php              # pass $recordAsIs
├── Policies/
│   ├── RoomTypePolicy.php                      # + viewAvailability()
│   └── ReservationPolicy.php                   # + overbook()
├── Http/
│   ├── Controllers/AvailabilityController.php  # NEW: index
│   ├── Controllers/ReservationController.php   # authorize overbook when flag set; pass overbook_override
│   ├── Requests/AvailabilityRequest.php        # NEW
│   ├── Requests/StoreReservationRequest.php    # + overbook_override
│   ├── Requests/UpdateReservationRequest.php   # + overbook_override
│   └── Resources/AvailabilityResource.php      # NEW
└── Ai/
    ├── Tools/GetAvailabilityTool.php           # NEW (Admin AI)
    ├── Tools/GetGuestAvailabilityTool.php      # NEW (Concierge, yes/no only)
    ├── Agents/AdminAdvisorAgent.php            # register tool + instruction line
    └── Agents/GuestConciergeAgent.php          # register tool + instruction line

routes/api.php                                  # GET /availability in the tenant group

database/migrations/
└── 2026_09_23_000007_add_date_index_to_reservations_table.php

tests/Feature/
├── AvailabilityServiceTest.php                 # NEW: formula, nights, statuses, overstay, out of order, fixed query count
├── AvailabilityControllerTest.php              # NEW: shape, validation, inactive/named types, super admin, 403
├── ReservationAvailabilityGuardTest.php        # NEW: create/update/extend/re-create/free, override 403/allowed/audit, AI, import
├── ReservationAvailabilityConcurrencyTest.php  # NEW: DatabaseTruncation + 2nd connection, lock_timeout proves serialization
├── AvailabilityToolsTest.php                   # NEW: admin grid, permission refusal, concierge yes/no, no counts
├── TenantIsolationTest.php                     # + availability lookup and tools
└── PermissionAuthorizationTest.php             # + availability dataset row

docs/
├── availability-api-documentation.md           # NEW
├── reservations-api-documentation.md           # overbook_override, shortfall 422, 403
├── staff-roles-api-documentation.md            # permission reference: availability.view (default), reservations.overbook
└── latest-changes-<date>.md                    # new endpoint + new reservation rejection (behavior change)
```

**Structure Decision**: This is a single Laravel backend and it follows the existing
layers. A new service holds the logic that has several callers. The controller only
authorizes, validates, delegates and responds, and everything else extends what's already
there. Frontend work is out of this repo (D13).

## Delivery order

1. Enum helpers and permissions, then `AvailabilityService::forHotel()` with its tests
   (US1 and US3).
2. The endpoint, policy ability, resource, docs, and the isolation and permission tests
   (US1).
3. The guard: locks, footprint, exception, override permission, audit, import flag and
   concurrency test (US2).
4. The AI tools and agent instructions (US4).

Every step leaves the suite green. Step 3 is a behavior change: reservations that used to
oversell are now rejected. It must be announced in `latest-changes`.

## Complexity Tracking

No violations.
