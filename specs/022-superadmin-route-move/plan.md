# Implementation Plan: Super Admin Route Move

**Branch**: `022-superadmin-route-move` | **Date**: 2026-11-05 | **Spec**: [spec.md](spec.md)

**Input**: Feature specification from `specs/022-superadmin-route-move/spec.md`

## Summary

Move the 25 super admin endpoints from `/api/admin` to `/api/superadmin` in one release, with no alias and no behaviour change. `routes/admin.php` becomes `routes/superadmin.php`, registered in `bootstrap/app.php` with the same middleware stack and the new prefix. The six controllers move from `App\Http\Controllers\Admin` to `App\Http\Controllers\Superadmin`. Tests, comments and living docs follow, and a breaking-change note maps every old URL to its new one.

The source design is Stage 1 of [docs/designs/super-admin-platform.md](../../docs/designs/super-admin-platform.md). This plan re-checks its file lists against the code and adds what they miss (see [Corrections to the design](#corrections-to-the-design)).

## Technical Context

**Language/Version**: PHP 8.3, Laravel 13

**Primary Dependencies**: Sanctum, Pest 4. Nothing new.

**Storage**: PostgreSQL + pgvector. No schema or data change.

**Testing**: Pest feature tests on real Postgres, run with `php artisan test --parallel`

**Target Platform**: Linux server. `.github/workflows/deploy.yml` runs `composer install --optimize-autoloader` and `php artisan optimize`, which rebuild the class map and route cache, so deployment needs no extra step.

**Project Type**: JSON API web service

**Performance Goals**: Unchanged

**Constraints**: No alias or redirect. Controller logic, validation, resources and permissions stay untouched. Shipped migrations are not edited, and no new ones are needed.

**Scale/Scope**: 25 routes, 6 controllers, 2 route/bootstrap files, 7 other application files, 1 seeder, 11 test-side files, 9 docs plus `CLAUDE.md`, and 1 new note

## Constitution Check

*GATE: Must pass before Phase 0 research. Re-check after Phase 1 design.*

| Principle | Result | Evidence |
| --- | --- | --- |
| I. PMS system of record | Pass | No data touched |
| II. Preserve and Evolve | **Justified deviation** | The hard cut replaces a deprecation path. All functionality is preserved. See [Complexity Tracking](#complexity-tracking). |
| III. Tenant Isolation | Pass | Same `tenant` and `super_admin` middleware. `EntryPointDiscovery` and `crossTenantRuleFor()` classify routes by middleware, so the new routes are picked up automatically. |
| IV. Permission-Based Authorization | Pass | No permissions change. `api.key`, `auth:sanctum`, `throttle:api`, `tenant` and `super_admin` stay on the whole file. |
| V. AI through tools | Pass | No agent or tool calls these routes. `App\Ai\Tools\Admin` is a separate namespace and does not move. |
| VI. Auditability | Pass | Audit paths are inside the controllers and services, which are unchanged |
| VII. Data integrity | Pass | No mutation logic changes |
| VIII. Tested at the boundary | Pass | Existing tests move with address-only changes, plus a new test for FR-001, FR-005, FR-006 and FR-009 |
| IX. Spec-driven delivery | Pass | Stage 1 of 6 and separately deployable, on the condition that the frontend ships with it |
| API Design: breaking changes documented | Pass | Breaking-change note required by FR-014 |
| Documentation | Pass | Every affected API doc is updated. `staff-roles` changes only the URL, since no permission changes. |

**Gate result**: Pass, with one justified deviation.

**Post-design re-check**: Pass, no change. The design adds no new abstraction, dependency or behaviour.

## Project Structure

### Documentation (this feature)

```text
specs/022-superadmin-route-move/
├── plan.md
├── research.md
├── data-model.md
├── quickstart.md
├── contracts/superadmin-routes.md
├── checklists/requirements.md
└── tasks.md             # /speckit-tasks
```

### Source Code (repository root)

```text
bootstrap/app.php                          # prefix, file path, comment
routes/admin.php → routes/superadmin.php   # new namespace imports, header comment

app/Http/Controllers/Admin/* → app/Http/Controllers/Superadmin/*
    AiCostController, GuestMemoryExtractionController,
    KnowledgeBaseArticleController, KnowledgeDocumentController,
    KnowledgeRebuildController, UsageController

# Comments only
app/Http/Controllers/AccountUsageController.php
app/Http/Controllers/KnowledgeBaseArticleController.php
app/Http/Controllers/KnowledgeDocumentController.php
app/Services/Knowledge/ArticleCommands.php
app/Policies/KnowledgeDocumentPolicy.php
app/Models/KnowledgeDocument.php
app/Models/KnowledgeIndexRebuild.php
database/seeders/AiModelPriceSeeder.php

tests/Feature/Admin/ → tests/Feature/Superadmin/
    GlobalKnowledgeTest, KnowledgeRebuildTest,
    GuestMemoryExtractionAdminTest → GuestMemoryExtractionSuperadminTest
tests/Feature/Superadmin/RouteMoveTest.php       # new
tests/Feature/CostVisibilityTest.php             # allowlist, route filter, URL
tests/Feature/AiCostAttributionTest.php          # URLs, comment
tests/Feature/AccountUsageAiBlockTest.php        # URLs
tests/Feature/AiCostCapabilityTest.php           # URLs
tests/Feature/KnowledgeBaseArticleControllerTest.php  # URL
tests/Feature/KnowledgeDocumentVisionTest.php    # URL
tests/Feature/UsageMeteringTest.php              # URLs
tests/Pest.php                                   # comments

docs/usage-metering-api-documentation.md
docs/ai-cost-attribution-api-documentation.md
docs/global-knowledge-admin-api-documentation.md
docs/guest-memories-api-documentation.md
docs/knowledge-base-article-api-documentation.md
docs/knowledge-document-api-documentation.md
docs/auth-api-documentation.md
docs/staff-roles-api-documentation.md
docs/AI_Hospitality_Ecosystem_Implementation_Plan_v1_0.md
docs/latest-changes-<implementation date>.md     # new
CLAUDE.md                                        # "Request flow" section
```

**Structure Decision**: Existing Laravel layout. The route file and controller folder are renamed in place, so `git mv` keeps their history.

## Corrections to the design

Checked with a repo-wide search for `api/admin`, `routes/admin` and the `Admin` controller namespace.

| # | Finding | Handling |
| --- | --- | --- |
| 1 | [CostVisibilityTest.php:361](../../tests/Feature/CostVisibilityTest.php#L361) excludes routes starting with `api/admin` from the hotel-facing cost-leak scan. The design names only the allowlist entry at line 314. If line 361 is missed, the scan treats super admin routes as hotel-facing. | Change the filter to `api/superadmin`. The route comparison in the quickstart proves the hotel-facing set is unchanged (FR-012, SC-005). |
| 2 | The roadmap, `docs/AI_Hospitality_Ecosystem_Implementation_Plan_v1_0.md` (lines 56 and 465), names `routes/admin.php`. The design doesn't list it. It is a living document. | Update both references (FR-013, SC-006). |
| 3 | `AiCostAttributionTest` checks the full middleware stack on only 2 routes. | `RouteMoveTest` checks every route under the new prefix (SC-004). The old test keeps its scope with new URLs. |
| 4 | The design keeps the file name `GuestMemoryExtractionAdminTest`. | Rename it, because FR-007 applies the new name to tests. |
| 5 | The design says `CLAUDE.md` "Request flow" and "Layers" need changes, but only "Request flow" (lines 87 and 89) mentions the old names. | Update "Request flow" only. |

The design's other file lists match the code.

## Implementation Order

1. Capture the route snapshot **before any change** (see [quickstart.md](quickstart.md)). It can't be recreated afterwards without checking out old code.
2. Move the controllers and route file, and update `bootstrap/app.php`. Run `composer dump-autoload`, because `optimize-autoloader` keeps the old class map locally.
3. Update test URLs, move the test folder and fix `CostVisibilityTest`.
4. Add `RouteMoveTest`.
5. Update comments, docs and `CLAUDE.md`, and write the breaking-change note.
6. Run the quickstart checks, Pint and the full test suite.

Steps 2 and 3 are one change in practice: the suite is red between them.

## Risks

| Risk | Mitigation |
| --- | --- |
| Frontend ships separately and its super admin screens get `404` | Both deploy in one release, and the note says so. Agreed handoff: the frontend adapts after the backend is built, using `/frontend-apply-changes`. |
| A route is dropped or its order changes, so `knowledge-documents/deleted` is read as an id | The route file moves as is. The route comparison and `RouteMoveTest` both catch this. |
| The `RouteMoveTest` breaks when Stage 2 adds routes | It checks that the 25 exist, not that only 25 exist. The exact count is a one-off quickstart check. See [research.md](research.md) R3. |
| A stale local class map or route cache gives confusing failures | `composer dump-autoload` and `php artisan optimize:clear` after the move |

## Complexity Tracking

| Violation | Why Needed | Simpler Alternative Rejected Because |
| --- | --- | --- |
| Principle II and the API Design section prefer a controlled deprecation path and avoiding breaking changes; this is a hard cut | The address is the problem being solved, and later stages add suspension and impersonation to this area, so it should have one guarded surface. The system is at development stage with test users only, one frontend is the only client, and no capability is removed. | Keeping `/api/admin` as an alias leaves two addresses to secure and keep in sync, and protects no external client. The design rejects it explicitly (line 155). |
