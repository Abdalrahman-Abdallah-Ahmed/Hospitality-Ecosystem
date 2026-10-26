# Frontend changes: Hardening and production readiness (2026-10-25)

For the `ecosystem-frontend` team. Backend spec:
`specs/017-hardening-production-readiness`.

## 1. Another hotel's record is now "not found" (breaking)

Opening, changing or deleting a record that belongs to another hotel used to
answer `403 Forbidden`. It now answers **`404 Not Found`**, exactly like a
record that does not exist. This hides whether the record exists at all.

Where you handle `403` on a single record (detail pages, edit forms, delete
buttons, actions such as check-in or cancel), handle `404` the same way:

- show the "not found" state, not "you are not allowed";
- do not retry.

`403` still means the user's role lacks the permission, for lists and for
their own hotel's records. Keep that handling as it is.

## 2. Dashboard figures may be up to 60 seconds old

`GET /api/dashboard` is now reused for 60 seconds per hotel and day. Polling it
more often than once a minute returns the same figures. If the dashboard shows
a "last updated" time, it can say "within the last minute". Nothing else
changes in the response.

## 3. Error, empty and loading states on every screen (FR-034)

Before go-live, every screen needs all three states. No screen may go blank or
crash when the API is slow, returns an error or returns an empty list:

| Screen | Error state | Empty state | Loading state |
| --- | --- | --- | --- |
| Dashboard | | | |
| Hotels | | | |
| Rooms and room types | | | |
| Housekeeping board | | | |
| Guests | | | |
| Reservations and imports | | | |
| Stays (arrivals, departures, in house) | | | |
| Activities and bookings | | | |
| Tasks, teams, task categories | | | |
| Staff roles and users | | | |
| Knowledge base and documents | | | |
| Conversations and guest memories | | | |
| Recommendations | | | |
| AI insights, AI activity, usage | | | |

Tick each cell when done. Also:

- `next build` must finish with no errors and no type errors.
- A `5xx` or network failure shows a retry option, not a stack trace.
- A `422` shows the field messages from the top-level `errors` object. Validation errors
  use Laravel's own `{ "message": "...", "errors": { "field": ["..."] } }` shape, not the
  `{ message, code, body }` envelope.

## Not changed

- List responses: every list keeps its page sizes and `meta`/`links`. Nothing
  became paginated that was not.
- The new `GET /api/health` is for monitoring only; the frontend does not call
  it.
