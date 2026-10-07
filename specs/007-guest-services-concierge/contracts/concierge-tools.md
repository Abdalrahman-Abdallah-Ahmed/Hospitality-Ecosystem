# Contract: Guest Concierge Tools

**Feature**: [spec.md](../spec.md) · **Research**: [research.md](../research.md)

The Guest Concierge reaches the system only through these tools. Each is built with the
`Guest`, `Hotel` and `Reservation` that `SenderRecognitionService` resolved for the
message, plus the turn's `PitchTurn`. None accepts a guest, hotel, stay or room id as
input (R15). Results are plain strings or JSON strings for the model.

`GuestConciergeAgent::tools()` returns exactly this set, which the architecture test
pins (R15):

| Tool | Status | Kind |
| --- | --- | --- |
| `GetOwnReservationTool` | changed | read |
| `GetOwnBookingsTool` | **new** | read |
| `GetOwnRequestsTool` | **new** | read |
| `GetActivitiesTool` | unchanged (Phase 6) | read |
| `GetGuestAvailabilityTool` | unchanged | read |
| `KnowledgeSearchTool` | unchanged | read |
| `GetRecommendationsTool` | unchanged | read |
| `GetTaskCategoriesTool` | unchanged | read |
| `UpdateRecommendationTool` | unchanged | write (recommendation reaction only) |
| `CreateBookingTool` | unchanged (Phase 6) | write |
| `RequestBookingCancellationTool` | unchanged (Phase 6) | write (request only) |
| `CreateGuestServiceRequestTool` | changed | write |
| `RequestRoomChangeTool` | **new** | write |
| `EscalateToHumanTool` | changed | write |

None of these, nor any class they call, may cancel a booking, change a booking's status,
assign or change a room, change room status, or check a guest in or out.

---

## Shared refusal: no active reservation

The service, maintenance and room-change tools check `Reservation::isActive()` first
(R6). If it is false, they write nothing and return:

> `This needs a current or upcoming reservation at {hotel}. The guest has none, so nothing was filed. Offer to connect them with staff instead.`

---

## GetOwnReservationTool (changed)

**Input**: none.

**Output** (JSON). Today's fields, plus `is_active` and a per-room stay status:

```json
{
  "id": "…", "reservation_id": "RES-…", "status": "checked_in", "is_active": true,
  "arrival_date": "2026-10-04", "departure_date": "2026-10-09",
  "adults": 2, "children": 1,
  "rooms": [
    { "room_type": "Deluxe", "room_number": "214", "status": "reserved", "stay_status": "in_house" },
    { "room_type": "Deluxe", "room_number": null,  "status": "reserved", "stay_status": "expected" }
  ],
  "room_summary": ["2 × Deluxe"],
  "reservation_value": "1250.00", "currency": "USD", "special_requests": null
}
```

When there is no reservation: `No reservation found for this guest.`

## GetOwnBookingsTool (new)

**Input**: none.

**Output** (JSON). Upcoming bookings first (soonest first), then up to 10 recent past
ones:

```json
{
  "upcoming": [
    { "reference": "DCB-4K2P", "activity": "Sunset cruise", "date": "2026-10-07", "time": "17:30",
      "party_size": 3, "status": "confirmed", "price": "180.00", "currency": "USD",
      "cancellation_requested": false }
  ],
  "past": []
}
```

When the guest has none: `The guest has no activity bookings at {hotel}.`

## GetOwnRequestsTool (new)

**Input**: none.

**Output** (JSON). The guest's open requests only:

```json
[
  { "id": "…", "kind": "maintenance_request", "title": "Air conditioning not cooling",
    "status": "received", "created_at": "2026-10-06T09:12:00+03:00", "room_number": "214" }
]
```

`status` is `received` or `in progress` (data model). The output never includes team,
assignee, description, notes, priority or other guests' tasks. When there are none:
`The guest has no open requests.`

## CreateGuestServiceRequestTool (changed)

**Input** (changes only):

- `kind`: `service_request`, **new** `maintenance_request`, or `booking_follow_up`.
- `task_category_id`: used for `service_request` only. A maintenance request ignores it.
- **New** `add_to_request_id` (optional): an open request of the same kind to add this
  detail to (R14).

**Behavior**:

1. Check for an active reservation. A `booking_follow_up` is exempt: it is a positive
   signal, not a service.
2. If `add_to_request_id` names the guest's own open request of the same kind, append
   the detail, block pitching (service and maintenance), and return
   `Added to your existing request (task id: …). Staff will see the update.`
3. `service_request`: category validated against the hotel, team from the category,
   one open clean per room, pitching blocked.
4. `maintenance_request` (R3):
   - **Room**: when the guest is in several rooms and gave no number, nothing is filed
     and the tool returns
     `The guest is in rooms 214 and 215. Ask which room before filing.`
   - **Routing**: the category is `hotel.maintenance_task_category_id` and the team is
     `hotel.maintenance_team_id`, whatever category the model named.
   - **Fields**: `guest_signal = maintenance_request`, `created_by = guest`, linked to
     the stay and room, or to the reservation before arrival. Priority is high for VIP
     guests.
   - **Side effects**: never changes room status, and blocks pitching.
   - **Result**:
     `Maintenance request filed for room {n} (task id: …). The maintenance team has it.`

## RequestRoomChangeTool (new)

**Input**:

| Field | Type | Required | Notes |
| --- | --- | --- | --- |
| `reason` | string | yes | Why the guest wants to move |
| `preference` | string | no | For example "higher floor", "quieter side" |
| `room_number` | string | no | Which of their rooms, when in several |

**Behavior**:

- Requires an active reservation.
- Calls `GuestRequestService::requestRoomChange()` (R4): no team, linked to the stay or
  to the reservation before arrival, one open request per stay or reservation.
- Never touches room assignment.
- A new request emails the admins.

**Results**:

- `Room-change request passed to staff (task id: …). Tell the guest staff will decide and contact them; do not promise a move.`
- `A room-change request is already with staff (task id: …).`

## EscalateToHumanTool (changed)

**Input**: `reason` (string, required). Unchanged.

**Behavior**: calls `GuestRequestService::escalate()` (R5). Needs no active reservation.
Always blocks pitching in this reply.

**Results**:

- `A staff member has been notified and will follow up with the guest directly.`
- When one is already open: `Staff already have this guest's request for a person; the new detail was added to it.`

---

## Unknown senders

No tools run. The message is recorded as `ignored` and never reaches the agent (R12).
