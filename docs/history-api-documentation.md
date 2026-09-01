# Record History (Audit Trail) API Documentation

Every create, update, and delete of an audited record is written to an
append-only `event_log`. This endpoint reads the history of one record — who
changed it, when, and from what.

## Base URL

`GET /api/history/{type}/{id}`

`{type}` is one of: `reservation`, `transaction`, `stay`, `guest`,
`recommendation`, `task`, `ai-insights`. Any other value returns `404`.

## Required Headers

```http
X-API-KEY: {your_api_key}
Authorization: Bearer {login_token}
Accept: application/json
```

## Who Can Call This

`App\Policies\EventLogPolicy`: the caller's `role` must be `admin` (super admin
bypasses). The events returned are automatically scoped to the caller's own
hotel(s) — asking for a record that belongs to another hotel returns `404`, the
same as an id that doesn't exist. A soft-deleted record still has a readable
history (that is the point of the log).

## Query Parameters

| Param | Default | Notes |
| --- | --- | --- |
| `per_page` | 15 | Page size. |
| `page` | 1 | 1-indexed. |

Results are ordered newest first (`occurred_at` descending).

## The Event Object

```json
{
  "id": "01a0f2c1-...",
  "hotel_id": "01a00c93-...",
  "event_type": "reservation.updated",
  "subject_type": "App\\Models\\Reservation",
  "subject_id": "01a01133-...",
  "actor_type": "App\\Models\\User",
  "actor_id": "01a00abc-...",
  "actor_kind": "user",
  "changes": {
    "reservation_value": { "from": "100.00", "to": "250.00" },
    "status": { "from": "pending", "to": "confirmed" }
  },
  "context": { "ip": "203.0.113.4", "route": "api/reservation/01a01133-..." },
  "evidence_level": "L1",
  "reason": null,
  "occurred_at": "2026-09-02T09:14:22.000000Z",
  "actor": { "id": "01a00abc-...", "name": "Maha Fathy", "...": "..." }
}
```

Field notes:

- `event_type` is `{record}.{verb}`. Verbs are `created`, `updated`, `deleted`,
  plus named ones: `transaction.reversed`, and stay lifecycle
  (`stay.checked_in`, `stay.checked_out`, `stay.no_show`, `stay.cancelled`,
  `stay.expected`). Bulk imports collapse to a single
  `hotel.transactions_imported` / `hotel.reservations_imported` event with the
  import summary in `changes` — the individual rows are **not** logged one by
  one.
- `actor_kind` is one of `user` (a human, `actor` is populated), `system` (a
  scheduled/internal process), `ai_agent` (written by an LLM agent or its
  tools — `actor` is null), `import`.
- `changes` is `{ field: { from, to } }` for updates, `{ field: { to } }` for
  creates, and absent for deletes. Only an allow-listed set of business columns
  per record type appears here — derived fields (hashes) and secrets never do.
  Enum and date values are normalised to strings.
- `context` carries the request ip and route when the change came from an HTTP
  request; `{ "queue": true }` when it came from a queued job.
- `evidence_level` on the event itself is `L1` — a record change is a directly
  observed fact. (This is separate from the `evidence_level` on the AI
  insight/recommendation *content*.)
- `reason` is set for events that carry one (e.g. a transaction reversal).

## Example

```bash
curl "https://your-domain.com/api/history/transaction/01a01133-2cfc-717b-bbb3-a61663b8b201?per_page=50" \
  -H "Accept: application/json" \
  -H "X-API-KEY: YOUR_API_KEY" \
  -H "Authorization: Bearer USER_LOGIN_TOKEN"
```

`body` is a Laravel paginator; read rows from `body.data`.

## Related Docs

- `docs/transaction-api-documentation.md` — the ledger whose reversals show up here.
- `docs/ai-insights-api-documentation.md` / `docs/reservation-recommendations-api-documentation.md` — the `evidence_level` on AI content.
