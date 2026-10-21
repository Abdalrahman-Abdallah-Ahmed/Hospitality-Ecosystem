# Research: Reservation Guests and Party Composition

## Decision: retain `reservations.guest_id` as the primary-guest pointer

**Rationale**: Existing reservation, stay, Concierge, booking, notification and recommendation flows already use `guest_id`. Keeping it avoids a broad and risky rewrite while a new `reservation_guests` relation records the complete party. The primary pivot row and `guest_id` must change in the same transaction.

**Alternatives considered**:

- Derive the primary guest solely from `reservation_guests`: rejected because every existing caller would need to change before the feature can ship.
- Keep a guest list without a primary flag: rejected because the product requires a clear contact and existing integrations need a stable owner.

## Decision: use a dedicated, hotel-scoped reservation-guest model

**Rationale**: A model with `BelongsToHotel`, UUIDs, soft deletes and event logging matches the project's tenant and audit conventions. It can enforce one guest per reservation and provide a direct, auditable representation of party membership.

**Alternatives considered**:

- A bare many-to-many pivot: rejected because it provides no consistent tenant scoping or audit behavior.
- Store guest ids in JSON: rejected because it cannot reliably enforce guest ownership, uniqueness or relational querying.

## Decision: store children ages as a JSON integer array on `reservations`

**Rationale**: D8 defines ages as reservation-wide totals and does not allocate children to rooms or named guest profiles. A JSON array preserves the given order and supports infants without requiring child contact details.

**Alternatives considered**:

- A child entity per age: rejected because it conflates party totals with guest identity and adds personal-data collection outside this feature.
- Separate per-room age arrays: rejected because per-room party allocation is post-MVP.

## Decision: validate and synchronize guest lists inside the existing reservation command path

**Rationale**: `ReservationCreator` already provides transactional creation and updates for the staff API, Admin AI and imports. Extending it prevents different rules from emerging at different entry points.

**Alternatives considered**:

- Controller- or tool-specific synchronization: rejected because imports and AI could drift from staff validation.
- A separate guest-list endpoint: rejected because it would allow a reservation to exist temporarily without a valid primary guest.

## Decision: match a named guest by phone first, then by an unambiguous same-hotel email

**Rationale**: The feature requires callers to reuse an existing guest named by phone or
email. Phone remains the preferred identity because it is the established WhatsApp
identifier. An email-only match is safe only when exactly one live profile at the hotel has
that email; shared email addresses cannot identify a person reliably. A phone and email
that resolve to different profiles is rejected rather than guessed.

**Alternatives considered**:

- Match any first email result: rejected because households can share an email address.
- Create a new profile whenever email is supplied: rejected because it violates the
  no-duplicate requirement.

## Decision: additional guests have no reservation authority in WhatsApp

**Rationale**: The constitution identifies the registered WhatsApp number as the primary guest identity and requires conservative identity resolution. An additional guest remains a normal hotel guest profile, but cannot see or act on another person's reservation.

**Alternatives considered**:

- Treat every listed guest as an owner: rejected because it exposes reservation details and privileged operations to people not established as the primary contact.

## Decision: permit legacy reservations with children and no ages until their party data changes

**Rationale**: Existing data cannot satisfy a newly introduced age requirement retroactively. The release preserves it, while every new party or age write enforces the invariant.

**Alternatives considered**:

- Invent ages during migration: rejected because it produces false guest data.
- Reject any edit to legacy reservations: rejected because it blocks operational corrections.
