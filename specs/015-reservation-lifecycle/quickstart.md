# Quickstart: Reservation Lifecycle and No-Show

Validation guide for `015-reservation-lifecycle`. Contract details:
[contracts/no-show-api.md](contracts/no-show-api.md). Data and transitions:
[data-model.md](data-model.md).

## Prerequisites

```bash
docker compose -f .postgres/compose.yaml up -d
php artisan migrate
```

## Run

```bash
php artisan test --filter='NoShow|ReservationLifecycle'
php artisan test --parallel
./vendor/bin/pint
```

## Acceptance scenarios

| # | Scenario | Expected outcome |
| --- | --- | --- |
| 1 | Mark a confirmed reservation that arrived yesterday as a no-show. | `no_show`; stays `no_show`; rooms free in availability for those nights; audit with actor and reason. |
| 2 | Try it on a reservation arriving tomorrow. | `422` on `stays`; nothing changes. |
| 3 | Try it on a reservation with one room checked in. | `422`; marking the unarrived room alone succeeds. |
| 4 | Three rooms: two check in, third marked no-show, then both check out. | Reservation `checked_out`; third stay stays `no_show`; no "cancel the rooms" error. |
| 5 | Mark every room no-show one at a time. | Reservation becomes `no_show` on the last one. |
| 6 | Undo a whole no-show. | Back to its previous status (pending or confirmed); stays `expected`. |
| 7 | Undo after its rooms were sold to someone else. | `422` on `rooms`; with `overbook_override` and the overbook permission it succeeds. |
| 8 | Undo one room's no-show on a checked-out reservation. | `422`: the room's no-show is final. |
| 9 | `PUT` with `status: no_show`, or `checked_out → pending`. | `422` on `status` naming the change. |
| 10 | Edit the dates, then the source, of a no-show reservation; then edit its special requests and guest list. | Dates and source rejected ("Undo the no-show first."); special requests and guest list saved. |
| 11 | An employee without `reservations.no_show` calls any of the four endpoints. | `403`. |
| 12 | Admin AI marks room 214 of a reservation as a no-show. | Same result as the API; audit actor is the AI. |
| 13 | A no-show reservation with pending recommendations. | They expire; no pitch or proactive message; no memory extraction. |
| 14 | Another hotel's admin calls the endpoints. | `403`; nothing changes. |
| 15 | Assign the room of a no-show line to another booking on the same nights. | Allowed (no clash). |
| 16 | A no-show reservation: arrivals list, pitch eligibility, proactive guardrails, memory extraction. | Not in arrivals; not eligible; no proactive message; no extraction queued. |
