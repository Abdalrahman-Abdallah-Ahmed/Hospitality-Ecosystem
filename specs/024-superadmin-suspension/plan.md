# Implementation Plan: Account and Hotel Suspension

**Branch**: `024-superadmin-suspension` | **Date**: 2026-11-07 | **Spec**: [spec.md](spec.md)

**Input**: Feature specification from `specs/024-superadmin-suspension/spec.md`

## Summary

Let a super admin stop an account or one hotel completely, and restore it with its data kept. Suspension lives in new columns written by one service, and one rule on the models decides what counts as suspended. Five enforcement points apply it: login, a middleware on the tenant routes, the hotel's own routes, a job middleware plus filters in looping jobs, and the inbound WhatsApp job. `GET /api/hotel` opens to admins and to employees holding a new `hotels.view` permission, limited to their own active hotels.

The source design is Stage 3 of [docs/designs/super-admin-platform.md](../../docs/designs/super-admin-platform.md). This plan follows it except where [plan-verification.md](plan-verification.md) and the spec's clarifications overrule it. See [Corrections to the design](#corrections-to-the-design).

## Technical Context

**Language/Version**: PHP 8.3, Laravel 13

**Primary Dependencies**: Sanctum, the database queue, the database cache store and the Sentry SDK, all already in use. Pest 4. Nothing new.

**Storage**: PostgreSQL + pgvector. Three nullable columns on each of `hotel_groups` and `hotels`. The fixed-reply limit is a cache entry. See [data-model.md](data-model.md).

**Testing**: Pest feature tests on real Postgres, run with `php artisan test --parallel`. Sentry tag tests set a DSN themselves, because `phpunit.xml` forces it empty.

**Target Platform**: Linux server. No new setting or worker.

**Project Type**: JSON API web service

**Performance Goals**: One indexed query per hotel request on the normal path, not cached. Extra queries only when the default hotel is removed (replacement hotel, staff role) or the user has no hotels (account). One small lookup per job start.

**Constraints**: `User`, `ResolveTenant` and `TenantContext` don't change. No dashboard, impersonation endpoints or bypass removal. Queued notifications and pairing codes aren't gated (R11, R12). No rollback or backfill. No shipped migration edited.

**Scale/Scope**: 4 new super admin routes, 1 migration, 1 service, 2 middleware, 1 interface, 1 exception, 3 support classes, 2 new resources, 3 new form requests, 2 language files. 10 jobs gain the middleware and 6 gain filters. 5 docs change, 1 is created, and `CLAUDE.md` changes.

## Constitution Check

*GATE: Must pass before Phase 0 research. Re-check after Phase 1 design.*

