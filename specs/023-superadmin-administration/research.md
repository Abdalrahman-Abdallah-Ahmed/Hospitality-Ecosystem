# Research: Super Admin Account, Hotel and User Administration

The Technical Context has no open unknowns. These decisions settle the design questions the spec leaves to planning. V numbers refer to [plan-verification.md](plan-verification.md).

## R1: Two link lifetimes

**Decision**: Add a second broker, `invitations`, in `config/auth.php`, with its own table `user_invitation_tokens`, `expire` of 4320 minutes and `throttle` of 60. New users get a token from it. All other resets use the existing `users` broker.

`POST /api/reset-password` calls `reset` on `users`. If that doesn't succeed, it calls `reset` on `invitations`, whatever the first failure was. Trying the second broker even for an unknown email keeps timing the same, so the response time doesn't reveal whether the email exists (FR-034).

On success, the password is written and `clearAccess` runs inside the broker's callback, and the token in the other broker is deleted too.

**Rationale**: A broker's repository has one `expire` per table, and `tokenExpired` reads only `created_at`. Separate tables mean a normal reset token can't be validated against 72 hours, because the `invitations` broker never sees it.

**Alternatives**: An `expires_at` column needs a custom `TokenRepositoryInterface`. One broker sharing a table with two `expire` values would let a normal token pass the longer check. A signed URL holds no row to invalidate on use.

**Consequences handled**:

- `sendPasswordReset` checks `recentlyCreatedToken` on `invitations` as well, or a resend right after a create wouldn't be throttled (FR-031).
- `sendPasswordReset` deletes any pending invitation token after a successful send, so only the newest link works, and a failed send doesn't cost the user a link that still works.
- `auth:clear-resets` isn't scheduled today, so expired rows stay in both tables. They can't be used, and each email has at most one row per table, so this is left alone.

## R2: Sending email and reporting outcomes

**Decision**: Both emails are sent during the request.

- `UserInvitationNotification` extends `ResetPassword`, inherits the `createUrlUsing` address, and states the 72-hour life. It isn't queued.
- The user is created and committed first, along with ownership and the audit event. The email is sent after the transaction.
- On a create, a failure calls `report($e)`, deletes the unused token so an immediate retry isn't throttled, and returns `reset_email_sent: false`.
- On a resend, the event, the removal of the pending first link and the send all run in one transaction, with the event first. A failure rolls back all of it, so links the user already holds keep working and no email goes out if the event can't be written. It is reported once, and the controller answers `503`, because a thrown `DomainRuleException` would be reported a second time. The transaction stays open during the send, which is acceptable at this volume.
- `platform.user.password_reset_sent` is recorded only after a successful send.

**Rationale**: Clarified in the spec. A failed token left in place would make the operator's immediate retry return `429`.

**Alternatives**: Rejected in clarification: queuing, like `WelcomeNotification`.

## R3: Refusals and transactions

**Decision**: Services throw the existing `App\Exceptions\DomainRuleException($message, $status)`, whose `render()` already returns `apiResponse($message, $status)`. No new exception class.

**Rationale**: Throwing rolls back the transaction, so a refused step in `changeAccess` leaves nothing half-written, and controllers stay thin. Principle II requires reusing this class, which exists for exactly this purpose.

**Tenant controller**: `refusesSuperAdminGrant()` and `staffRoleError()` return plain values, as the design says, and `UserController` builds its own `apiResponse`.

## R4: Soft-deleted hotels leave reach (FR-015a)

**Decision**:

- In `User::accessibleHotelIds()`, add the home hotel only when it is live. The group branch and the `hotels()` relation already exclude deleted hotels through the model's soft-delete scope.
- In `ResolveTenant`, use `hotel_id` as the fallback current hotel only when it is in `$available`.

**Rationale**: These are the only two direct readers of a user's `hotel_id` for tenant reach, confirmed by searching `app/`. `$user->hotel` already returns null for a deleted hotel. Super admins keep `null`, and users of live hotels see no change.

**Scope**: `hotel_user` rows and tokens aren't touched, so a restore returns access. WhatsApp and jobs don't go through these paths and aren't changed.

## R5: List endpoints

**Decision**: Two subclasses of `GenericIndexRequest`, which itself checks each filter value against the column's type.

| Request | Filter and sort columns | Extra rules |
| --- | --- | --- |
| `AccountIndexRequest` | `id`, `name`, `slug`, `country_code`, `default_currency`, `default_timezone`, `created_at`, `updated_at` | Overrides `modelClass()` |
| `UserIndexRequest` | All user columns except `password` and `remember_token`, as an allowlist | |

The hotel list uses `GenericIndexRequest` directly.

`HotelGroup` gets `$searchable = ['name', 'slug']`, so search never reads `contract_currency`. Nothing else searches accounts.

