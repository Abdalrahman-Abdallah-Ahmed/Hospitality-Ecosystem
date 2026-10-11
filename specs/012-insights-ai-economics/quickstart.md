# Quickstart: validating Insights Citations and AI Economics

## Prerequisites

```bash
docker compose -f .postgres/compose.yaml up -d   # Postgres + pgvector (tests need it)
php artisan migrate                               # applies the three 2026_10_11_* migrations
```

AI calls are faked in tests (`InsightsAgent::fake()`, `GuestMemoryAgent::fake()`, …), so
no provider key is needed for the suite.

**Baseline** (T001, `main` at `9d5b26b`, 2026-10-11): `php artisan test --parallel`, with
1684 passed and 0 failed.

## 1. Run the feature's tests

```bash
php artisan test --filter=CostVisibilityTest           # US1: no cost/model/provider in any hotel-facing surface
php artisan test --filter=AiCapabilityCoverageTest     # US2: every capability metered + attributed, zero unattributed
php artisan test --filter=AccountUsageAiBlockTest      # US1: requests/tokens per capability, sums exact, measured_since
php artisan test --filter=InsightCitationsTest         # US3: citations, evidence rule, dropped refs, availability
php artisan test --filter=AiCostCapabilityTest         # US4: by_capability sums, estimated flag, 403s
php artisan test --filter=GuestMemoryExtractionTest    # US2: guest_memory_extractions metered once; measured_since
php artisan test                                       # full suite stays green
```

## 2. Expected outcomes by story

| Story | Scenario | Expected |
| --- | --- | --- |
| US1 | `GET /api/usage` as an admin after seeded AI calls | `body.ai` totals equal the sum of `by_capability` and of `by_hotel`. No `cost*`, `price`, `margin`, `model` or `provider` key, and no sentinel value ([contracts/usage-api.md](contracts/usage-api.md)) |
| US1 | `GET /api/usage` as an employee | `403` |
| US1 | `POST /api/ai-advisor/chat` with a breached ceiling | `503`, neutral message, no amounts ([contracts/cost-visibility.md](contracts/cost-visibility.md)) |
| US1 | A knowledge document stopped by the ceiling | `error` is the neutral text |
| US2 | Memory extraction completes, then is re-dispatched | Exactly one `guest_memory_extractions` meter event. The `ai_usage_logs` rows have `capability = guest_memory` and `trigger_kind = scheduled_job` |
| US2 | The coverage test after every capability has run | Zero rows with `capability = unattributed` or `trigger_kind = unattributed` |
| US3 | A hotel policy plus contradicting reservations, then insights generated | The insight's `citations[0]` is the policy (`scope: hotel`, `available: true`). `evidence_level` comes from the reservation ([contracts/insights-api.md](contracts/insights-api.md)) |
| US3 | The faked agent returns ref `K9`, which the tool never returned | The citation is dropped and a warning logged |
| US3 | A knowledge-only insight | `category: knowledge`, `evidence_level: L3` |
| US3 | The cited policy is deleted, then the insights are listed | The citation is still listed with `available: false` |
| US4 | `GET /api/admin/ai-cost` as a super admin | The `by_capability` cost and calls sum to the account totals. An unpriced model gives `cost_is_estimated: true` ([contracts/ai-cost-api.md](contracts/ai-cost-api.md)) |
| US4 | The same route as an admin | `403` |

**Coverage of §2** (T051): every row above is asserted by a passing test.
- Cross-account isolation lives in `AccountUsageAiBlockTest` (AI usage) and `InsightCitationsTest` (citations).
- The knowledge-document ceiling `error` is in `KnowledgeDocumentVisionTest`.
- The memory meter is in `GuestMemoryExtractionTest`.
- The only manual-only item is the Admin AI's wording when asked about cost (FR-013), because it depends on the live model (§3 step 4). The tool layer gives it no cost to reveal, which `CostVisibilityTest` asserts.

## 3. Manual smoke test (optional, dev database with a real provider key)

1. `POST /api/ai-insights` as a hotel admin whose hotel has at least one active policy.
   Wait for the queue, then `GET /api/ai-insights`. Expect some insights with `citations`,
   and `[]` on the others.
2. `GET /api/usage`. Expect `ai.by_capability.insights.requests` to be at least 1.
3. Log in as a super admin and run `GET /api/admin/ai-cost`. The same capability should show
   a cost.
4. Ask the Admin AI "How much did AI cost us this month?" It should decline to give a cost
   and offer usage.

## 4. Before committing

```bash
./vendor/bin/pint
php artisan test
```

Then update the docs listed in [plan.md](plan.md) § Documentation.
