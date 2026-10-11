# Contract: Cost visibility fixes and checks

## Behaviour changes on existing endpoints

| Endpoint / surface | Before | After |
| --- | --- | --- |
| `GET /api/knowledge-documents` (and `show`): `error` for admins | When the daily AI ceiling stopped indexing, it held the exception message, including the dollar amount spent today and the ceiling | "The daily AI usage limit was reached; try again tomorrow." (`failure_code` stays `usage_limit_reached`) |
| `POST /api/ai-advisor/chat` when the account is past its daily ceiling | Uncaught exception: a 500 whose body could carry the amounts when debug is on | `503` `{ "message": "The AI assistant is temporarily unavailable. Try again later.", "code": 503, "body": null }` |
| Admin AI answer to "what does the AI cost us?" | No rule beyond "never show AI costs" | Says cost is not available here and offers the usage report. Never estimates (FR-013) |

Stored errors on existing failed documents are fixed by a data migration
(`2026_10_11_000003_redact_ceiling_errors_on_knowledge_documents.php`). It rewrites `error`
to the neutral text wherever `failure_code = 'usage_limit_reached'`. It is idempotent, and
`down()` is a no-op.

## The automated check (`tests/Feature/CostVisibilityTest.php`)

**Sentinels seeded** by every scenario:

| Item | Value |
| --- | --- |
| AI usage log model | `sentinel-model-x9` |
| AI usage log provider | `sentinel-provider` |
| AI usage log `cost_usd` | `987.654321` |
| Model price | for `sentinel-model-x9` |
| Contract value (`hotel_groups.contract_value_monthly`) | `4242.42` |
| Spend ceiling | one breached ceiling, so the ceiling message paths run |

The test then makes four checks:

1. **Static** (a file scan over `app/` with PHP comments stripped): only the allowlist in
   tasks.md T010(a) may reference `AiUsageLog`, `AiModelPrice`, `cost_usd`,
   `price_per_million`, `contract_value` or `gross_margin`. That list is the single source;
   it includes the existing log-only `FlagAiCostOverrunsJob`, `EventLogger`'s audit
   exclusion, and the super-admin-only `AiActivityResource`.
2. **Routes**: for every `GET` route under `/api` that is outside `routes/admin.php`, it
   calls the route as a hotel admin and as an employee with every permission. The response
   must contain none of the denylisted keys from `usage-api.md` rule 4 and none of the
   sentinel values (`sentinel-model-x9`, `sentinel-provider`, `987.65`, `4242.42`).
   Unresolvable routes fail the test by name. The plain key `price` is allowed, because
   activity and booking prices are hotel data.
3. **Admin AI tools**: every `ReadsData` tool in `AdminToolset` and on `InsightsAgent` is
   called with valid arguments, and its output is held to the same assertions.
4. **Messages and notifications**: with the ceiling breached, the guest WhatsApp reply is
   the neutral `UNAVAILABLE_REPLY`. Every notification in `app/Notifications/` renders with
   no sentinel value. No export endpoint exists yet, and the test notes that a future one
   must be added here.

Super-admin counter-checks, which must still pass:
- `/api/admin/ai-cost` shows the sentinel cost and model.
- `/api/ai-activity` as a super admin shows `cost_usd`.
