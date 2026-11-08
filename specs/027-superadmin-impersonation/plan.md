# Implementation Plan: Super Admin Impersonation

**Branch**: `027-superadmin-impersonation` | **Date**: 2026-11-08 | **Spec**: [spec.md](spec.md)

**Input**: Feature specification from `specs/027-superadmin-impersonation/spec.md`

## Summary

Let a super admin act as a hotel user for up to 30 minutes. A session is a Sanctum token issued to the target user, with one new `impersonator_id` column marking it. That keeps the user's permissions and hotels working unchanged. One helper recognises such a token, and the audit writer, Sentry tagging, a new `not_impersonating` middleware, `GET /api/user` and logout all use it. A session can't gain lasting access, and it can read a suspended hotel but not write to it. Hotel readers see only the super admin's id and name.

The source design is Stage 5 of [docs/designs/super-admin-platform.md](../../docs/designs/super-admin-platform.md). This plan follows it except where [plan-verification.md](plan-verification.md) and the spec's clarifications overrule it. See [Corrections to the design](#corrections-to-the-design).

## Technical Context

**Language/Version**: PHP 8.3, Laravel 13

**Primary Dependencies**: Sanctum and the Sentry SDK, both already in use. Pest 4. Nothing new.

**Storage**: PostgreSQL + pgvector. One nullable column on `personal_access_tokens`. See [data-model.md](data-model.md).

**Testing**: Pest feature tests on real Postgres, run with `php artisan test --parallel`, using real Bearer tokens rather than `Sanctum::actingAs`.

**Target Platform**: Linux server. One new scheduled command, and no new setting or worker.

**Project Type**: JSON API web service

**Performance Goals**: A normal request adds no query, because the helper only reads the already loaded token. A session's request adds one lookup for the super admin, however many events it writes. The new column has an index, so the cascade on deleting a user doesn't scan the table.

**Constraints**: The token lasts 30 minutes and can't be extended. Queued jobs aren't attributed to the super admin (R8). `HotelAccess`, `UseChosenHotel`, `UserPolicy`, `UserResource` and `revoke-tokens` don't change. The bypasses stay until stage 6. No shipped migration is edited.

**Scale/Scope**: 3 routes, 1 controller, 1 form request, 1 service, 1 support helper, 1 middleware, 1 model trait on 5 models, 1 migration, 1 test file, 1 new API doc plus edits to 7.

## Constitution Check

*GATE: Must pass before Phase 0 research. Re-check after Phase 1 design.*