| Principle | Result | Evidence |
| --- | --- | --- |
| I. PMS system of record | Pass | No operational data is changed or removed. Purges skip a suspended hotel's documents |
| II. Preserve and Evolve | Pass | `is_active` keeps its meaning. Reuses `DomainRuleException`, `recordPlatform`, `TagsSentryScope`, `GenericQuery`, `UNAVAILABLE_REPLY` and the `THROTTLED` status |
| III. Tenant Isolation | Pass | The middleware only narrows `TenantContext`, keeping `null` and `[]` distinct. The hotel list is limited to reachable hotels, and a caller who can't reach a hotel is never told it is suspended. Tests prove both |
| IV. Permission-Based Authorization | **Justified deviation** | `hotels.view` goes through `allows()`, with allowed and `403` tests, and isn't an employee default. The list's response *shape* depends on role. See [Complexity Tracking](#complexity-tracking) |
| V. AI through tools | Pass | No tool changes. The AI simply doesn't run for a suspended hotel |
| VI. Auditability | Pass | Four platform events, with actor and reason. Blocked jobs log a line and a handler's failure goes to `report()`, not swallowed |
| VII. Data integrity | Pass | Suspend and reactivate lock the row in a transaction with their audit event. The fixed reply is decided once and stored, so a retry doesn't send a second one or lose it |
| VIII. Tested at the boundary | Pass | Feature tests on every enforcement point, plus route and job inventory tests |
| IX. Spec-driven delivery | Pass | Stage 3 of 6, after Stage 2 |
| Internationalization: English and Arabic | **Justified deviation** | Guests get the fixed reply in English or Arabic (V17). The suspended API messages stay English only. See [Complexity Tracking](#complexity-tracking) |
| Security: least privilege | Pass | Suspension columns aren't fillable. Employees see a short hotel record, and can't filter or search hidden fields |
| API Design: breaking changes documented | Pass | `docs/latest-changes-2026-11-07.md` covers the new `403` shape and the list's new audience |
| Documentation | Pass | See [Documentation](#documentation) |
| Non-negotiable rule 22: new architecture justified | Pass | Alternatives C to F in the design, and [research.md](research.md) |

**Gate result**: Pass, with two justified deviations.

**Post-design re-check**: Pass, no change. The design moved the suspension calculation into one shared class and made it per hotel, which tightens isolation and adds no new access path.

## Project Structure

### Documentation (this feature)

```text
specs/024-superadmin-suspension/
├── spec.md
├── plan.md
├── plan-verification.md
├── research.md
├── data-model.md
├── quickstart.md
├── contracts/
│   └── suspension.md
├── checklists/
│   └── requirements.md
└── tasks.md             # /speckit-tasks, not created here
```

### Source Code (repository root)

```text
app/
├── Contracts/HasHotel.php                               # new
├── Enums/
│   ├── SuspensionScope.php                              # new
│   ├── InboundMessageStatus.php                         # THROTTLED comment
│   └── Permission.php                                   # HOTELS_VIEW, docblock
├── Exceptions/HotelSuspended.php                        # new
├── Http/
│   ├── Controllers/
│   │   ├── Auth/AuthenticatedSessionController.php      # apiLogin
│   │   ├── HotelController.php                          # index, show, update
│   │   └── Superadmin/{Account,Hotel}Controller.php     # suspend, reactivate, filter
│   ├── Middleware/EnsureNotSuspended.php                # new, alias not_suspended
│   ├── Requests/
│   │   ├── HotelListRequest.php                         # new, tenant list
│   │   └── Superadmin/
│   │       ├── AccountIndexRequest.php                  # suspended filter
│   │       ├── HotelIndexRequest.php                    # new
│   │       ├── SuspendRequest.php                       # new
│   │       └── Concerns/FiltersBySuspension.php         # new
│   └── Resources/
│       ├── HotelSummaryResource.php                     # new, employees
│       └── Superadmin/
│           ├── AccountResource.php                      # suspension fields
│           └── HotelResource.php                        # new (V1)
├── Jobs/
│   ├── Middleware/SkipIfHotelSuspended.php              # new
│   ├── ProcessInboundWhatsAppMessageJob.php             # fixed reply
│   └── (10 hotel jobs, 6 looping jobs)                  # see research R6, R7
├── Models/
│   ├── Concerns/Filterable.php                          # scopeSearchColumns
│   ├── Hotel.php                                        # casts, rule, scopes
│   └── HotelGroup.php                                   # casts, rule, scope
├── Policies/HotelPolicy.php                             # viewAny
├── Services/Superadmin/Suspension.php                   # new
└── Support/Suspension/
    ├── HotelAccess.php                                  # new, the shared calculation
    ├── ImpersonatedRead.php                             # new, null until stage 5
    └── SuspendedResponse.php                            # new
bootstrap/app.php                                        # alias, priority
database/migrations/2026_11_07_000001_add_suspension_to_hotel_groups_and_hotels.php
lang/{en,ar}/suspension.php                              # new
routes/api.php                                           # stack, logout, GET /user
routes/superadmin.php                                    # 4 routes
tests/Feature/
├── SuspensionModelTest.php                              # new: the one rule
├── SuspensionTest.php                                   # new: CUJ 2, CUJ 3, access
├── SuspensionAdministrationTest.php                     # new: endpoints, audit, filters
├── SuspensionJobTest.php                                # new: jobs, handlers, rebuild
├── SuspensionWhatsAppTest.php                           # new: fixed reply
├── SuspensionRouteInventoryTest.php                     # new
├── JobSuspensionInventoryTest.php                       # new
├── HotelControllerTest.php, GenericCrudTest.php         # list expectations
└── PermissionAuthorizationTest.php                      # hotels.view
```

**Structure Decision**: The existing Laravel layers. The enforcement classes are new, because no existing class can hold them without breaking the rule that `User`, `ResolveTenant` and `TenantContext` don't change. `App\Services\Superadmin\Suspension` sits beside the Stage 2 services.

## Design Notes

- **One rule**: `Hotel::isSuspended()` and `Hotel::scopeNotSuspended()` decide everything, and both read a soft-deleted account too ([research R1](research.md#r1-the-one-rule)).
- **One calculation**: login and the middleware both use `HotelAccess`, so they can't disagree about who is refused ([research R2](research.md#r2-the-shared-calculation)).
- **Protected by construction**: `not_suspended` joins the tenant group's stack, and logout opts out, so a new route can't be left out ([research R3](research.md#r3-applying-the-middleware)).
- **The tenant resource is untouched**: suspension fields appear only on `Superadmin\*` resources.

## Corrections to the design

From [plan-verification.md](plan-verification.md) and clarification. Planning added the last four.

| Design | This plan | Source |
| --- | --- | --- |
| Adds fields to `Superadmin\HotelResource` | Creates that resource, which doesn't exist | V1 |
| Hotel routes return the suspended `403` to anyone authorized | Skips super admins | V2 |
| Employees keep access to active hotels | Only hotels they're explicitly assigned to, with their role's permissions | V3, clarified |
| `/api/hotel` is admin-only today, wrong test lines | Super admin only today, correct tests and docs | V4 |
| Hotel list returns the full record | Employees get a short record and limited search, filter and sort | V16, clarified |
| Hotel filter and three columns only | Adds `is_suspended` | V5 |
| No rule for reactivate or for levels combined | Independent levels, `422` when not suspended | V6 |
| Handler called directly | A handler's exception is caught and reported | V7 |
| Reason's location unstated | In the event's `context` | V8 |
| WhatsApp gate reads the hotel | Also the sender's default hotel and account when it is null | V10 |
| `User::account()` default | `account(withTrashed: true)` | V11 |
| One logout route exempt | Both | V13 |
| Sentry tag only on block | Also re-tags `hotel_id` when the current hotel changes | V14 |
| Fixed reply is one English string | English or Arabic by the guest's language | V17, clarified |
| `/logout` under `tenant`, the rest nested in a new group | `not_suspended` on the group, logout opts out | [R3](research.md#r3-applying-the-middleware) |
| "The account is suspended when the group on the returned rows is" | Decided per hotel, which gives the same answer with one account, and fails safe otherwise | [R2](research.md#r2-the-shared-calculation) |
| `filter[suspended]` with no stated rule | Accepts `true`, `false`, `1` and `0`, because Laravel's `boolean` rule refuses `true` in a query string | [R5](research.md#r5-lists-and-filters) |
| Staff role not mentioned on the middleware | Loaded before narrowing, so an assigned employee's role still reads | [R2](research.md#r2-the-shared-calculation) |

## Documentation

- **Modify**: `docs/superadmin-accounts-api-documentation.md` (four endpoints, filter, fields), `docs/auth-api-documentation.md` (suspended login, `hotel_id` differing from `hotel.id`), `docs/hotel-api-documentation.md` (list audience and short record, lines 54 and 167), `docs/staff-roles-api-documentation.md` (`hotels.view`, lines 19–22 and 359), `docs/whatsapp-device-api-documentation.md` (fixed reply).
- **Create**: `docs/latest-changes-2026-11-07.md` (the `403` shape every client must handle, the list's new audience).
- **Modify**: `CLAUDE.md` (access control item 4 names this read as the exception), and the `Permission` enum's docblock.
- The frontend's changes follow, from a changes document after implementation.

## Complexity Tracking

| Violation | Why Needed | Simpler Alternative Rejected Because |
|-----------|------------|-------------------------------------|
| The hotel list's response shape and allowed columns depend on role (Principle IV) | Clarified requirement: employees get a short record, admins the full one. Hotel settings are deliberately outside the enum, so no permission exists to check, and `HotelPolicy` already gates them by role | A `hotels.view_settings` permission would let a role grant settings to an employee, which is exactly what the enum's rule forbids. It fails closed: anyone not an admin or super admin gets the short record |
| The suspended API messages are English only (Internationalization) | Nothing in `app/` calls `setLocale`, so no API message can be served in Arabic today. The frontend can translate from `body.reason` and `body.scope` | Adding request language selection for one message would be a platform change far outside this stage |
