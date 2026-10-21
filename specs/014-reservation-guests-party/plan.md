# Implementation Plan: Reservation Guests and Party Composition

**Branch**: `014-reservation-guests-party` | **Date**: 2026-10-14 | **Spec**: [spec.md](spec.md)

## Summary

Add a hotel-scoped guest list to each reservation, with exactly one primary guest, and add children's ages to the reservation party totals. Preserve `reservations.guest_id` as the primary-guest pointer so existing stays, Concierge recognition, notifications, bookings and recommendations keep their current meaning. All staff, Admin AI and import entry points use the existing reservation command path and its transactional guarantees.

## Technical Context

**Language/Version**: PHP 8.3, Laravel 13.

**Primary Dependencies**: Eloquent, PostgreSQL, Sanctum, Laravel AI tools, Pest.

**Storage**: PostgreSQL. New `reservation_guests` relation and `children_ages` JSON integer array on `reservations`.

**Testing**: Pest feature tests against the real PostgreSQL test database.

**Target Platform**: JSON API consumed by the separate `ecosystem-frontend` application and by the existing WhatsApp and Admin AI flows.

**Project Type**: Multi-tenant Laravel web service.

**Performance Goals**: A reservation detail returns its guest list and party ages in one normal detail read; normal reservation lists continue to be paginated.

**Constraints**: Tenant scoping, permission-based authorization, append-only auditing, existing API response envelope, and no physical-room allocation per guest in MVP.

**Scale/Scope**: One or more named guests per reservation; named guests cannot exceed the reservation's adults plus children total. Party totals and ages apply across all room lines.

## Constitution Check

| Principle | Design response | Status |
| --- | --- | --- |
| Preserve and evolve | Keep `reservations.guest_id` as the primary pointer; extend `ReservationCreator` and `ReservationCommands`. | Pass |
| Tenant isolation | Guest lookup, pivot rows, requests, resources, tools and import all use the reservation's hotel. | Pass |
| Permission authorization | Reuse `reservations.create` and `reservations.update`; no role-name checks or new permission. | Pass |
| AI acts through tools | Admin AI uses the same command path and request-level rules; Concierge remains primary-guest-only. | Pass |
| Data integrity and audit | Write the reservation, pivot rows, ages, stay-primary changes and audit events in one transaction. | Pass |
| Tested domain boundary | Add command, API, AI, migration and tenant-isolation coverage. | Pass |
| Auditability and observability | Guest-list links and ages are audited through model event logging with the human or AI actor; the bulk backfill writes directly, like the reservation-room backfill. | Pass |
| Spec-driven delivery | Spec → clarification → this plan → tasks; analysis findings folded back into spec and tasks. | Pass |

No constitution violation or exception is required. The post-design review reaches the same result.

## Project Structure

```text
app/
├── Models/
│   ├── Reservation.php                     # guests relation, children ages cast, primary pointer
│   └── ReservationGuest.php                # new hotel-scoped reservation/guest link
├── Support/Reservations/
│   ├── ReservationCreator.php              # create/update transaction and guest-list sync
│   └── ReservationGuestSync.php            # new invariant and pivot synchronization logic
├── Services/GuestIdentityService.php        # phone-first, unambiguous-email guest resolution
├── Services/Reservations/ReservationCommands.php
├── Http/
│   ├── Requests/StoreReservationRequest.php
│   ├── Requests/UpdateReservationRequest.php
│   └── Resources/ReservationResource.php
├── Ai/Tools/
│   ├── CreateReservationTool.php
│   ├── UpdateReservationTool.php
│   ├── GetReservationTool.php
│   ├── GetReservationsTool.php
│   └── GetOwnReservationTool.php
└── Imports/ReservationsImport.php

database/migrations/
├── 2026_10_14_000001_create_reservation_guests_table.php
├── 2026_10_14_000002_add_children_ages_to_reservations_table.php
└── 2026_10_14_000003_backfill_reservation_guests.php

tests/Feature/
├── ReservationGuestsTest.php                # new domain and API contract coverage
├── ReservationGuestsMigrationTest.php       # new migration coverage
├── ReservationControllerTest.php
├── ReservationRoomsAiToolsTest.php
├── CreateReservationToolTest.php
├── ReservationImportTest.php
├── SenderRecognitionServiceTest.php
└── TenantIsolationTest.php

docs/
├── reservations-api-documentation.md
└── latest-changes-2026-10-14.md
```

**Structure Decision**: Extend the single existing Laravel API. The guest-list synchronizer belongs beside `ReservationCreator`, which already owns the common transactional reservation write path.

## Implementation Order

1. Add the relation, ages column, casts, model relations and database constraints.
2. Backfill every existing reservation with its current guest as the primary link and an empty ages list, safely and idempotently.
3. Add phone-first, unambiguous-email guest resolution and shared guest-list/age validation to `ReservationCreator` and `ReservationCommands`; preserve the primary `guest_id` and update eligible stays.
4. Update staff request validation, controller loading and reservation resources.
5. Update Admin AI read/write tools and import mapping to call the same command path.
6. Update Concierge/sender-recognition behavior only where it must explicitly use the primary pointer; additional guests receive no reservation authority.
7. Add migration, API, AI, audit, regression and tenant-isolation tests.
8. Update reservation API documentation and the breaking-change summary, then format and run the full suite.

## Risks

| Risk | Mitigation |
| --- | --- |
| A second source of truth for the primary guest | Maintain `guest_id` and the primary pivot row atomically; test them together. |
| Cross-hotel guest attachment | Validate every supplied guest through the reservation hotel scope; treat a foreign id as unavailable. |
| Legacy children totals lack ages | Backfill an empty array and enforce matching only when the children count or ages are changed. |
| Existing callers expect one guest | Keep the primary guest relation and add the guest list as an additive response field. |
| AI or import bypasses validation | Route all writes through `ReservationCreator`; test each entry point against the same invalid shapes. |

## Complexity Tracking

No constitution violations to justify.
