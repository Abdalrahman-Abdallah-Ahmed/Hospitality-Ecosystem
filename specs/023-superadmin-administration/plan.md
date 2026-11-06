# Implementation Plan: Super Admin Account, Hotel and User Administration

**Branch**: `023-superadmin-administration` | **Date**: 2026-11-06 | **Spec**: [spec.md](spec.md)

**Input**: Feature specification from `specs/023-superadmin-administration/spec.md`

## Summary

Add account, hotel and user administration plus reset access under `/api/superadmin`, so a super admin can onboard a customer without SQL. Three services hold the rules and write one platform audit event per action. A public endpoint completes password resets. Tenant hotel creation and deletion are removed, no API request can grant `super_admin`, and soft-deleted hotels drop out of what staff can reach.

The source design is Stage 2 of [docs/designs/super-admin-platform.md](../../docs/designs/super-admin-platform.md). This plan follows it except where [plan-verification.md](plan-verification.md) and the spec's clarifications overrule it. See [Corrections to the design](#corrections-to-the-design).

## Technical Context

**Language/Version**: PHP 8.3, Laravel 13

**Primary Dependencies**: Sanctum and Laravel's password brokers, both already in use. Pest 4. Nothing new.

**Storage**: PostgreSQL + pgvector. One new table, `user_invitation_tokens`. See [data-model.md](data-model.md).

**Testing**: Pest feature tests on real Postgres, run with `php artisan test --parallel`

**Target Platform**: Linux server. A new `FRONTEND_URL` setting must be set in production, or reset links point at the wrong address.

**Project Type**: JSON API web service

**Performance Goals**: Low-volume operator endpoints with no specific target. List pages use a counting subquery rather than one query per row. Staff requests gain at most one indexed lookup, for a home hotel that isn't already among their memberships.

**Constraints**: No suspension, dashboard, impersonation or bypass removal. No rollback or backfill. No shipped migration edited. Super admin controllers call no tenant policy.

**Scale/Scope**: 17 new super admin routes plus 1 public route, 4 controllers, 3 services, 1 migration, 1 notification, 1 enum, 10 form requests, 1 new resource. About 10 existing application files change, and 6 docs are created or changed.

## Constitution Check

*GATE: Must pass before Phase 0 research. Re-check after Phase 1 design.*

