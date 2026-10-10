# Frontend changes: Recommendation Approval and Proactive Concierge (Phase 10)

For `ecosystem-frontend`. Backend branch: `010-recommendation-approval-proactive`.
Covers SPEC-071 (approval), SPEC-073 (proactive WhatsApp) and SPEC-074 (decline retry).

Full API references (backend repo, `D:\Hospitality Ecosystem\docs\`):

- `reservation-recommendations-api-documentation.md` — section **6. Approval**
- `proactive-messages-api-documentation.md`
- `hotel-api-documentation.md` — `proactive_settings`
- `guest-api-documentation.md` — section **4a. Contact Preference**
- `staff-roles-api-documentation.md` — new permission
- `latest-changes-2026-10-10.md` — summary and breaking changes

All responses use `{ message, code, body }`. Headers as usual: `X-API-KEY`, `Authorization: Bearer <token>`.

---

## 1. What changed in the backend

- Every AI-generated recommendation now starts **`pending_approval`**. Nothing reaches a guest
  until someone with the new **`recommendations.approve`** permission approves it.
- The WhatsApp Concierge only offers **approved** recommendations.
- Hotels can switch on **proactive messages**: the Concierge messages in-house guests first
  (morning after check-in, mid-stay, booking reminder, newly approved recommendation). It is off by default.
- Guests can **opt out** of proactive messages and unprompted offers by replying STOP. Staff
  can record the choice too.

Five UI pieces are needed:

| # | Screen | Who sees it |
| --- | --- | --- |
| 1 | Approval queue (new) | Admins; employees with `recommendations.approve` |
| 2 | Recommendation status labels and filters (update) | Everyone who sees recommendations |
| 3 | Proactive messaging settings (hotel settings, update) | Admins only |
| 4 | Proactive message log (new) | Admins only |
| 5 | Guest opt-out badge and toggle (guest page, update) | Employees with `guests.update` for the toggle |

---

## 2. Breaking changes to handle first

1. **Status values.** `pending` no longer exists. New values: `pending_approval`,
   `approved`, `rejected_by_admin`. Full list:
   `pending_approval`, `approved`, `rejected_by_admin`, `sent`, `accepted`, `rejected`,
   `purchased`, `ignored`, `expired`, `cancelled`.
   Anything filtering or labelling `pending` must switch.
2. **Editing a recommendation** (`PUT /api/recommendation/{id}`):
   - changing `activity_id`, `reason` or `reservation_id` on an **approved** one sends it back
     to `pending_approval` (show the returned status; no error);
   - changing those fields on an offered or closed one returns **422**
     `An offered or closed recommendation cannot change its activity, reason or reservation.`
     Disable those fields when `status` is `sent`, `accepted`, `rejected`, `purchased`,
     `ignored`, `expired`, `cancelled` or `rejected_by_admin`, or when `delivered_at` is set.

---

## 3. Recommendation object — new fields

```json
{
  "id": "uuid",
  "status": "pending_approval",
  "source": "staff_request",
  "reviewed_by": { "id": "uuid", "name": "Mona Ali" },
  "reviewed_at": "2026-10-10T09:12:00.000000Z",
  "review_reason": "Pool closed for maintenance",
  "offerable": true,
  "reason": "...",
  "predicted_confidence": "0.80",
  "priority": 1,
  "reservation": { "reservation_id": "RES-…", "arrival_date": "…", "guest": { "first_name": "…", "last_name": "…" } },
  "activity": { "name": "Snorkeling", "...": "..." }
}
```

| Field | Meaning | UI |
| --- | --- | --- |
| `source` | `staff_request` (Generate button), `conversation` (made during a guest chat), `legacy` | Small tag: "Requested by staff" / "From guest chat" / "Earlier" |
| `reviewed_by` | `{id, name}` or `null` | "Approved by Mona Ali" / "Rejected by …" |
| `reviewed_at` | When decided | Show next to the reviewer |
| `review_reason` | Optional rejection reason | Show under rejected items |
| `offerable` | Approved and not yet offered | "Ready to offer" badge |

All read-only. `predicted_confidence` is a decimal **string**.

---

## 4. Screen 1 — Approval queue (new)

**Route suggestion:** `/recommendations/approval` (or a tab on the recommendations page).
**Show the nav item when:** the user holds `recommendations.approve` (from `GET /api/user` → `body.permissions`).

### Load

```
GET /api/recommendation?status=pending_approval&sort=arrival_date&per_page=25
```

Filters to offer:

| Param | UI control |
| --- | --- |
| `status` (comma list) | Tabs: Pending (`pending_approval`), Approved (`approved`), Rejected (`rejected_by_admin`), All |
| `arrival_from`, `arrival_to` (`Y-m-d`) | Arrival date range ("next 3 days" quick filter) |
| `guest_id` | Guest picker |
| `activity_id` | Activity picker |
| `source` | `staff_request` / `conversation` / `legacy` |
| `sort` | `arrival_date`, `-arrival_date`, `priority`, `-predicted_confidence` |

Unknown `status` → 422.

### Row content

Guest name, reservation code, arrival date, activity name, reason, confidence (as %), source tag.

### Actions

**Approve one**

```
POST /api/recommendation/{id}/approve
```

200 → updated recommendation (`status: approved`). Remove it from the Pending tab.

**Reject one** (open a small dialog with an optional reason, max 500 characters)

```
POST /api/recommendation/{id}/reject
{ "reason": "Pool closed for maintenance" }
```

`reason` is optional. A reject is also allowed on an `approved` recommendation that was
never offered (`offerable: true`): label it "Withdraw approval".

Errors for both:

| Code | When | Show |
| --- | --- | --- |
| 422 | Already decided / already offered / closed. `message`: `This recommendation can no longer be approved (status: approved).` | Toast with the message, then refresh the row |
| 403 | No permission, or another hotel's record | Standard "not allowed" |

**Bulk** (checkbox per row + "Approve selected" / "Reject selected")

```
POST /api/recommendations/decide
{ "action": "approve", "ids": ["uuid-1", "uuid-2"] }
{ "action": "reject",  "ids": ["uuid-1"], "reason": "Season over" }
```

- `ids`: 1–100 distinct ids. Cap the selection at 100 in the UI.
- `reason` only with `reject` (sending it with `approve` → 422).
- 200 body:

```json
{
  "decided": ["uuid-1"],
  "skipped": [{ "id": "uuid-2", "reason": "already_decided" }, { "id": "uuid-3", "reason": "not_found" }]
}
```

Show "Approved 8 · 2 skipped (already decided)". Refresh the list after.

### Empty state

"No recommendations waiting for approval." Optionally link to a reservation's "Generate
recommendations" action (`POST /api/reservation/{id}/recommendations`, unchanged; new
items arrive as `pending_approval`).

---

## 5. Screen 2 — Recommendation status labels and filters (update)

Wherever recommendations are listed (reservation detail, recommendations page, analytics
drill-downs):

| Status | Label | Colour suggestion |
| --- | --- | --- |
| `pending_approval` | Awaiting approval | amber |
| `approved` | Approved | blue |
| `rejected_by_admin` | Rejected by staff | grey |
| `sent` | Offered | indigo |
| `accepted` | Accepted | green |
| `rejected` | Declined by guest | red |
| `purchased` | Purchased | green |
| `ignored` | No response | grey |
| `expired` | Expired | grey |
| `cancelled` | Cancelled | grey |

- Add the status filter (`status=` comma list) where there is a recommendation list.
- On the reservation detail, show approve/reject buttons on `pending_approval` items when
  the user holds `recommendations.approve`.
- Remove any "mark as accepted" button that used `PUT` with `status` (it never worked; the
  field is ignored).

---

## 6. Screen 3 — Proactive messaging settings (hotel settings, update)

**Admins only** (no permission grants it to employees). Part of `PUT /api/hotel/{id}`.

### Read

`GET /api/hotel/{id}` → `body.proactive_settings` (always complete, defaults filled):

```json
{
  "enabled": false,
  "triggers": { "first_morning": true, "mid_stay": true, "upcoming_activity": true, "recommendation_approved": true },
  "quiet_hours": { "start": "21:00", "end": "09:00" },
  "daily_cap": 1,
  "milestone_time": "10:00",
  "reminder": { "morning_cutoff": "12:00", "evening_before_at": "18:00", "hours_before": 4 }
}
```

### Form

| Field | Control | Rules | Help text |
| --- | --- | --- | --- |
| `enabled` | Switch | boolean | "Let the WhatsApp Concierge message guests first." Off by default. |
| `triggers.first_morning` | Checkbox | boolean | "The morning after check-in, with an approved suggestion." |
| `triggers.mid_stay` | Checkbox | boolean | "The middle of stays of 4 nights or more." |
| `triggers.upcoming_activity` | Checkbox | boolean | "A reminder of a booked activity." |
| `triggers.recommendation_approved` | Checkbox | boolean | "As soon as a suggestion is approved, if the guest hasn't had one yet." |
| `quiet_hours.start` / `.end` | Time pickers | `H:i` | "No messages between these times (hotel time)." The window may cross midnight. |
| `daily_cap` | Number | 1–3 | "Most messages a guest gets per day." |
| `milestone_time` | Time | `H:i` | "When morning messages are due." |
| `reminder.morning_cutoff` | Time | `H:i` | "Activities before this time are reminded the evening before…" |
| `reminder.evening_before_at` | Time | `H:i` | "…at this time." |
| `reminder.hours_before` | Number | 1–24 | "Later activities are reminded this many hours ahead." |

Disable the trigger and timing fields while `enabled` is off.

Show this note under the switch: "Messages are only sent to guests who wrote to the hotel in the last 24 hours (a WhatsApp rule). Guests can reply STOP at any time."

### Save

Send **only what changed**. It is merged server-side:

```
PUT /api/hotel/{id}
{ "proactive_settings": { "enabled": true, "quiet_hours": { "start": "22:30" } } }
```

200 → hotel object with the full merged `proactive_settings`.
422 → `message` holds the first error, e.g. `Unknown proactive setting [send_at_midnight].` or a time/number range error.
403 for employees.

---

## 7. Screen 4 — Proactive message log (new)

**Admins only.** Route suggestion: `/proactive-messages` (under hotel settings or reports).
Hide the nav item for non-admins; the endpoint returns 403 to employees whatever their role.

### Load

```
GET /api/proactive-messages?per_page=25
```

Default sort is newest due first (`-due_at`). Uses the generic filter syntax:

| Param | Control |
| --- | --- |
| `filter[trigger]` | `first_morning`, `mid_stay`, `upcoming_activity`, `recommendation_approved` |
| `filter[status]` | `scheduled`, `sending`, `sent`, `skipped`, `failed` |
| `filter[reason]` | See the reason table below |
| `filter[guest_id]` | Guest picker |
| `sent_from`, `sent_to` | Date range (`Y-m-d`, hotel-local) |
| `sort` | e.g. `-sent_at` |

`GET /api/proactive-messages/{id}` → one row (detail drawer).

### Row

```json
{
  "id": "uuid",
  "trigger": "upcoming_activity",
  "status": "skipped",
  "reason": "outside_window",
  "guest": { "id": "uuid", "name": "Sara Khan" },
  "reservation_id": "uuid",
  "booking_id": "uuid",
  "recommendation_id": null,
  "due_at": "…", "valid_until": "…", "sent_at": null,
  "locale": null, "body": null, "attempts": 0
}
```

Columns: Guest · Trigger · Status · Reason · Due · Sent · Message (`body`, expandable). Show times in the hotel's timezone.

Trigger labels: First morning · Mid-stay · Activity reminder · Approved suggestion.

Status labels: Waiting (`scheduled`), Sending, Sent, Skipped, Failed.

Reason labels (`reason` is set for skipped rows, and for waiting rows it says why they wait):

| `reason` | Label |
| --- | --- |
| `outside_window` | Guest hasn't written in 24 h |
| `opted_out` | Guest opted out |
| `quiet_hours` | Quiet hours |
| `daily_cap` | Daily limit reached |
| `guest_active` | Guest is mid-conversation |
| `already_pitched` | Guest already had a suggestion |
| `pitch_cap` | Suggestion limit for the stay reached |
| `retry_used` / `retry_window_closed` | Guest declined; no more suggestions |
| `no_candidate` / `recommendation_not_offerable` | Nothing approved to offer |
| `departing` | Departing today |
| `escalated` | Guest was escalated to staff |
| `open_complaint` | Guest has an open request |
| `not_in_house` | Guest not in house |
| `booking_not_confirmed` | Booking no longer confirmed |
| `no_whatsapp` | No WhatsApp number |
| `missing_data` | Missing activity details |
| `pitching_disabled` / `disabled` / `trigger_disabled` | Switched off |
| `expired` | Too late to send |
| `send_failed` | WhatsApp send failed |

Read-only: no create, edit or delete actions.

---

## 8. Screen 5 — Guest opt-out (guest page, update)

### Guest object — new read-only fields

```json
{
  "proactive_opted_out": true,
  "proactive_opted_out_at": "2026-10-10T11:02:00.000000Z",
  "proactive_opt_out_source": "guest_message"
}
```

- When `proactive_opted_out` is `true`, show a badge: "No offers" with a tooltip "Asked on
  WhatsApp on {date}" (`guest_message`) or "Recorded by staff on {date}" (`staff`).
- These fields cannot be set through `PUT /api/guest/{id}`.

### Toggle (users with `guests.update`)

A switch "Send this guest offers and proactive messages", for recording what the guest asked at the desk:

```
PUT /api/guest/{id}/contact-preference
{ "proactive_opted_out": true }
```

200 → guest object. Sending the current state is a no-op (still 200). 403 without
`guests.update`. Guests are always answered when they write, whatever this setting.

---

## 9. Permissions

- New: **`recommendations.approve`**. It appears automatically in `GET /api/permissions`, so the role editor needs no code change. Make sure its label reads well, e.g. "Approve recommendations".
- `guests.update` now also covers the contact-preference toggle.
- Proactive settings and the proactive log are admin-only (no permission).

---

## 10. Checklist

- [ ] Replace every `pending` recommendation status with the new statuses and labels (§2, §5)
- [ ] Disable content fields on offered or closed recommendations; handle the 422 (§2)
- [ ] Show `source`, `reviewed_by`, `review_reason`, `offerable` (§3)
- [ ] Approval queue: tabs, filters, sort, approve, reject with reason, bulk (≤ 100) (§4)
- [ ] Approve/reject on the reservation detail for permitted users (§5)
- [ ] Proactive settings form, partial save, 422 messages (§6)
- [ ] Proactive message log with filters and reason labels (§7)
- [ ] Guest opt-out badge and toggle (§8)
- [ ] Nav visibility: approval queue by `recommendations.approve`; settings and log admins only (§9)
