# Test Accounts

Logins at every access level, for trying the app by hand. Local and testing only: the seeder refuses to run anywhere else, because they all share a well-known password.

```bash
php artisan migrate
php artisan db:seed --class=AccessLevelSeeder
```

It's safe to run again. It restores these users' roles, hotels and passwords, and leaves anything else alone. It prints this list when it finishes.

**Password for all of them:** `123123123`. For `admin@hospitality.test` it's `SEED_SUPER_ADMIN_PASSWORD` instead, if that's set.

## Platform

| Email | Access |
| --- | --- |
| `admin@hospitality.test` | Super admin |
| `second.superadmin@hospitality.test` | Super admin, for ending another super admin's session |
| `unassigned.admin@hospitality.test` | Admin with no hotel or account |

## Nile Resorts: active, three hotels

| Email | Access | Hotels |
| --- | --- | --- |
| `owner@nile.test` | Account owner | Cairo, Luxor, Aswan |
| `admin.multi@nile.test` | Admin | Cairo (default), Luxor |
| `admin.single@nile.test` | Admin, with a paired WhatsApp device | Aswan |
| `frontdesk@nile.test` | Employee, Front Desk role | Cairo |
| `frontdesk.multi@nile.test` | Employee, Front Desk role | Cairo and Luxor at once |
| `housekeeping@nile.test` | Employee, Housekeeping role and team | Cairo |
| `readonly@nile.test` | Employee, view-only role | Cairo |
| `employee.norole@nile.test` | Employee with no role, default permissions | Cairo |

## Red Sea Hotels: active, one hotel suspended

Hurghada is suspended and Dahab is active.

| Email | Access | What to expect |
| --- | --- | --- |
| `owner@redsea.test` | Account owner | Works in Dahab |
| `admin@redsea.test` | Admin, default hotel Hurghada | Login works, and moves to Dahab |
| `employee@redsea.test` | Employee, default hotel Hurghada | Login works, and moves to Dahab |

## Suspended customers

Their logins are refused, so reach them by impersonating them.

| Email | Access | What to expect |
| --- | --- | --- |
| `owner@delta.test` | Owner of Delta Stays, a suspended account | `403`, scope `account` |
| `employee@delta.test` | Employee of Delta Stays | `403`, scope `account` |
| `admin@oasis.test` | Admin of Oasis Inn, whose only hotel is suspended | `403`, scope `hotel` |

## Good for testing impersonation

Log in as a super admin and open the user list.

| Act as | To check |
| --- | --- |
| `owner@nile.test` | Choosing any hotel of the account. Refused writes to users and staff roles |
| `frontdesk@nile.test` | A staff role's permissions applying |
| `admin.single@nile.test` | WhatsApp status showing, with no connect or disconnect action |
| `admin@redsea.test` | A suspended default hotel: reads Hurghada, and can write only in Dahab |
| `owner@delta.test` | A suspended account: reads work, and writes get the suspended `403` |

Every hotel has two rooms, a guest and an upcoming reservation.
