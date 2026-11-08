# Implementation Plan: Account-Level Hotel Ownership

**Branch**: `025-account-hotel-ownership` | **Date**: 2026-11-07 | **Spec**: [spec.md](spec.md)

**Input**: Feature specification from `specs/025-account-hotel-ownership/spec.md`

## Summary

Move ownership from the hotel to the account. `hotels.owner_id` goes, each account gets at most one owner enforced by a unique index, and registration makes the new user their account's owner. Admins then work across every module in the hotels they can reach, one hotel per request: an owner in any of the account's hotels, and other admins in their home and member hotels. One middleware delivers that, by pointing both the tenant scope and `$user->hotel` at the hotel chosen with `X-Hotel-Id`, so no policy changes. Only the owner can create, promote, edit or delete other admins. Employees don't change.

The request's mismatches and the clarified decisions are in [plan-verification.md](plan-verification.md). The spec follows them, and so does this plan.

## Technical Context

**Language/Version**: PHP 8.3, Laravel 13

**Primary Dependencies**: Sanctum, `laravel/ai` and Pest 4, all already in use. Nothing new.

**Storage**: PostgreSQL + pgvector. One migration drops `hotels.owner_id`, adds a composite unique index on `users`, and adds `agent_conversations.hotel_id`. See [data-model.md](data-model.md).

**Testing**: Pest feature tests on real Postgres, run with `php artisan test --parallel`. One route-driven test covers every tenant module (SC-009).

**Target Platform**: Linux server. No new setting or worker.

**Project Type**: JSON API web service

**Performance Goals**: No extra query on the normal path. One `Hotel` lookup, plus the user's team if they have one, only when a request works in a hotel other than the user's stored `hotel_id`. `HotelPolicy` repeats `accessibleHotelIds()` (one or two small queries) on `GET`, `PUT` and `PATCH /api/hotel/{id}` only.

**Constraints**: No suspension behaviour changes. No policy changes for tenant modules. No rollback or backfill: an existing dev database is freshly migrated. No shipped migration edited. Employees' access doesn't change. WhatsApp stays on the default hotel. No frontend edits here.

**Scale/Scope**: 1 migration, 1 middleware, 0 new routes, 0 new permissions, about 15 changed classes, 2 new test files, 56 existing test files touched (most by one line), 9 docs changed plus the breaking-changes document and `CLAUDE.md`.

## Constitution Check

*GATE: Must pass before Phase 0 research. Re-check after Phase 1 design.*

