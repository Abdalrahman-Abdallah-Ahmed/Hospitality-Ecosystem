# Frontend changes: Reservation Rooms (SPEC-010)

**For:** `ecosystem-frontend` (Next.js). **Backend branch:** `002-reservation-rooms` (Hospitality Ecosystem API).
**Status:** backend done, all tests green. **Breaking.** The frontend must ship in the same release; there is no compatibility shim.

Full API reference: `docs/reservations-api-documentation.md` (sections *The Reservation Object*, *Create*, *Changing the Rooms*).

---

## 1. What changed, in one paragraph

A reservation no longer points at one room. It books one or more **room units ("lines")**. Each line names a **room type** (required) and optionally a **physical room**. "2 × Deluxe + 1 × Suite" is one reservation with 3 lines. All lines share the reservation's arrival and departure dates. The top-level `room_id` / `room` fields are **gone** from requests and responses.

---

## 2. Response shape (every reservation endpoint and the dashboard)

Removed: `room_id`, `room`.
Added:

```ts
type ReservationRoomLine = {
  id: string;                      // line id: send it back on update to keep the line
  reservation_id: string;
  room_type_id: string;
  room_id: string | null;          // null = unassigned
  status: 'reserved' | 'cancelled';
  cancelled_with_reservation: boolean; // true = cancelled with its reservation; un-cancel restores it
  room_type: RoomType;             // always loaded
  room: Room | null;               // always loaded, null when unassigned
  created_at: string;
  updated_at: string;
};

type RoomSummaryItem = {
  room_type_id: string;
  room_type_name: string | null;
  quantity: number;                // live (non-cancelled) lines of this type
};

type Reservation = {
  // ...all existing fields except room_id / room
  rooms: ReservationRoomLine[];    // every line, cancelled ones included (history), creation order
  room_summary: RoomSummaryItem[]; // e.g. [{ room_type_name: 'Deluxe', quantity: 2 }]
};
```

**Display rules**
- The list row's room column shows `room_summary` ("2 × Deluxe, 1 × Suite"), and optionally the assigned room numbers from the live lines.
- Detail view: one row per **live** line with type, room number or "Unassigned", and status. Hide cancelled lines by default, with an optional "show cancelled" toggle.
- Dashboard `today_arrivals` / `today_departures`: read rooms from `rooms[]` / `room_summary`. Stop reading `room.room_type`.

---

## 3. Create reservation form: `POST /api/reservation`

Replace the single room picker with a **room-type lines editor**.

```json
{
  "hotel_id": "…",
  "guest_id": "…",
  "reservation_id": "RES-1001",
  "arrival_date": "2026-10-01",
  "departure_date": "2026-10-04",
  "status": "confirmed",
  "adults": 4,
  "children": 1,
  "rooms": [
    { "room_type_id": "<deluxe>", "quantity": 2 },
    { "room_type_id": "<suite>", "room_id": "<room 501>" }
  ],
  "capacity_override": false
}
```

**UI**
- A repeater of lines: **Room type** (select, required) × **Quantity** (number 1–50, default 1), plus an optional **Room** picker.
- Show the Room picker only when quantity is 1, and list only rooms of the selected type. The API rejects a room on a line with quantity > 1.
- Room type options: the hotel's **active** room types only (`GET /api/room-types?filter[is_active]=1`). Inactive or deleted types are rejected.
- Don't let the same room be picked on two lines.
- At least 1 line. Total quantity across lines must be ≤ 50; enforce it client-side too.
- Never send `room_id` at the top level. It returns `422 "Use rooms[] instead."`

**Capacity check (new)**
- The server rejects a party larger than the booked rooms: `adults + children` > Σ `max_occupancy`, or `adults` > Σ `adult_capacity` of the chosen types. Nice to have: compute this client-side from the room types and warn inline.
- On that `422` (key `rooms`, message starts "The party (…) is larger than the booked rooms hold…"), show the message plus a **"Save anyway"** action that resubmits with `capacity_override: true`. The override is audited server-side. Only show it to staff; the AI never overrides.

---

## 4. Edit reservation: `PUT /api/reservation/{id}`

`rooms` is **optional**:
- **Leave it out** when the user didn't touch the rooms. The lines stay as they are.
- **Send it** as the **full list of live lines the reservation should have afterwards**:

```json
{
  "rooms": [
    { "id": "<line A>" },                              // keep, unchanged
    { "id": "<line B>", "room_id": "<room 105>" },     // keep, set/change room
    { "id": "<line C>", "room_id": null },             // keep, clear room
    { "room_type_id": "<suite>", "quantity": 1 }       // new line(s)
  ]
}
```

