# Contract: Super-admin AI cost report

`GET /api/admin/ai-cost?from=YYYY-MM-DD&to=YYYY-MM-DD` is an existing route in
`routes/admin.php` (super-admin stack). The change is additive. The target documentation is
`docs/ai-cost-attribution-api-documentation.md`.

## Added: `by_capability` on each account

```json
{
  "hotel_group_id": "…",
  "account": "Grand Harbor Hotel",
  "ai_cost_usd": 12.481203,
  "ai_cost_eur": 11.52,
  "calls": 312,
  "cost_per_call_eur": 0.0369,
  "by_trigger": { "…": "unchanged" },
  "by_model": { "…": "unchanged" },
  "by_hotel": { "…": "unchanged" },
  "by_capability": {
    "concierge":     { "label": "Concierge",       "calls": 190, "input_tokens": 280000, "output_tokens": 31000, "ai_cost_usd": 8.1, "ai_cost_eur": 7.48, "cost_is_estimated": false },
    "guest_memory":  { "label": "Guest memories",  "calls": 14,  "input_tokens": 14000,  "output_tokens": 1270,  "ai_cost_usd": 0.42, "ai_cost_eur": 0.39, "cost_is_estimated": false },
    "document_vision": { "label": "Document reading", "calls": 4, "input_tokens": 9000, "output_tokens": 1500, "ai_cost_usd": 0.0, "ai_cost_eur": 0.0, "cost_is_estimated": true }
  },
  "contract_value": null,
  "gross_margin": null,
  "margin_unavailable_reason": "…",
  "cost_basis": "…",
  "estimated_rows_pct": 1.3
}
```

Rules (tested):
1. The `ai_cost_usd` values in `by_capability` sum to the account's `ai_cost_usd`, within
   rounding of 0.000001, and their `calls` sum to `calls`.
2. Only capabilities with at least one call are listed. That keeps the existing `by_*`
   convention; the zero-filled list is a hotel-facing convenience.
3. `cost_is_estimated` is true when any row in the group was priced as an estimate, for
   example an unpriced model (spec US4.4).
4. Keys and labels are the same as the hotel-facing `by_capability` (FR-016).
5. A non-super-admin gets `403` (unchanged route guard). The test covers an admin and an
   employee holding every permission.