| Principle | Result | Evidence |
| --- | --- | --- |
| I. PMS system of record | Pass | No operational data is changed. Editing a record no longer can move it between hotels ([R8](research.md#r8-article-updates)) |
| II. Preserve and Evolve | Pass | Reuses `accessibleHotelIds()`, `TenantContext`, `X-Hotel-Id`, `DomainRuleException` and the unique-violation pattern of `RoomHoldConflict` |
| III. Tenant Isolation | Pass | Admins' scope gets narrower, to one hotel, and never wider than `accessibleHotelIds()`. The middleware skips `null` and keeps `[]` as `[]`. An unreachable hotel in the header is refused. Route-driven tests prove both directions |
| IV. Permission-Based Authorization | Pass | `allows()` and tenant module policies don't change. The middleware tests `isAdmin()` only to set the scope of work, as `User::permissions()` already separates admins. User management stays in the admin-only group `CLAUDE.md` names ([R2](research.md#r2-reach-for-other-records), [R3](research.md#r3-applying-x-hotel-id)) |
| V. AI through tools | Pass | Tools keep their hotel from the user. An advisor conversation stays in the hotel it started in, so a confirmation can't run elsewhere ([R7](research.md#r7-advisor-conversations)) |
| VI. Auditability | Pass | Ownership changes appear in `platform.user.created` and `platform.user.access_changed`, including the account link |
| VII. Data integrity | Pass | One owner is guaranteed by a database index, not by a check that can race. Writes stay in their existing transactions |
| VIII. Tested at the boundary | Pass | Feature tests on each endpoint, a concurrent-path test through the index, and a route-driven module test |
| IX. Spec-driven delivery | Pass | Follows spec 024's review finding 3 |
| Internationalization: English and Arabic | Pass | New refusals are API messages in English, as elsewhere. Nothing guest-facing changes |
| Security: least privilege | Pass | Closes the owner takeover and the half-demotion, and the tenant write path for account attributes. Owner reach is never granted automatically, apart from registration |
| API Design: breaking changes documented | Pass | `docs/latest-changes-<date>.md` covers all of it ([contracts](contracts/account-ownership.md#breaking-changes)) |
| Documentation | Pass | See [Documentation](#documentation) |
| Non-negotiable rule 22: new architecture justified | Pass | One small middleware, with alternatives in [R3](research.md#r3-applying-x-hotel-id) |

**Gate result**: Pass.

**Post-design re-check**: Pass. Analysis overturned the first design, which kept lists across hotels and put a role branch in `allows()`. One hotel per request removes that deviation, and closes the dashboard, list and hotel-stamping problems together (V19).

## Project Structure

### Documentation (this feature)

```text
specs/025-account-hotel-ownership/
├── spec.md
├── plan.md
├── plan-verification.md
├── research.md
├── data-model.md
├── quickstart.md
├── contracts/
│   └── account-ownership.md
├── checklists/
│   └── requirements.md
└── tasks.md             # /speckit-tasks, not created here
```

### Source Code (repository root)

```text
app/
├── Console/Commands/SeedBenchmarkVolumeCommand.php      # no owner_id
├── Enums/GroupRole.php                                  # owner only, comment gone
├── Http/
│   ├── Controllers/
│   │   ├── AiAdvisorController.php                      # conversation must match the hotel
│   │   ├── Auth/RegisterUserController.php              # owner of the new account
│   │   ├── HotelController.php                          # PLATFORM_ATTRIBUTES, links checked under the edited hotel
│   │   ├── KnowledgeBaseArticleController.php           # stop passing a hotel on update
│   │   └── UserController.php                           # account attributes, owner rules
│   ├── Middleware/
│   │   ├── EnsureNotSuspended.php                       # comment only
│   │   └── UseChosenHotel.php                           # new, alias hotel_choice
│   └── Resources/HotelResource.php                      # no owner_id, no owner
├── Ai/Tools/UpdateKnowledgeArticleTool.php              # no hotel argument
├── Models/
│   ├── Hotel.php                                        # fillable, owner()
│   └── User.php                                         # group_role cast, isOwner()
├── Policies/
│   ├── HotelPolicy.php                                  # view, update
│   └── UserPolicy.php                                   # owner-only rules for other admins
├── Services/
│   ├── Ai/AdvisorTurn.php                               # stamps the conversation's hotel
│   ├── Knowledge/ArticleCommands.php                    # update no longer stamps a hotel
│   └── Superadmin/
│       ├── HotelAdministration.php                      # owner check gone
│       └── UserAdministration.php                       # one owner, ending ownership
└── Support/Superadmin/SecondOwner.php                   # new, recognizes the index violation
bootstrap/app.php                                        # alias, priority
database/
├── factories/HotelFactory.php                           # no owner_id
├── migrations/2026_11_07_000002_move_hotel_ownership_to_account.php
└── seeders/HospitalitySeeder.php                        # no owner_id
routes/api.php                                           # stack, four opt-outs
tests/Feature/
├── AccountOwnershipTest.php                             # new: one owner, register, ending, guards
├── MultiHotelAccessTest.php                             # new: modules, header, article, advisor
├── SuspensionRouteInventoryTest.php                     # hotel_choice on every route
├── CrossTenantRouteTest.php, TenantIsolationTest.php    # admin lookup, no owner_id
├── HotelControllerTest.php, GenericCrudTest.php         # admins need a home hotel
├── SuspensionTest.php, DeletedHotelAccessTest.php       # header now 403, real owners
├── Superadmin/{User,Hotel}AdministrationTest.php        # ownership and move rules
├── CreateUserTest.php, GenericIndexFilterTypesTest.php  # ignored fields, filter column
└── (about 45 other files)                               # delete the owner_id line
```

**Structure Decision**: The existing Laravel layers. The one new middleware exists because the header's refusal has to run after suspension narrowing and be skippable on logout, which `ResolveTenant` can't do ([R3](research.md#r3-applying-x-hotel-id)).

## Design Notes

- **One hotel per request**: for an admin, the tenant scope and `$user->hotel` both point at the current hotel, so controllers, services, reports, policies and AI tools already agree. `hotel_id` stays the stored home hotel ([R3](research.md#r3-applying-x-hotel-id)).
- **What reaches further**: `HotelPolicy` reads every hotel a user may reach, so a suspended hotel gets its own `403` ([R1](research.md#r1-reach-in-policies)). `GET /api/hotel` and the WhatsApp device routes opt out of the narrowing.
- **Records keep their own hotel on update**: one exception is fixed as a safeguard ([R8](research.md#r8-article-updates)).
- **Protected by construction**: `hotel_choice` joins the tenant group's stack, and logout opts out, so a new route can't be left out.

## Corrections to the request

From [plan-verification.md](plan-verification.md) and clarification. Planning and analysis added the last five.

| Request | This plan | Source |
| --- | --- | --- |
| Users are soft-deleted | They aren't, so the index has no `deleted_at` condition | V1 |
| One owner, enforcement unstated | Composite unique index, with its violation mapped to `422` | V2 |
| First admin's ownership undecided | Never automatic, except registration | V3 |
| "Detach" the owner before a move | Clearing the group role also clears the account link, because no detach exists | V5 |
| Tenant user endpoint not settled | Ignores account attributes for every caller | V7, clarified |
| Owner removal not settled on the tenant side | Only the owner can create, promote, edit or delete another admin. Owner can't demote or delete themselves | V8, clarified |
| Only `/api/hotel/{id}` opens | Every module, for hotels the admin can reach, one per request | V9, V19, clarified |
| Unreachable `X-Hotel-Id` not mentioned | Plain `403`, not a silent fallback | Clarified |
| Demo hotel has an owner | It belonged to the super admin, so the demo account has none | V11 |
| Header refusal in `ResolveTenant` (first draft of V9) | A new middleware, because logout has to stay reachable | V17 |
| Article updates not mentioned | `ArticleCommands::update` stops stamping the caller's hotel | V15 |
| Advisor not mentioned | Web conversations are tied to their hotel | V16 |
| A deleted or missing home hotel not mentioned | The relation follows the current hotel, so the owner isn't told they have no hotel | V18 |
| `$user->hotel` was thought enough on its own | The scope narrows too, because the dashboard, lists and implicit stamps read the scope | V19 |

## Documentation

- **Modify**: `docs/hotel-api-documentation.md`, `docs/user-management-api-documentation.md`, `docs/superadmin-accounts-api-documentation.md`, `docs/frontend-create-modules-api.md`, `docs/register-api-update.md`, `docs/usage-metering-api-documentation.md`, `docs/designs/super-admin-platform.md`. Line numbers are in [plan-verification.md](plan-verification.md#change-inventory).
- **Modify**: `docs/auth-api-documentation.md` and the permission reference in `docs/staff-roles-api-documentation.md`, for how `X-Hotel-Id` and reach now work.
- **Create or extend**: `docs/latest-changes-<date>.md`. If implemented on 2026-11-07, add to the existing file.
- **Modify**: `CLAUDE.md`. The tenancy section needs one hotel per request for admins and `$user->hotel`'s new meaning, and the access control section the owner-only rules.
- **Frontend**: follows from a handoff after implementation, through `/frontend-apply-changes`.
