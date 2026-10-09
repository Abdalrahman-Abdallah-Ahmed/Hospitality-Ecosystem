<?php

namespace App\Ai\Tools\Admin\Concerns;

use App\Exceptions\DomainRuleException;
use App\Models\Activity;
use App\Models\Booking;
use App\Models\Guest;
use App\Models\Hotel;
use App\Models\Reservation;
use App\Models\Room;
use App\Models\Task;
use App\Models\Team;
use App\Models\User;
use App\Support\PhoneNumber;
use Closure;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use RuntimeException;

/**
 * What the Admin AI tools share: finding records by what the model names,
 * always within the tool's own hotel (another hotel's record is simply "not
 * found", FR-003); running a domain operation so a refusal comes back as
 * the rule's own words with nothing changed (FR-010); and the result shapes
 * of contracts/admin-ai-tools.md.
 *
 * Expects a `private readonly Hotel $hotel` on the using tool.
 */
trait AdminToolSupport
{
    /**
     * Run a domain operation in its own savepoint. A business rule refusing
     * it rolls the savepoint back and comes back as a sentence; anything else
     * is a real error and is rethrown.
     *
     * @template T
     *
     * @param  Closure(): T  $operation
     * @return T|string
     */
    protected function attempt(Closure $operation): mixed
    {
        try {
            return DB::transaction($operation);
        } catch (ValidationException $e) {
            return $this->notDone(collect($e->errors())->flatten()->unique()->implode(' ') ?: $e->getMessage());
        } catch (DomainRuleException $e) {
            return $this->notDone($e->getMessage());
        } catch (RuntimeException $e) {
            // BookingService and BookingCancellationService refuse with a
            // plain RuntimeException, which the booking endpoints answer as a
            // 422. Only that exact class is a refusal: its subclasses (a
            // database error, a missing model, bad JSON) are failures and must
            // reach the exception handler.
            if ($e::class !== RuntimeException::class) {
                throw $e;
            }

            return $this->notDone($e->getMessage());
        }
    }

    protected function notDone(string $reason): string
    {
        return 'Not done: '.rtrim($reason, '. ').'. Nothing was changed.';
    }

    /**
     * @param  array<string, mixed>  $ids
     * @param  array<string, mixed>  $changed
     */
    protected function done(array $ids, array $changed, ?string $note = null): string
    {
        return json_encode(array_filter([
            'ok' => true,
            'ids' => $ids,
            'changed' => $changed,
            'note' => $note,
        ], fn ($value) => $value !== null), JSON_UNESCAPED_UNICODE);
    }

    protected function alreadyExists(string $id, string $what): string
    {
        return json_encode([
            'ok' => false,
            'exists' => ['id' => $id],
            'message' => "Already exists: {$what} ({$id}); nothing was created.",
        ], JSON_UNESCAPED_UNICODE);
    }

    /**
     * Several records match a name: list them and ask, never pick (FR-025).
     *
     * @param  Collection<int, array<string, mixed>>  $candidates
     */
    protected function ambiguous(string $what, Collection $candidates): string
    {
        return json_encode([
            'ok' => false,
            'ambiguous' => $what,
            'candidates' => $candidates->values()->all(),
            'message' => "More than one {$what} matches. Ask the admin which one; nothing was changed.",
        ], JSON_UNESCAPED_UNICODE);
    }

    protected function findReservationByCode(string $code): ?Reservation
    {
        $code = trim($code);

        return $code === '' ? null : Reservation::withoutGlobalScope('hotel')
            ->where('hotel_id', $this->hotel->id)
            ->where('reservation_id', $code)
            ->first();
    }

    protected function findRoomByNumber(string $number): ?Room
    {
        $number = trim($number);

        return $number === '' ? null : Room::withoutGlobalScope('hotel')
            ->where('hotel_id', $this->hotel->id)
            ->where('room_number', $number)
            ->first();
    }

    /**
     * A record of this hotel by id; null for an unknown id, a malformed one,
     * or another hotel's.
     *
     * @template TModel of \Illuminate\Database\Eloquent\Model
     *
     * @param  class-string<TModel>  $model
     * @return TModel|null
     */
    protected function findOwn(string $model, ?string $id): mixed
    {
        $id = trim((string) $id);

        if ($id === '' || ! Str::isUuid($id)) {
            return null;
        }

        return $model::withoutGlobalScope('hotel')->where('hotel_id', $this->hotel->id)->find($id);
    }

