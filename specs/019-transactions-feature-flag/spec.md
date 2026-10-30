# Feature Specification: Transactions Feature Flag

**Feature Branch**: `019-transactions-feature-flag`

**Created**: 2026-10-30

**Status**: Draft (clarified)

**Input**: User description: "Transactions feature flag (master plan SPEC-005, decision D11). Put the existing transactions ledger (ledger, import, reversal, attribution) behind a `features.transactions` flag: a config default plus a per-hotel override, off by default. When off for a hotel: the /api/transaction endpoints (index, show, import, reverse) are unavailable, MVP flows (bookings, stays, check-out, etc.) skip ledger writes, and the frontend can read the flag to hide the Transactions menu. When on: current behaviour unchanged. Keep all transaction code and tables; no new finance logic. Must respect tenancy (TenantContext) and existing Permission checks."

## Clarifications

### Session 2026-10-30

- Q: How can transactions be turned on? → A: **Platform configuration only.** One
  platform-wide setting, off by default. No per-hotel override, and no endpoint or UI lets
  any user (super admin included) change it. Turning it on is a deploy/environment change.
- Q: What happens to existing ledger data? → A: There is none; the system is still in
  development. No backfill or migration of existing rows is needed.

## Context

The platform has a working transactions ledger: an append-only record of guest charges
(any revenue centre, not only activities), filled by a spreadsheet import from the
hotel's POS/PMS, corrected by reversals, and attributed to guests, stays, rooms,
activities and bookings. Its only consumer today is the conversion analytics
`settlement_rate`. Finance is not part of the MVP (master plan D11; constitution MVP
boundary: "No MVP feature should depend on the transaction ledger"). The ledger is
switched off, without deleting code or tables, until finance is properly built.

Where the ledger is touched today:

| Touch point | Kind | Today |
| --- | --- | --- |
| Transaction list, detail | read | Available to staff with `transactions.view` |
| Transaction import | write | Available to staff with `transactions.import`; the **only** path that creates ledger rows |
| Transaction reversal | write | Available to staff with `transactions.reverse` |
| Conversion analytics `settlement_rate` | read | Computed on the staff endpoint; already left out for the Admin AI |
| `transactions` on the booking detail response | read | Always loaded by booking `show`; the stay and guest resources support it but nothing loads it |
| Booking settlement linking | write (link only) | Service method, no endpoint calls it |

No booking, stay, check-in, check-out, reservation or activity flow creates ledger rows.

## User Scenarios & Testing *(mandatory)*

### User Story 1 - The ledger is off (Priority: P1)

The platform runs the MVP without finance. Nobody — super admin, admin or employee —
can list, view, import or reverse transactions, and no part of the product shows
ledger-derived figures.

**Why this priority**: This is the shipped state and the whole point of D11.

**Independent Test**: With the setting unconfigured, call each transaction operation as
a super admin, an admin and an employee with a full transactions role; confirm all are
refused and no ledger row is created or changed.

**Acceptance Scenarios**:

1. **Given** the setting is not configured, **When** an admin lists, views, imports or
   reverses transactions, **Then** each request is refused as "not available" and the
   ledger is unchanged.
2. **Given** the setting is off, **When** an employee whose staff role grants every
   `transactions.*` permission makes the same requests, **Then** they are refused the same
   way: the switch outranks permissions.
3. **Given** the setting is off, **When** a super admin makes the same requests for any
   hotel, **Then** they are refused the same way.
4. **Given** the setting is off, **When** staff open conversion analytics, **Then** the
   response carries no settlement figures.
5. **Given** the setting is off, **When** staff ask for a booking, stay or guest with its
   transactions included, **Then** no transactions are returned and the request still
   succeeds.

---

### User Story 2 - Turned on by configuration, behaviour is unchanged (Priority: P1)

When finance is ready, the developer turns the setting on in the platform configuration.
Everything about transactions then works exactly as it does today.

**Why this priority**: The switch must not break the code it hides; otherwise turning it
on later would be a rebuild.

**Independent Test**: Turn the setting on in the test environment and run the existing
transaction tests unchanged.

**Acceptance Scenarios**:

1. **Given** the setting is on, **When** staff with the right permissions list, view,
   import or reverse transactions, **Then** the results are identical to today.
2. **Given** the setting is on, **When** an employee lacks `transactions.view`, **Then**
   they get a permission refusal, as today.
3. **Given** the setting is on, **When** staff of Hotel A use transactions, **Then** they
   see only Hotel A's ledger, as today.

---

### User Story 3 - The frontend knows whether to show Transactions (Priority: P2)

The frontend learns whether transactions are enabled and hides the Transactions menu and
ledger figures when they are not.

**Why this priority**: Without it the UI shows a menu that only leads to errors. The
backend refusal (Story 1) is the real protection; this is presentation.

**Independent Test**: Fetch the hotel as its staff and confirm the response states
whether transactions are enabled, matching the platform setting.

