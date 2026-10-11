# Contract: Usage reports (hotel-facing and super-admin)

These are changes to existing endpoints. Every existing field keeps its name and meaning
(additive only). The target documentation is `docs/usage-metering-api-documentation.md`.

## `GET /api/usage?from=YYYY-MM-DD&to=YYYY-MM-DD` (hotel-facing)

Access is unchanged:
- open to an account **admin** or a `group_role` holder;
- an employee gets `403`, and so does a user with no account;
- the account comes from the token, and there is no account parameter.

### Added: `ai` block

```json
{
  "message": "Usage fetched successfully.",
  "code": 200,
  "body": {
    "from": "2026-10-01",
    "to": "2026-10-31",
    "hotel_group_id": "…",
    "account": "Grand Harbor Hotel",
    "seats": { "…": "unchanged" },
    "features": {
      "ai_messages": { "code": "ai_messages", "label": "AI messages", "category": "cost_driver", "used": 120, "unit": "messages", "measured": true, "measured_since": null },
      "guest_memory_extractions": {
        "code": "guest_memory_extractions", "label": "Guest memory extractions", "category": "cost_driver",
        "used": 14, "unit": "extractions", "measured": true, "measured_since": "2026-10-12",
        "note": "Measured from 2026-10-12; earlier activity was not counted."
      }
    },
    "recommendations": { "…": "unchanged" },
    "ai": {
      "requests": 312,
      "input_tokens": 401230,
      "output_tokens": 48770,
      "total_tokens": 450000,
      "by_capability": {
        "concierge":       { "label": "Concierge",          "requests": 190, "input_tokens": 280000, "output_tokens": 31000, "total_tokens": 311000 },
        "admin_advisor":   { "label": "Admin AI",           "requests": 40,  "input_tokens": 60000,  "output_tokens": 9000,  "total_tokens": 69000 },
        "recommendation":  { "label": "Recommendations",    "requests": 12,  "input_tokens": 20000,  "output_tokens": 4000,  "total_tokens": 24000 },
        "insights":        { "label": "Insights",           "requests": 6,   "input_tokens": 15000,  "output_tokens": 2000,  "total_tokens": 17000 },
        "document_vision": { "label": "Document reading",   "requests": 4,   "input_tokens": 9000,   "output_tokens": 1500,  "total_tokens": 10500 },
        "guest_memory":    { "label": "Guest memories",     "requests": 14,  "input_tokens": 14000,  "output_tokens": 1270,  "total_tokens": 15270 },
        "knowledge":       { "label": "Knowledge indexing", "requests": 46,  "input_tokens": 3230,   "output_tokens": 0,     "total_tokens": 3230 }
      },
      "by_hotel": {
        "019f…": { "hotel": "Grand Harbor Hotel", "requests": 312, "input_tokens": 401230, "output_tokens": 48770, "total_tokens": 450000 }
      },
      "basis": "Counted from every AI provider call made for this account. Calls made inside a Concierge turn (classification, knowledge search) are counted under Concierge."
    },
    "not_measured": { "conversations_handled": "…" }
  }
}
```

Rules (each one is tested):
1. The values of `by_capability` sum exactly to the top-level `ai` figures, and so do the
   values of `by_hotel` (FR-011).
2. `by_capability` always lists every reportable capability, with zero-filled entries. An
   `unattributed` key appears only when non-zero. Turn classification has no row of its own:
   it always runs inside a Concierge turn and is counted there (see `basis`). `by_hotel`
   keeps a row for a hotel deleted or moved since, for the calls it made (its `hotel`
   name may be `null` if the record is gone), and lists
   calls logged with no hotel under `unassigned`, only when there are any.
3. `measured_since` is present on every feature entry and null unless the feature was added
   after metering began. `note` appears only when `from` is before `measured_since`.
4. The body never contains any of these keys:
   - `cost`, `cost_usd`, `cost_eur`, `cost_per_call_eur`;
   - `*price_per_million`;
   - `margin`, `gross_margin`, `gross_margin_pct`;
   - `contract_value`, `contract_value_monthly`;
   - `model`, `provider`.

   It never contains any model or provider name as a value either (FR-012, Clarification
   Q1). The plain key `price` is **not** banned, because activity and booking prices are
   legitimate hotel data elsewhere in the API.
5. A super admin calling this route gets the same cost-free shape.

## `GET /api/admin/usage` (super admin)

Each entry in `accounts[]` gains the same `ai` block and the `measured_since`/`note`
fields. There is still no cost here; cost lives in `/api/admin/ai-cost`.

## Admin AI `GetReportTool` → `usage`

The returned JSON gains the same `ai` block (R2, R5). It still has no cost, model or
provider.