`Superadmin\UserController@index` applies `User::scopeAttachedToAccount` and `scopeAttachedToHotel`, then removes those two keys with `$request->merge()` before `GenericQuery::apply()`. Super admins are excluded in the base query.

**Rationale**: An allowlist keeps the text `contract_value` out of `app/Http/Requests`, which `CostVisibilityTest` scans (V2). Without the type check, a malformed filter reaches Postgres and returns `500`.

**User count**: `HotelGroup::scopeWithUserCount` adds a single `selectSub`. It counts rows from `users` with no join, so each user counts once: not a super admin, and either `hotel_group_id` matches, or `hotel_id` is in a live hotel of the account, or a `hotel_user` row is. The metering seat count intentionally differs, because it ignores memberships.

## R6: Slug rules (V4)

| Where | Rule |
| --- | --- |
| Hotel create | `Rule::unique('hotels', 'slug')->withoutTrashed()`, so a deleted slug reaches the restore path |
| Hotel edit | `Rule::unique('hotels', 'slug')->ignore($hotel)`, which includes deleted rows |
| Account create, edit | `Rule::unique('hotel_groups', 'slug')`, with `ignore` on edit, which includes deleted rows |

Restoring applies only the fields sent. The account fallback for currency and timezone (V12) applies only to a new hotel, so a restore doesn't overwrite values it wasn't sent.

## R7: Moving a hotel (V6)

**Decision**: `HotelAdministration::update` returns `422` when `hotel_group_id` changes and any of these holds:

1. the hotel has `hotel_user` rows;
2. a user with `hotel_id` on this hotel has `hotel_group_id` set;
3. a user with `hotel_id` on this hotel has `hotel_user` rows in another hotel;
4. the hotel's owner has a different home hotel.

**Rationale**: Each would leave someone linked across accounts. Cases 3 and 4 came up while designing this: in case 3 the user's account would follow the hotel and leave their other memberships outside it, and in case 4 `HotelPolicy` would still let the old owner edit another customer's hotel.

Users attached only by `hotel_id` follow the hotel through `User::resolveAccount`, and the existing `updated` hook recounts both accounts.

## R8: Ownership (V10)

**Decision**: Ownership is set only on the user's home hotel. This happens on create and when `changeAccess` makes them an admin, using a conditional `whereNull('owner_id')` update so an existing owner is never overwritten. Demotion clears `owner_id` on every hotel they own, including soft-deleted ones, so a later restore isn't stuck with an employee as owner.

**Rationale**: Memberships don't grant ownership, because ownership gives rights over hotel settings and the spec only ties it to the admin's hotel. So an admin with a membership can read that hotel's data but can't open its settings unless they own it, which is the same as today.

## R9: `group_role`

**Decision**: Add `App\Enums\GroupRole` (`owner`, `group_admin`, the only values the code and tests use) and validate with `Rule::enum`. Do **not** cast the column on `User`.

**Rationale**: A super admin can still write any string through the tenant `PUT /api/users` until Stage 6, and a cast would make that user unreadable. The tenant path is left alone (R12).

## R10: Scope and authorization

**Decision**: `Hotel`, `HotelGroup` and `User` have no `BelongsToHotel`, so reading them needs no escape hatch. Methods touching `StaffRole`, `Team`, `WhatsAppDevice` or the hotel's default teams run in `TenantContext::withoutScope()`, as the design asks, so they keep working if the caller's scope changes. That includes loading `team` and `staffRole` for user responses. No tenant policy is called.

The inventory needs no new entries: `crossTenantRuleFor` covers anything with the `super_admin` middleware, and `/reset-password` isn't a tenant route.

## R11: Audit content

**Decision**: `recordPlatform(string $eventType, Model $subject, ?array $changes, ?string $reason, array $context)` writes inside `runForHotel(null, ...)`, with the same actor logic as `record()`. `changes` holds from/to values of the public fields only.

- A write that changes nothing records no event, because no write happened.
- A restore records `platform.hotel.created` with `context.restored = true`.
- `revokeTokens` always records an event, even with nothing to revoke, because the operator's action happened (spec edge case).

## R12: Tenant paths left alone

The tenant `PUT /api/users` isn't changed by FR-023 or FR-026:

- A tenant admin demoting a colleague leaves `group_role` and `owner_id` as they were.
- A super admin can still set any `group_role` on a non-super-admin there, until Stage 6.

- A super admin can still delete another super admin, or themselves, through `DELETE /api/users/{user}`.

**Rationale**: The spec applies these rules to the super admin endpoints, and fixing the tenant path is outside this stage. The tenant changes are the grant, link and demotion refusals (FR-028, FR-029), and dropping `owner_id` and `hotel_group_id` from hotel edits (FR-015).

## R13: Testing mail failures

`phpunit.xml` uses the `array` mailer. Tests force a failure with `Notification::shouldReceive('send')->andThrow(...)`, and read the emailed token from `Notification::fake()` to drive the reset endpoint.