**Acceptance Scenarios**:

1. **Given** the setting is off, **When** staff fetch their hotel, **Then** it says
   transactions are disabled.
2. **Given** the setting is on, **When** staff fetch their hotel, **Then** it says
   transactions are enabled.

### Edge Cases

- **Import in progress when the setting is turned off**: the import runs inside one
  request, so it either completes before the deploy or is refused after it.
- **Request for a transaction id from another hotel**: when on, refused exactly as today
  (not found); when off, refused as "not available" — either way nothing is revealed.
- **Metering**: a refused import records no imported-row usage.
- **Clients trying to change the setting**: no hotel or user field accepts it; a value
  sent in a hotel update is ignored.
- **Admin AI**: already excludes settlement figures and has no transaction tool; it stays
  that way whatever the setting.

## Requirements *(mandatory)*

### Functional Requirements

- **FR-001**: The platform MUST have a single setting for whether transactions are
  enabled. It MUST be **off** when not configured.
- **FR-002**: The setting MUST only be changeable through platform configuration. No API
  endpoint, hotel setting or user action — for any role, super admin included — may
  change it.
- **FR-003**: When the setting is off, the transaction list, detail, import and reversal
  operations MUST be refused for every actor and every hotel, and MUST NOT create, change
  or reveal ledger rows.
- **FR-004**: The refusal in FR-003 MUST be distinguishable from a permission refusal, so
  a client can tell "feature off" apart from "you may not".
- **FR-005**: When the setting is on, every transaction operation MUST behave exactly as
  today, including permission checks and tenant isolation.
- **FR-006**: The setting MUST be checked in addition to permissions, never instead of
  them: turning it on grants no one access they lacked.
- **FR-007**: When the setting is off, ledger-derived figures (conversion
  `settlement_rate`) MUST be left out of analytics, and transactions MUST NOT be returned
  when a booking, stay or guest is fetched with its transactions.
- **FR-008**: No booking, stay, check-in, check-out, reservation or activity flow may
  create ledger rows when the setting is off. (None does today; this MUST stay true and be
  covered by a test.)
- **FR-009**: The hotel's response MUST state whether transactions are enabled, so the
  frontend can hide the Transactions menu with no extra request.
- **FR-010**: Any future queued or scheduled work that writes to the ledger MUST check the
  setting when it runs and write nothing when it is off. (Today the import runs inside the
  request, so the endpoint check covers it.)
- **FR-011**: Existing transaction code and tables MUST be kept. Turning the setting off
  MUST NOT delete or alter any ledger row.
- **FR-012**: The transaction permissions (`transactions.view`, `transactions.import`,
  `transactions.reverse`) MUST stay unchanged, and roles keep holding them while the
  setting is off, so they work again once it is turned on. While it is off the permission
  list (`GET /api/permissions`) MUST NOT offer them, since their endpoints answer 404
  (changed after code review, 2026-10-30).

### Key Entities

- **Transactions setting**: one platform-wide on/off value from configuration; off
  unless configured. Not stored per hotel, not editable through the API.
- **Transaction (existing)**: unchanged. Append-only ledger row; only its reachability
  depends on the setting.

## Success Criteria *(mandatory)*

### Measurable Outcomes

- **SC-001**: With the setting off, 100% of the four transaction operations are refused
  for every actor type (super admin, admin, employee with full transactions role), and the
  ledger row count is unchanged.
- **SC-002**: With the setting on, the existing transaction tests pass unchanged.
- **SC-003**: With the setting off, a full MVP journey (reservation → check-in → activity
  booking → check-out) leaves zero ledger rows.
- **SC-004**: No request by any user can turn the setting on.
- **SC-005**: The frontend can decide whether to show the Transactions menu from a single
  hotel fetch.

## Assumptions

- "Not available" is a **404** with a message saying transactions are not enabled, which
  keeps the endpoints hidden as D11 asks and satisfies FR-004 through the message. `403`
  stays reserved for missing permissions.
- A request for the `transactions` include on bookings, stays or guests is silently
  ignored when off rather than rejected, so existing clients do not break.
- Turning the setting on is a developer action (environment/config change and deploy);
  it needs no audit record in the application.
- The flag is exposed on the hotel response even though it is platform-wide, because that
  is what the frontend already fetches; a per-hotel value can be added later without
  changing the field.
- There is no ledger data yet (development only), so no data migration or backfill.
- Booking settlement linking has no endpoint; it is left as is.
- "MVP flows skip ledger writes" is already true in the code; this spec makes it a tested
  guarantee.
- Frontend work (hiding the menu and ledger figures) is done in `ecosystem-frontend` from
  a frontend-changes handoff; this spec only provides the flag.
- Out of scope: per-hotel switching, any admin toggle, new finance features, POS
  integration, removing transaction code, and changing the Admin AI.
