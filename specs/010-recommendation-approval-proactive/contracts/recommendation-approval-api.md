# Contract: Recommendation Approval API

All routes sit in the tenant group of `routes/api.php`
(`api.key → auth:sanctum → throttle:api → tenant`). Every response is
`{ message, code, body }` via `apiResponse()`. Bodies use `RecommendationResource`.

## Resource changes (`RecommendationResource`)

Added fields:

```json
{
  "status": "pending_approval",            // + pending_approval | approved | rejected_by_admin; "pending" no longer appears
  "source": "staff_request",               // staff_request | conversation | legacy
  "reviewed_by": { "id": "uuid", "name": "Mona Ali" },   // null until decided
  "reviewed_at": "2026-10-10T09:12:00+03:00",
  "review_reason": "Pool closed for maintenance",
  "offerable": true                        // approved, not yet offered, activity active
}
```

## POST `/api/recommendation/{recommendation}/approve`

Permission: `recommendations.approve` (+ same hotel). Body: none.

| Case | Code | message |
| --- | --- | --- |
| Approved | 200 | `Recommendation approved successfully.` |
| Already decided / offered / final | 422 | `This recommendation can no longer be approved (status: {status}).` |
| No permission | 403 | standard |
| Other hotel | 403 | standard (the repo's convention for bound records of another hotel) |

## POST `/api/recommendation/{recommendation}/reject`

Permission: `recommendations.approve`. Body:

```json
{ "reason": "Pool closed for maintenance" }   // optional, string ≤ 500
```

Allowed from `pending_approval` and from `approved` that was never offered. Same error
table as approve (`… can no longer be rejected …`).

## POST `/api/recommendations/decide` (bulk)

Permission: `recommendations.approve`. Body:

```json
{
  "action": "approve",                 // approve | reject
  "ids": ["uuid", "uuid"],             // 1–100, distinct
  "reason": "Optional, reject only"    // ≤ 500
}
```

200 response body (each id decided and audited on its own):

```json
{
  "decided": ["uuid-1", "uuid-2"],
  "skipped": [
    { "id": "uuid-3", "reason": "already_decided" },
    { "id": "uuid-4", "reason": "not_found" }
  ]
}
```

`not_found` covers ids of another hotel (no existence leak). 422 only for an invalid body.

## GET `/api/recommendation` (index, extended)

Existing `GenericIndexRequest` filters, plus:

| Param | Meaning |
| --- | --- |
| `status` | any status value, comma-separated allowed |
| `source` | `staff_request` \| `conversation` \| `legacy` |
| `reservation_id`, `activity_id` | existing columns |
| `guest_id` | the reservation's primary guest |
| `arrival_from`, `arrival_to` | reservation arrival date range (Y-m-d) |
| `sort` | adds `arrival_date`, `priority`, `predicted_confidence` (`-` for desc) |

## PUT `/api/recommendation/{recommendation}` (changed behaviour)

- `status` and `reviewed_*` are still ignored.
- Changing `activity_id`, `reason` or `reservation_id`:
  - on an `approved`, never-offered recommendation → saved; status becomes
    `pending_approval`; review fields cleared;
  - on an offered or final recommendation → **422**
    `An offered or closed recommendation cannot change its activity, reason or reservation.`
- Changing only `priority` / `predicted_confidence` never changes approval.

## Permissions endpoint

`GET /api/permissions` lists `recommendations.approve` in the recommendations group
automatically (it reads the enum).
