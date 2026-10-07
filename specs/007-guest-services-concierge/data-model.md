# Data Model: Guest Services and Concierge on the New Domain

**Feature**: [spec.md](spec.md) · **Research**: [research.md](research.md)

One migration changes `tasks`. Everything else is new enum cases, or reads of existing
tables. No column is dropped and no shipped migration is edited.

## Migration `2026_10_06_000001_add_guest_request_fields_to_tasks_table`

### New columns on `tasks`

| Column | Type | Null | Meaning |
| --- | --- | --- | --- |
| `guest_notice_status` | string | yes | `pending`, `sent`, `skipped` or `failed`. Null means not yet processed (R8) |
| `guest_notice_channel` | string | yes | `whatsapp` or `email`; set only when `sent` |
| `guest_notice_reason` | string | yes | Why it was not sent: `cancelled`, `no_contact` or `send_failed` |
| `guest_notice_at` | timestampTz | yes | When the outcome was recorded |

### Constraints

- `CHECK (guest_notice_status IN ('pending', 'sent', 'skipped', 'failed'))`
- `CHECK (guest_notice_channel IN ('whatsapp', 'email'))`
- `CHECK (guest_notice_reason IN ('cancelled', 'no_contact', 'send_failed'))`

### Indexes

**One open escalation per guest per hotel** (R5):

```sql
CREATE UNIQUE INDEX tasks_one_open_escalation_per_guest ON tasks (hotel_id, guest_id)
WHERE guest_signal = 'escalation' AND status IN ('pending', 'in_progress') AND deleted_at IS NULL
```

**One open room-change request per stay, or per reservation before arrival** (R4,
FR-028):

```sql
CREATE UNIQUE INDEX tasks_one_open_room_change_per_stay ON tasks (COALESCE(stay_id, reservation_id))
WHERE guest_signal = 'room_change_request' AND status IN ('pending', 'in_progress') AND deleted_at IS NULL
```

`stay_id` and `reservation_id` are both UUIDs, so `COALESCE` keeps one type. A request
filed before arrival (reservation only) and one filed after check-in (stay) are separate
keys. That is intended: the guest's situation changed.

### Existing data

- **Duplicate open escalations.** Before creating the escalation index, the migration
  keeps the newest open escalation per `(hotel_id, guest_id)`. Older open duplicates
  have their descriptions merged into it and are then closed as `completed` with
  `guest_notice_status = 'skipped'`, so closing them sends nothing.
- **Already-completed guest tasks** are stamped `guest_notice_status = 'skipped'`, so
  this feature never notifies about work finished before it shipped.

### `down()`

Drops both indexes, the three checks and the four columns.

## Task (existing model, changed)

- **`$casts` additions**:
  - `guest_notice_status` → `GuestNoticeStatus`
  - `guest_notice_channel` → `GuestNoticeChannel`
  - `guest_notice_reason` → `GuestNoticeReason`
  - `guest_notice_at` → `datetime`
- **Not fillable**: the four notice columns are written only by
  `SendGuestRequestNoticeJob` and the migration, through `forceFill` or a conditional
  update.
- **`eventLoggedAttributes()`** adds `guest_notice_status`, `guest_notice_channel` and
  `guest_notice_reason`.
- **`queueGuestNotice()`**: dispatches `SendGuestRequestNoticeJob::dispatch($this->id)->afterCommit()`
  when the conditions below hold. Called by the hook, and by any closing path that cannot
  save the model (R7).
- **`booted()` addition** (R7): an `updated` listener that calls `queueGuestNotice()`. When all of these hold, it
  dispatches `SendGuestRequestNoticeJob::dispatch($task->id)->afterCommit()`:
  - `status` became `completed` or `cancelled`;
  - `guest_id` is set;
  - `guest_signal?->notifiesOnCompletion()` is true, or the signal is
    `cancellation_request`;
  - `guest_notice_status` is null.
- **New scopes**:
  - `guestRequests()`: `guest_signal` in service, maintenance, room change,
    cancellation or escalation.
  - `ownedByGuest(Hotel $hotel, Guest $guest)`: removes the hotel scope and filters on
    both ids.

