# Implementation Plan: Reservation Import v2 and Reconciliation

**Branch**: `013-reservation-import-v2` | **Date**: 2026-10-12 | **Spec**: [spec.md](spec.md)

**Status**: **BLOCKED — not planned yet.** Resume `/speckit-plan` once the prerequisites
below have shipped.

## Blocker

The spec maps several guests per reservation (FR-013), children's ages (FR-014) and
no-show rows. These rely on Phase 2 specs that were never built:

- **SPEC-011 Reservation Guests & Party**: there is no `reservation_guests` table and no
  `reservations.children_ages` column (D8). A reservation has only `guest_id`.
  `specs/002-reservation-rooms` deferred it; specs 004 and 009 note it as "not built yet".
- **SPEC-012 Reservation Lifecycle**: `ReservationStatus` has no `no_show` case.

Decision (2026-10-12, project owner): specify and ship SPEC-011 and SPEC-012 in full as
their own features first, then plan Phase 13 on top of them.

## Findings to carry into the real plan

These came from researching the code before the plan stopped:

- **Guest phone already optional**: `guests.phone_number` is nullable, and `guests.external_id`
  exists with a unique `(hotel_id, channel, external_id)` index. Clarification Q4 (guests
  without a phone) needs no schema change. External guest id matching can reuse
  `external_id` with an import channel or source key.
- **Phone normalization gap**: `App\Support\PhoneNumber::digits()` does not strip a leading
  `00` international prefix and does not add a country code to national numbers. So spec
  scenario US4-1 ("0020 100 123 4567" matches "+201001234567") fails today. The import
  needs a normalizer: strip `00`, and for a leading single `0`, prepend the calling code
  derived from `hotels.country_code`.
- **Conflict detection**: `GuestIdentityService::computeIdentityHash()` hashes email *or*
  phone (email wins), so one hash lookup cannot see a phone-vs-email conflict (FR-019).
  Look up separately by phone digits (expression index exists), by normalized email and by
  external id, then compare the results.
- **Room clash (FR-012a)**: reuse `RoomAssignmentRules::reason()`, which already finds
  overlapping live lines for a room and names the clashing reservation. The import passes
  the line in unassigned instead of throwing.
- **In-house import (FR-017a)**: `ReservationCreator::create(..., fromImport: true)` already
  calls `syncStays(recordStatus: true)` and `syncRoomOccupancy()`, so a checked-in import
  already marks rooms occupied. Past checked-out imports go through `StayService::checkOut`;
  verify they don't dirty rooms or create cleaning tasks.
- **Super admin (FR-031)**: `ReservationController::import()` uses `$request->user()->hotel`.
  It must switch to `resolveHotel()` with a required `hotel_id` for super admins.
- **Queue**: the default connection is `database`. Named queues and Redis are Phase 14
  (F9). Per-hotel serialization (FR-023) can use a unique/locked job keyed by hotel, or a
  "next queued import" handoff when a run finishes.
- **Audit**: keep `EventLogger::withoutRecording()` around the row writes plus one
  `reservations_imported` summary event per run (FR-032).
- **Existing tests**: `tests/Feature/ReservationImportTest.php` asserts the synchronous
  `200` contract. It will be rewritten for the queued contract (FR-005, breaking).
- **Library**: `maatwebsite/excel` ^3.1 (`ToCollection`, `WithHeadingRow`) stays the parser.
  Chunked reading suits large files.