- A line **left out** of the list is **cancelled** (kept in `rooms` with `status: 'cancelled'`).
- A kept line's **type can't change**. To change it, remove the line and add a new one. Sending a different `room_type_id` on a kept line returns a `422`.
- For `room_id` on a kept line, **omit the key** to keep the current room, send `null` to clear it, or send an id to set it.
- The capacity check re-runs when lines, `adults` or `children` change. Handle it the same way as on create (`capacity_override`).

**What the edit form allows, by the reservation's current status**

| Status | Add line | Remove line | Set / change / clear room |
| --- | --- | --- | --- |
| `pending`, `confirmed` | ✅ | ✅ (not the last one) | ✅ (room of the line's type) |
| `checked_in` | ❌ | ❌ | **Move room only**: change to another room of the same type; can't clear |
| `checked_out`, `cancelled` | ❌ | ❌ | ❌ (read-only) |

- **Checked-in:** replace the lines editor with a **"Move room"** action per line: a room picker filtered to the line's type. It sends `rooms` with every live line's `id` and the new `room_id` on the moved line. The backend frees the old room and marks the new one occupied.
- Removing the last line isn't allowed. Offer "Cancel reservation" instead (`status: 'cancelled'` cancels every line).
- **Un-cancelling** (added after the first handoff): changing a `cancelled` reservation back to another status restores the lines it had when it was cancelled, so a plain `{ status: 'confirmed' }` is enough. If the UI lets the user pick rooms while un-cancelling, send `rooms` in the same request: `{ id }` of any of the reservation's lines reinstates it, items without `id` add lines, and omitted lines stay cancelled. Handle the capacity 422 the same way as on create.

---

## 5. Reservation list filters: `GET /api/reservation`

- New: `filter[room_type_id]=<uuid>` and `filter[room_id]=<uuid>`. Each matches reservations with **any live line** of that type or room. Add "Room type" and "Room" filter selects. Both also accept a list (`filter[room_type_id][]=a&filter[room_type_id][]=b`, matches either), and an empty value is ignored.
- They **can't be used for `sort`**. There's no "sort by room" any more; remove it if present.
- `search` no longer matches room ids.

---

## 6. Room types screen: `DELETE /api/room-types/{id}`

New rejection when current or upcoming reservations still book the type:

```json
{ "message": "Cannot delete room type: 3 current or upcoming reservations still use it. Deactivate it instead.",
  "code": 422,
  "body": { "error": "deletion_blocked_by_reservations", "reservations": 3 } }
```

Show the message and offer **Deactivate** (`is_active: false`). The existing `deletion_blocked_by_rooms` case is unchanged.

---

## 7. Error handling cheat-sheet (standard `errors` shape, keyed by line index)

| Key | Meaning |
| --- | --- |
| `rooms` | No lines, more than 50 rooms, over capacity, removing the last line, or a change not allowed for the status |
| `rooms.{i}.room_type_id` | Type inactive, deleted, or not this hotel's; or a kept line's type changed |
| `rooms.{i}.room_id` | Room missing, of another hotel, of another type, on a quantity > 1 line, duplicated, or cleared while checked in |
| `rooms.{i}.id` | Line id unknown or already cancelled (stale form: refetch) |
| `room_id` | Old field sent. Remove it |

Map `rooms.{i}.*` errors onto line `i` of the repeater, using the index you sent.

---

## 8. Nothing else changes

Auth, headers, permissions (`reservations.*`), pagination, status values, the import endpoint's request, and the `{ message, code, body }` envelope are unchanged. Each imported row now produces one line; the import response is the same.

---

## 9. Checklist

- [ ] Types: drop `room_id` / `room` from `Reservation`; add `rooms`, `room_summary`, `ReservationRoomLine`
- [ ] List: room column from `room_summary`; "Room type" / "Room" filters; remove sort-by-room
- [ ] Detail: lines table (type, room or Unassigned, status)
- [ ] Create form: lines repeater (type × quantity, optional room when quantity is 1, filtered by type), active types only, ≤ 50 rooms
- [ ] Capacity `422` → "Save anyway" with `capacity_override: true`
- [ ] Edit form: send `rooms` only if changed, as the full live list with `id`s; enforce the status table
- [ ] Checked-in: "Move room" per line (same-type rooms only)
- [ ] Dashboard arrivals/departures: read `rooms[]`
- [ ] Room types: handle `deletion_blocked_by_reservations` → offer Deactivate
- [ ] Map `rooms.{i}.*` validation errors to the right line