### Guest-facing status (`GetOwnRequestsTool` only, not stored)

| `status` | Guest sees |
| --- | --- |
| `pending` | received |
| `in_progress` | in progress |
| `completed` / `cancelled` | not listed (open requests only) |

## Lifecycle of a guest request's notice

```text
          task created (guest_notice_status = null)
                         │
       status → completed / cancelled (any code path)
                         │
          Task::updated → SendGuestRequestNoticeJob (after commit)
                         │
     claim: null → pending (conditional UPDATE; 0 rows → stop)
                         │
     ┌──────────── which outcome? ────────────┐
 cancelled service/maint./room       completed / cancellation decided
     │                                         │
 skipped (cancelled)              window open and phone? ── yes ──► WhatsApp
                                               │ no                 │ fails ×3
                                               ▼                    ▼
                                       email on file? ── yes ──► email ── fails ×3 ──► failed (send_failed)
                                               │ no
                                               ▼
                                     skipped (no_contact)

 sent: guest_notice_status = sent, guest_notice_channel = whatsapp | email
 every final state sets guest_notice_at and records a guest_notified event
```

Which message is sent:

| Task | Trigger | Message key |
| --- | --- | --- |
| service, maintenance, room change | `completed` | `completed` |
| service, maintenance, room change | `cancelled` | none: `skipped / cancelled` |
| cancellation request | `completed`, `resolution = approved` | `cancellation_approved` |
| cancellation request | `completed`, `resolution = declined` | `cancellation_declined` (with `resolution_note`) |
| escalation, booking follow-up, staff tasks | any | none: the job is never dispatched |

## Enums

### `GuestSignal` (changed)

New cases:

- `MAINTENANCE_REQUEST = 'maintenance_request'`
- `ROOM_CHANGE_REQUEST = 'room_change_request'`

New methods:

- `notifiesOnCompletion(): bool`: service, maintenance, room change.
- `isComplaint(): bool`: service, maintenance, room change (used by the pitching gate,
  R17).

### New enums

- **`GuestNoticeStatus`**: `PENDING`, `SENT`, `SKIPPED`, `FAILED`.
- **`GuestNoticeChannel`**: `WHATSAPP`, `EMAIL`.
- **`GuestNoticeReason`**: `CANCELLED`, `NO_CONTACT`, `SEND_FAILED`.

### `InboundMessageStatus` (changed)

New case `IGNORED = 'ignored'`: the sender matched no guest or staff member, so no reply
was generated (R12).

## Reservation (existing model, changed)

`isActive(): bool` (R6) is true when:

- `status` is `pending`, `confirmed` or `checked_in`, and
- `departure_date` is today or later in the hotel's time zone.

It is not stored.

## Read-only entities used by the guest tools

| Entity | Read by | Scoping |
| --- | --- | --- |
| Reservation, ReservationRoom, Stay, Room | `GetOwnReservationTool` | the resolved reservation only |
| Booking | `GetOwnBookingsTool` | `hotel_id` + `guest_id` |
| Task | `GetOwnRequestsTool` | `ownedByGuest()` + `guestRequests()` + `open()` |
| WhatsAppInboundMessage | `SendGuestRequestNoticeJob` (window, R9) | phone digits; no hotel scope (platform-level table) |
| Task (in the job) | `SendGuestRequestNoticeJob` | first lookup by id without scope, then everything inside `TenantContext::runForHotel($task->hotel_id)` |
| Hotel | notice wording, maintenance routing | the task's hotel |

## Validation rules

All of these are enforced in tools and services, not only in the prompt:

| Rule | Where |
| --- | --- |
| Service, maintenance and room change need `reservation->isActive()` | each tool, before any write (FR-030) |
| Category must belong to the hotel, else no category | `CreateGuestServiceRequestTool::taskCategoryId` (existing) |
| `add_to_request_id` must be the guest's own open request of the same kind, else ignored | `GuestRequestService::appendDetail` (R14) |
| Room number, when given, must match one of the guest's in-house stays | `stayFor()` (existing) |
| At most one open escalation per guest, and one open room change per stay or reservation | partial unique indexes plus a catch of `23505` |