| Principle | Result | Evidence |
| --- | --- | --- |
| I. PMS system of record | Pass | No operational data is changed. A restore brings back a hotel's own records |
| II. Preserve and Evolve | **Justified deviation** | Tenant `POST` and `DELETE /api/hotel` are removed without an alias. They worked only through the super admin bypass, and the capability moves rather than disappears. See [Complexity Tracking](#complexity-tracking) |
| III. Tenant Isolation | Pass | Account moves and memberships are checked so no user reaches across accounts. Soft-deleted hotels leave `accessibleHotelIds()`. Platform events have no hotel. Cross-tenant tests are required |
| IV. Permission-Based Authorization | Pass | The file-level `super_admin` guard is the explicit authorization path, the same as Stage 1. No `Permission` case is added, because these actions stay outside staff roles, like users and hotel settings. No policy is written with role checks |
| V. AI through tools | Pass | No agent or tool calls these routes |
| VI. Auditability | Pass | One platform event per write, with the actor recorded. Mail failures go to `report()`, not swallowed |
| Notifications SHOULD be asynchronous | **Justified deviation** | Password links are sent during the request, by clarification. See [Complexity Tracking](#complexity-tracking) |
| Internationalization: English and Arabic | **Recorded exception** | The first-password email is English-only, like `WelcomeNotification`, because users have no language field to choose by. Adding one is outside this stage |
| II. Reuse existing infrastructure | Pass | Service refusals use the existing `DomainRuleException` |
| VII. Data integrity | Pass | Multi-step writes run in a transaction. Retrying a create fails on the unique email or slug rather than duplicating |
| VIII. Tested at the boundary | Pass | Feature tests cover every endpoint, including CUJ 1 and CUJ 5 end to end |
| IX. Spec-driven delivery | Pass | Stage 2 of 6, shipped after Stage 1 |
| API Design: breaking changes documented | Pass | `docs/latest-changes-<date>.md` covers the removed routes and the refused role |
| Documentation | Pass | See [Documentation](#documentation) |
| Non-negotiable rule 22: new architecture justified | Pass | The second broker is justified in [research.md](research.md#r1-two-link-lifetimes) |

**Gate result**: Pass, with one justified deviation.

**Post-design re-check**: Pass, no change. The design added two refusal cases for hotel moves and one rule for pending links, all of which tighten isolation.

## Project Structure

### Documentation (this feature)

```text
specs/023-superadmin-administration/
├── spec.md
├── plan.md
├── plan-verification.md
├── research.md
├── data-model.md
├── quickstart.md
├── contracts/superadmin-administration.md
├── checklists/requirements.md
└── tasks.md             # /speckit-tasks, not created here
```

### Source Code (repository root)

```text
app/
├── Enums/GroupRole.php                                    # new
├── Http/
│   ├── Controllers/
│   │   ├── Auth/PasswordResetController.php               # new
│   │   ├── HotelController.php                            # remove store, destroy
│   │   ├── UserController.php                             # shared guards, FR-029
│   │   └── Superadmin/{Account,Hotel,User}Controller.php  # new
│   ├── Middleware/ResolveTenant.php                       # skip deleted home hotel
│   ├── Requests/
│   │   ├── ResetPasswordRequest.php                       # new
│   │   └── Superadmin/                                    # 9 new requests
│   └── Resources/
│       ├── UserResource.php                               # hotel_ids when loaded
│       └── Superadmin/AccountResource.php                 # new
├── Models/
│   ├── Hotel.php                                          # recount on restore
│   ├── HotelGroup.php                                     # $searchable, user count scope
│   └── User.php                                           # live home hotel, attachment scopes
├── Notifications/UserInvitationNotification.php           # new
├── Policies/HotelPolicy.php                               # remove create, delete, restore, forceDelete
├── Providers/AppServiceProvider.php                       # reset link address
├── Services/Superadmin/
│   ├── AccountAdministration.php                          # new
│   ├── HotelAdministration.php                            # new
│   └── UserAdministration.php                             # new
└── Support/Audit/EventLogger.php                          # recordPlatform()
config/{app,auth}.php, .env.example                        # frontend_url, invitations broker
database/migrations/<date>_000001_create_user_invitation_tokens_table.php
routes/{api,superadmin}.php
tests/Feature/Superadmin/{Account,Hotel,User}AdministrationTest.php   # new
tests/Feature/Superadmin/{PlatformAudit,AccessGuard}Test.php          # new
tests/Feature/PasswordResetTest.php                                   # new
tests/Feature/DeletedHotelAccessTest.php                              # new
tests/Feature/{HotelController,CreateUser,GenericCrud,OperationalDefaults}Test.php
tests/Inventory/entry_points.php
```

**Structure Decision**: Follows the existing Laravel layers. Rules with more than one caller or more than one step go in `app/Services/Superadmin`, and controllers stay thin as `CLAUDE.md` requires.

## Design Notes

Details and reasons are in [research.md](research.md). The fields and rules are in [data-model.md](data-model.md), and the API is in [contracts/superadmin-administration.md](contracts/superadmin-administration.md).

- **First-password links** use a second broker, `invitations`, with its own table and a 72-hour life. Other resets stay on `users` at 60 minutes. The public endpoint tries both.
- **Email failures** on a user create return `201` with `reset_email_sent: false`, and on a resend return `503`. Both report the error and delete the unused token, so an immediate retry isn't throttled.
- **Refusals** throw the existing `DomainRuleException`, which rolls back the transaction and renders through `apiResponse()`.
- **Tenant isolation** changes in one small place: `accessibleHotelIds()` and the current-hotel fallback in `ResolveTenant`.

## Corrections to the design

Beyond V1 to V13 in [plan-verification.md](plan-verification.md) and the spec's clarifications:

| Design says | This plan does | Why |
| --- | --- | --- |
| 2.2a `HotelProvisioning`; 2.1 and 2.2 write from the controllers | `AccountAdministration` and `HotelAdministration` | Controllers stay thin, and audit, moves and restores stay together |
| 2.2 `Superadmin\HotelResource` extends the shared one | Use the shared `HotelResource` | Stage 2 would add no fields to it. Stage 3 can add the subclass with its suspension fields |
| Nothing on filter values | `GenericIndexRequest` checks each filter value against the column's type, on every list endpoint | A malformed uuid, date or number would otherwise reach Postgres and return `500`. Found in code review, replacing a one-column fix |
| 2.4 `create` calls `sendResetLink` | `create` uses the `invitations` broker and `UserInvitationNotification` | The built-in email always states the default broker's 60 minutes, which would be wrong for a 72-hour link |
| 2.2 hotel moves unrestricted (V6) | Two further refusals found while designing | An owner whose home hotel is elsewhere, and a home-hotel user with memberships, would both end up linked across accounts |

## Documentation

- **Create**: `docs/superadmin-accounts-api-documentation.md`, `docs/latest-changes-<date>.md`
- **Modify**: `docs/hotel-api-documentation.md`, `docs/user-management-api-documentation.md`, `docs/auth-api-documentation.md`, `docs/whatsapp-device-api-documentation.md`
- **No change**: the permission reference in `docs/staff-roles-api-documentation.md`, because no permission is added. `/api/hotel` is already listed there as admin-only.
- **Frontend**: a handoff follows after the backend is done. It needs a reset page at `/reset-password?token=…&email=…`.

## Complexity Tracking

| Violation | Why Needed | Simpler Alternative Rejected Because |
|-----------|------------|-------------------------------------|
| Tenant hotel create and delete removed with no alias (Principle II) | The request moves these to the super admin API, and they only ever worked through a bypass that Stage 6 removes | Keeping an alias would leave two creation paths with different restore rules, one of which can move a customer's data to another account |
| Password links sent during the request, not queued (Notifications SHOULD be asynchronous) | Clarified requirement: the operator must learn immediately that a send failed, and can act on it | Queuing hides the failure until someone checks failed jobs, and puts the secret token in the queue payload. Volume is a few operator actions, so the wait doesn't matter |
| Second broker and token table | Clarified requirement: 72 hours for a first link, 60 minutes otherwise, and a normal link must never get the longer life | A single table and broker has one `expire` value. Adding a column would need a custom token repository, which is more code than one config entry and a table |