    /**
     * A guest by id or phone number. Phone matching compares digits only,
     * the way guest identity does.
     *
     * @return Guest|Collection<int, Guest>|null a guest, several to choose from, or none
     */
    protected function findGuest(?string $idOrPhone): Guest|Collection|null
    {
        $value = trim((string) $idOrPhone);

        if ($value === '') {
            return null;
        }

        if (Str::isUuid($value)) {
            return $this->findOwn(Guest::class, $value);
        }

        $digits = PhoneNumber::digits($value);

        if ($digits === null) {
            return null;
        }

        $matches = Guest::withoutGlobalScope('hotel')
            ->where('hotel_id', $this->hotel->id)
            ->whereRaw(PhoneNumber::DIGITS_SQL.' = ?', [$digits])
            ->get();

        return $this->oneOf($matches);
    }

    protected function findTask(?string $id): ?Task
    {
        return $this->findOwn(Task::class, $id);
    }

    protected function findBooking(?string $idOrReference): ?Booking
    {
        $value = trim((string) $idOrReference);

        if ($value === '') {
            return null;
        }

        return Str::isUuid($value)
            ? $this->findOwn(Booking::class, $value)
            : Booking::withoutGlobalScope('hotel')->where('hotel_id', $this->hotel->id)->where('reference', $value)->first();
    }

    /**
     * @return Activity|Collection<int, Activity>|null
     */
    protected function findActivity(?string $idOrName): Activity|Collection|null
    {
        $value = trim((string) $idOrName);

        if ($value === '') {
            return null;
        }

        if (Str::isUuid($value)) {
            return $this->findOwn(Activity::class, $value);
        }

        $matches = Activity::withoutGlobalScope('hotel')
            ->where('hotel_id', $this->hotel->id)
            ->whereRaw('lower(name) = ?', [mb_strtolower($value)])
            ->get();

        return $this->oneOf($matches);
    }

    /**
     * A staff member of this hotel by id or (part of) their name.
     *
     * @return User|Collection<int, User>|null
     */
    protected function findStaff(?string $idOrName): User|Collection|null
    {
        $value = trim((string) $idOrName);

        if ($value === '') {
            return null;
        }

        $staff = User::query()->where('hotel_id', $this->hotel->id);

        if (Str::isUuid($value)) {
            return $staff->find($value);
        }

        $matches = $staff->where('name', 'ilike', '%'.addcslashes($value, '%_\\').'%')->get();

        return $this->oneOf($matches, fn (User $user) => mb_strtolower($user->name) === mb_strtolower($value));
    }

    /**
     * @return Team|Collection<int, Team>|null
     */
    protected function findTeam(?string $idOrName): Team|Collection|null
    {
        $value = trim((string) $idOrName);

        if ($value === '') {
            return null;
        }

        if (Str::isUuid($value)) {
            return $this->findOwn(Team::class, $value);
        }

        $matches = Team::withoutGlobalScope('hotel')
            ->where('hotel_id', $this->hotel->id)
            ->whereRaw('lower(name) = ?', [mb_strtolower($value)])
            ->get();

        return $this->oneOf($matches);
    }

    /**
     * The record a lookup found: none, the only match, or — among several —
     * the one exact match (a staff member called "Ali" next to "Alice").
     * Otherwise all of them, for the advisor to ask which one (FR-025).
     *
     * @template TModel of \Illuminate\Database\Eloquent\Model
     *
     * @param  Collection<int, TModel>  $matches
     * @param  (Closure(TModel): bool)|null  $isExact
     * @return TModel|Collection<int, TModel>|null
     */
    protected function oneOf(Collection $matches, ?Closure $isExact = null): mixed
    {
        if ($matches->count() <= 1) {
            return $matches->first();
        }

        $exact = $isExact ? $matches->filter($isExact) : collect();

        return $exact->count() === 1 ? $exact->first() : $matches;
    }

    protected function guestName(?Guest $guest): string
    {
        return $guest ? (trim($guest->first_name.' '.$guest->last_name) ?: 'unnamed guest') : 'no guest';
    }

    /**
     * The hotel's "today", which is what "today" and "tomorrow" mean to the
     * admin (R6).
     */
    protected function hotelToday(): string
    {
        return now($this->hotel->timezone ?: config('app.timezone'))->toDateString();
    }

    /**
     * A validation rule: the date is not before the hotel's today, as the
     * out-of-order screen checks it.
     */
    protected function notPastInHotel(): Closure
    {
        return function (string $attribute, mixed $value, Closure $fail): void {
            if ($value !== null && $value < $this->hotelToday()) {
                $fail('The expected end date cannot be in the past.');
            }
        };
    }
}