| Principle | Result | Evidence |
| --- | --- | --- |
| I. PMS system of record | Pass | No operational data changes shape |
| II. Preserve and Evolve | Pass | Reuses Sanctum tokens, `ImpersonatedRead`, `TagsSentryScope`, `recordPlatform()` and the priority list. Design Alternative H records why a token beats a header |
| III. Tenant Isolation | Pass | The token belongs to the target, so `ResolveTenant`, `not_suspended` and `hotel_choice` scope it unchanged. Tests prove another hotel's record is `404`, and start and end events never reach a hotel |
| IV. Permission-Based Authorization | Pass | The token carries the user's permissions, and policies run unchanged. Super admin routes use the file-level guard. No enum case is added, because these are platform actions with no hotel role, as for the rest of `routes/superadmin.php` |
| V. AI through tools | Pass | AI tools aren't changed. An advisor write during a session stays `ai_agent`, and now names the super admin |
| VI. Auditability | **Justified deviation** | Events for start and end, every audited write in the request, and the five previously unaudited record types during a session. Writes by jobs a session starts name the hotel user (accepted risk R8). See [Complexity Tracking](#complexity-tracking) |
| VII. Data integrity | Pass | Start and every ending run in a transaction. An ending records its event only if it removed the row, so at most one per session |
| VIII. Tested at the boundary | Pass | Every acceptance scenario gets a feature test, including tenant isolation, plus additions to `AccessGuardTest` and the inventory |
| IX. Spec-driven delivery | Pass | Stage 5 of 6, before the bypasses go |
| Rule 15: AI writes audited | Pass | AI tool writes stay audited, and during a session they also name the super admin |
| Rule 20: existing functionality preserved | Pass | Every non-session token behaves as before, except that a hotel reader sees less about a super admin actor |
| Internationalization: English and Arabic | **Justified deviation** | API messages are English only. See [Complexity Tracking](#complexity-tracking) |
| Security: least privilege | Pass | A session can't change credentials, users or staff roles, or pair a device, and the token isn't stored or logged |
| API Design: `apiResponse()` and Resources | **Justified deviation** | Uses `apiResponse()` with plain arrays and no Resource. See [Complexity Tracking](#complexity-tracking) |
| Documentation | Pass | See [Documentation](#documentation) |

**Gate result**: Pass, with three justified deviations.

**Post-design re-check**: Pass, no change. The model trait added for FR-019b does nothing outside a session, so no new audit rows reach normal traffic.

## Project Structure

### Documentation (this feature)

```text
specs/027-superadmin-impersonation/
├── spec.md
├── plan.md
├── plan-verification.md
├── research.md
├── data-model.md
├── quickstart.md
├── contracts/
│   └── impersonation.md
├── checklists/
│   └── requirements.md
└── tasks.md              # /speckit-tasks
```

### Source Code (repository root)

```text
app/
├── Http/
│   ├── Controllers/
│   │   ├── Auth/AuthenticatedSessionController.php  # apiLogout records the end event
│   │   └── Superadmin/
│   │       └── ImpersonationController.php          # new: start, end, endAllFor
│   ├── Middleware/
│   │   ├── EnsureNotImpersonating.php               # new
│   │   └── ResolveTenant.php                        # two more Sentry tags
│   ├── Requests/Superadmin/
│   │   └── EndImpersonationRequest.php              # new
│   └── Resources/EventLogResource.php               # trims a super admin actor
├── Models/
│   ├── Concerns/RecordsImpersonatedWrites.php       # new
│   └── Hotel, Team, TaskCategory, ActivityCategory, HotelPolicy  # add the trait
├── Services/Superadmin/Impersonation.php            # new
└── Support/
    ├── Audit/EventLogger.php                        # the swap, in write()
    ├── Impersonation/CurrentImpersonation.php       # new
    └── Suspension/ImpersonatedRead.php              # asks the helper

bootstrap/app.php                                    # alias and priority
database/migrations/2026_11_08_000001_add_impersonator_to_personal_access_tokens.php  # new
routes/api.php                                       # not_impersonating, GET /user
routes/console.php                                   # hourly prune
routes/superadmin.php                                # 3 POST routes

tests/
├── Feature/Superadmin/
│   ├── ImpersonationTest.php                        # new
│   └── AccessGuardTest.php                          # 3 rows in the routes dataset
└── Inventory/entry_points.php                       # the scheduled command
```

**Structure Decision**: Follows the existing `Superadmin` controller, request and service folders.

## Design

Details and reasons are in [research.md](research.md). In short:

1. **Migration** adds `impersonator_id` (data-model).
2. **`CurrentImpersonation`** gives `token()` and `impersonator()`, and every caller goes through it (R1, R2).
3. **`EventLogger::write()`** swaps the actor and adds `impersonated_user_id`, so `record()` and `recordPlatform()` both get it (R3).
4. **`Impersonation`** has `start`, `end`, `endAllFor` and `endCurrent`, and all endings share one exactly-once path (R4, R5). It records start and end events with explicit context, including `token_id` and, on start, `expires_at`.
5. **`ImpersonationController`** handles start, end by id and the per-user ending.
6. **`apiLogout`** deletes the token as now, and uses the shared path for an impersonation token.
7. **`EnsureNotImpersonating`** goes on the four refused route groups, and runs before binding (R6).
8. **`GET /user`** adds `impersonation` in the route closure only, by spreading the resolved `UserResource`, as `Superadmin\UserController::store` does.
9. **`RecordsImpersonatedWrites`** records session writes to the five record types (R7).
10. **`EventLogResource`** trims a super admin actor for other readers (R8).
11. **`ResolveTenant`** sets or clears the Sentry tags (R9).
12. **`routes/console.php`** schedules the prune, and the inventory lists it (R10).

## Corrections to the design

From [plan-verification.md](plan-verification.md) and clarification.

| Design | This plan | Source |
| --- | --- | --- |
| Swap in `record()` and `recordPlatform()` | Once, in `write()` | V1, R3 |
| `current()` on the service | A support helper, because `EventLogger` needs it too | R1 |
| `User::find` per event | One lookup per request, held on the token | R2 |
| Refuses `/connect`, `/users`, `/staff-roles` | Also `POST /team/{team}/members` | V5, clarified |
| Every write already audited gets the swap, nothing else | Also session writes to five unaudited record types, by field name | V6, clarified |
| The super admin is simply the actor | Hotel readers see only id and name, with no IP | V7, clarified |
| End finds rows with an `impersonator_id` | Also unexpired, with a live target; otherwise `404` | V8, V9, R4 |
| Start event stores `expires_at` | Both events store `token_id` too | V10, clarified |
| Any super admin ends a session by id | Also `POST /users/{user}/end-impersonation`, per user | V10, clarified |
| Sentry tags "also added" when impersonating | Always passed, with null clearing them | V13, R9 |
| "A `PUT` on A's record is refused" | `404` for a hotel-owned record, suspended `403` for the hotel's own | V3 |

## Documentation

- **New**: `docs/superadmin-impersonation-api-documentation.md`. It covers the three endpoints, the events, the limits, what `expires_at` does and doesn't prove, and sending `X-Hotel-Id` throughout a session (FR-030).
- **Changed**:
  - `docs/auth-api-documentation.md`: the `impersonation` key and the logout event.
  - `docs/whatsapp-device-api-documentation.md`: `/connect` is refused.
  - `docs/user-management-api-documentation.md`: writes are refused.
  - `docs/staff-roles-api-documentation.md`: writes are refused. The permission reference doesn't change.
  - `docs/history-api-documentation.md`: the trimmed actor.
  - `docs/hotel-api-documentation.md`, for the hotel settings event.
  - `docs/task-management-api-documentation.md`: `POST /api/team/{id}/members` is refused.
- **`docs/latest-changes-2026-11-08.md`**: lists what the frontend needs, including the trimmed history actor, which changes an existing response (FR-031).
- The design doc isn't edited. This plan and `plan-verification.md` record where the work departs from it.

## Complexity Tracking

| Violation | Why Needed | Simpler Alternative Rejected Because |
| --- | --- | --- |
| Principle VI: a job a session starts records the hotel user as actor (R8), for example `PrepareReservationImportJob` running as the uploader | Accepted in the design and the request. The job runs later with no token, and the start event shows who held the session at that time | Carrying the impersonator into every queued job touches each job's payload and is out of scope for this stage |
| No Resource class for the start, end and per-user responses | There's no model to present: the bodies are a token plus two fields, a count, or null. `apiLogin` and `connect` already return tokens this way | A Resource over a plain array would add a class and change nothing in the response |
| API messages in English only | Only the platform operator reads the three new routes. The one refusal message a hotel-scoped route gains is read by that same operator, because only a session can trigger it | Translating a few messages would leave the rest of the super admin API in English, and the frontend renders its own text |
