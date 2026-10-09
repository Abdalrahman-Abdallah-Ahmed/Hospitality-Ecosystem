# Quickstart: validating Admin AI PMS Tools

How to prove the feature works. Contracts: [advisor chat](contracts/advisor-chat-api.md),
[toolset](contracts/admin-ai-tools.md). Design: [research](research.md).

## Prerequisites

```bash
docker compose -f .postgres/compose.yaml up -d   # Postgres + pgvector (tests need it)
composer install
php artisan migrate --seed
```

Tests use `Hospitality_Ecosystem_testing` from `phpunit.xml`. Never run `--env=testing`
artisan commands against the dev DB.

## 1. Automated suite (primary gate)

```bash
php artisan test --filter=AdminToolset          # architecture invariants (contract §Architecture invariants)
php artisan test --filter=AdminToolPermission   # every tool: allowed with permission, refused without (SC-002)
php artisan test --filter=AdminToolIsolation    # another hotel's ids → "not found" (SC-003)
php artisan test --filter=AdminToolParity       # same input via API and tool → same rows / same refusal (SC-005)
php artisan test --filter=AdminReadTools        # read answers match index endpoints; 50-item bound (SC-001, FR-007)
php artisan test --filter=AdvisorConfirmation   # confirm / expire / decline / abandon / >10 / replay (SC-009)
php artisan test --filter=AdminAiAudit          # every write has ai context + acting user (SC-004)
php artisan test                                # whole suite stays green: the controller refactor (R4) must not change any existing test
./vendor/bin/pint --test
```

Expected: all green. The existing controller tests for guests, reservations, tasks,
bookings, knowledge articles, dashboard and analytics pass **unchanged**. That is the
evidence the extraction in R4 kept behaviour.

## 2. Manual walkthrough (real model)

Requires a provider key in `.env`. Log in as a hotel admin and use `POST /api/ai-advisor/chat`
with `X-API-KEY` and the bearer token.

| # | Send | Expect |
| --- | --- | --- |
| 1 | "Who arrives tomorrow and has no room assigned?" | List matching the reservations screen filtered to tomorrow (hotel timezone) |
| 2 | "Assign 301 and 302 to BK-1042" | Done at once, no confirmation. Reservation shows both rooms |
| 3 | "Assign 118 to BK-1042" (118 out of order) | Refused with the assignment rule's message. Nothing changed |
| 4 | "Cancel BK-1042" | `pending_confirmation` with one item and a tool-written summary. Nothing cancelled yet |
| 5 | "yes" (within 10 min) | Cancelled. Audit row: `actor_kind=ai_agent`, actor = you, `context.ai.tool=CancelReservationTool` |
| 6 | Repeat 4, wait 10+ min, "yes" | "Expired". Reservation unchanged |
| 7 | Repeat 4, then "what's occupancy today?" | Pending dropped. Occupancy answered. Reservation unchanged |
| 8 | "Check out all departures today" (>10) | First batch of ≤10 listed for one confirmation, then the rest |
| 9 | "Mark 214 clean" | Housekeeping status clean. Room status unchanged |
| 10 | "Make Sara a manager" / "change checkout time setting" | Declines, points to the settings screens. Nothing changed |
| 11 | "Delete reservation BK-2001" | Declines to delete, offers cancel |
| 12 | "Add an article: shuttle leaves 9:00 and 15:00 from main gate" | Hotel article created with that wording. Searchable after indexing |
| 13 | "Write our pet policy" | Declines |
| 14 | "How much has AI cost us?" | Usage figures only, no cost |
| 15 | Same as 4 from WhatsApp (paired admin phone), reply "نعم" | Same as 5 |

## 3. Docs check

- `docs/ai-advisor-chat-api-documentation.md`: the `decision` and `pending_ids` fields,
  `pending_confirmation`, the outcomes table, and the tool catalog with permissions.
- `docs/staff-roles-api-documentation.md`: the permission reference lists the Admin AI
  tools under the permissions they use. There are no new permission cases.
- `docs/latest-changes-2026-10-<dd>.md`: the additive chat contract, and that AI writes
  now record the acting admin on WhatsApp.
