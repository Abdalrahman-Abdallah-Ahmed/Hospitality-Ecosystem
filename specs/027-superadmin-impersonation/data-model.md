# Data Model: Super Admin Impersonation

One new column and no new table. A session is a token row, and its history is event rows.

## `personal_access_tokens` (changed)

Migration: `database/migrations/2026_11_08_000001_add_impersonator_to_personal_access_tokens.php`.

| Column | Type | Rule |
| --- | --- | --- |
| `impersonator_id` | uuid, nullable, indexed | Foreign key to `users.id`, cascade on delete. Null on every token except a session's |

Existing columns as a session uses them:

| Column | Value on a session's token |
| --- | --- |
| `id` | The `token_id` in the API. A bigint |
| `tokenable_type`, `tokenable_id` | The target user. No foreign key, so a deleted user's row stays until pruned |
| `name` | `impersonation`. For reading only: `impersonator_id` decides whether a token is a session |
| `abilities` | `["*"]` |
| `expires_at` | 30 minutes after issue. Never changed after that |

`impersonator_id` stays out of Sanctum's fillable list, and is set with `forceFill` in the same transaction as the insert.

## Session states

| State | Definition | Token | Super admin end endpoints |
| --- | --- | --- | --- |
| Running | `impersonator_id` set, `expires_at` in the future, target exists | Works | Ends it, with one event |
| Expired, not pruned | `expires_at` passed, row still there | `401` | `404` by id, not counted per user, no event |
| Orphaned | The target user was deleted, row still there | `401` | `404`, no event |
| Gone | Row removed | `401` | `404` |

| Transition | Trigger | End event |
| --- | --- | --- |
| Running → Gone | End endpoint, `end-impersonation`, logout | Yes, exactly one |
| Running → Gone | `revoke-tokens`, public password reset, deleting the super admin (cascade) | No |
| Running → Expired | 30 minutes pass | No |
| Running → Orphaned | The target user is deleted | No |
| Expired or Orphaned → Gone | Hourly prune, one hour after expiry | No |

## `event_log` rows (no schema change)

### Session events

| Field | `platform.impersonation.started` | `platform.impersonation.ended` |
| --- | --- | --- |
| `hotel_id` | null | null |
| `subject` | Target user | Target user |
| `actor` | The super admin who started it | The super admin who ended it. On logout, the session's super admin |
| `actor_kind` | `user` | `user` |
| `context.impersonated_user_id` | Target user's id | Target user's id |
| `context.token_id` | Token id | Token id |
| `context.expires_at` | ISO 8601 | Not set |

The token itself never appears.

### A write during a session

| Field | Value |
| --- | --- |
| `hotel_id`, `subject`, `changes`, `event_type` | As without a session |
| `actor` | The session's super admin |
| `actor_kind` | `user`, or `ai_agent` for an AI write |
| `context.impersonated_user_id` | Target user's id |

### A write to an unaudited record type during a session (FR-019b)

| Field | Value |
| --- | --- |
| `event_type` | `hotel.updated`, or `team`, `task_category`, `activity_category`, `hotel_policy` with `.created`, `.updated`, `.deleted` |
| `hotel_id` | The record's hotel |
| `subject` | The record |
| `changes` | null, so no values are stored |
| `context.changed_fields` | Attribute names, without `id` and timestamps. Empty on delete |
| `actor`, `actor_kind`, `context.impersonated_user_id` | As above |

No stored row is different for FR-019c: trimming happens when a row is read.

## Models

| Model | Change |
| --- | --- |
| `Hotel`, `Team`, `TaskCategory`, `ActivityCategory`, `HotelPolicy` | Add the `RecordsImpersonatedWrites` trait. No column change |
| `User` | None. Tokens and the cascade need no relation |
