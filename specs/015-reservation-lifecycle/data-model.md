# Data Model: Reservation Lifecycle and No-Show

**Feature**: [spec.md](spec.md) · **Research**: [research.md](research.md)

## Changed: `reservations`

| Field | Change | Rules |
| --- | --- | --- |
| `status` | New value `no_show` (string column, no schema change) | Changes follow the lifecycle table below. `no_show` holds no inventory. |
| `status_before_no_show` | **New**, nullable string | Set to `pending` or `confirmed` when the reservation becomes `no_show`; read and cleared on undo. Null otherwise. |

Migration: `2026_10_21_000001_add_status_before_no_show_to_reservations_table.php`
(nullable, no backfill: no reservation is `no_show` today).

## Unchanged schema, new use: `stays`

`stays.status = no_show` already exists. This feature is the first to set it (outside
fixtures), through `NoShowService`, and to set it back to `expected` on undo.

## Unchanged: `reservation_rooms`

Line status stays `reserved` / `cancelled`. A no-show line is a `reserved` line whose stay
is `no_show`. Its `room_id` is kept as history.

## Lifecycle table (spec FR-001)

| From | To | Operation | Guard |
| --- | --- | --- | --- |
| (new) | `pending`, `confirmed` | create | — |
| `pending` | `confirmed` | edit | — |
| `confirmed` | `pending` | edit | — |
| `pending`, `confirmed` | `cancelled` | edit / cancel | no stay in house (existing) |
| `cancelled` | `pending`, `confirmed` | edit | availability re-checked (existing) |
| `confirmed` | `checked_in` | check-in | SPEC-024 rules (existing) |
| `checked_in` | `checked_out` | check-out | SPEC-025 rules (existing) |
| `pending`, `confirmed` | `no_show` | no-show (whole), or the last live stay marked no-show | arrival day reached; no stay in house or departed |
| `no_show` | `status_before_no_show` | undo no-show (whole) | availability re-checked; any time |
| (import) | any, incl. `checked_out`, `no_show` | import | lifecycle table bypassed |

Every pair not in the table is rejected, e.g. `checked_out → pending`, `no_show →
cancelled`, `cancelled → no_show`, and any edit to `no_show`.

## Stay-level transitions added

| From | To | Operation | Guard |
| --- | --- | --- | --- |
| `expected` | `no_show` | no-show (room) | arrival day reached; reservation `pending`/`confirmed`/`checked_in` |
| `no_show` | `expected` | undo (room) | reservation not `checked_out`; availability re-checked |
| `expected` (all) | `no_show` | no-show (whole) | as above |
| `no_show` (all) | `expected` | undo (whole) | as above |

## Edit restrictions while `no_show` (FR-010a)

Allow-list: `guests` (including new guests' contact details) and `special_requests`.
Every other reservation field in the request is rejected with "Undo the no-show first.",
e.g. `arrival_date`, `departure_date`, `adults`, `children`, `children_ages`, `rooms`,
`status`, `reservation_id`, `source`, `reservation_value`, `currency`, `guest_id`.

## Permission

`reservations.no_show`: mark and undo a no-show, whole or per room, through the API or
the Admin AI. Not in `employeeDefaults()`.

## Audit

- Existing model events: `reservation.updated` (status, `status_before_no_show`) and
  `stay.no_show` / `stay.expected` with the actor.
- New explicit events with the optional reason: `reservation.no_show_marked`,
  `reservation.no_show_undone`, with `changes` listing the affected stay ids.
