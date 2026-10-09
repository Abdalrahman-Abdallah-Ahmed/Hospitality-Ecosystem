<?php

namespace App\Ai\Tools;

use App\Ai\Tools\Admin\Concerns\AdminToolSupport;
use App\Enums\BookingStatus;
use App\Enums\ChargeModel;
use App\Models\Activity;
use App\Models\Booking;
use App\Models\Guest;
use App\Models\Hotel;
use App\Models\Stay;
use App\Models\User;
use App\Services\BookingService;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Support\Collection;
use Laravel\Ai\Contracts\Tool;
use Laravel\Ai\Tools\Request;
use Stringable;

/**
 * Books an activity for a guest the way the desk does
 * (BookingService::takeStaffBooking): same hotel checks, same capacity and
 * opening-hours rules, origin "staff". It never overrides capacity, whoever
 * the admin is (FR-011). A second booking of the same guest for the same
 * activity on the same day is reported, not made (R9).
 *
 * Separate from the Concierge's CreateBookingTool, which books for the guest
 * talking to it and is unchanged.
 */
class CreateActivityBookingTool implements Tool
{
    use AdminToolSupport;

    public function __construct(
        private readonly Hotel $hotel,
        private readonly User $user,
    ) {}

    public function description(): Stringable|string
    {
        return 'Book an activity for a guest: the guest (id or phone), the activity (id or exact name), when '
            .'("YYYY-MM-DD HH:MM" in hotel time, or just a date for a day activity), and how many people. Optionally the '
            .'reservation code it belongs to and how it is paid. If the activity is full or closed then, nothing is booked '
            .'and the reason comes back; capacity is never overridden.';
    }

    public function handle(Request $request): Stringable|string
    {
        $guest = $this->findGuest($request->string('guest')->toString());

        if ($guest === null) {
            return 'This hotel has no guest with that id or phone number.';
        }

        if ($guest instanceof Collection) {
            return $this->ambiguous('guest', $guest->map(fn (Guest $match) => ['id' => $match->id, 'name' => $this->guestName($match)]));
        }

        $activity = $this->findActivity($request->string('activity')->toString());

        if ($activity === null) {
            return 'This hotel has no activity by that id or name.';
        }

        if ($activity instanceof Collection) {
            return $this->ambiguous('activity', $activity->map(fn (Activity $match) => ['id' => $match->id, 'name' => $match->name]));
        }

        $when = trim($request->string('scheduled_for')->toString());

        if ($when === '') {
            return 'When should it be booked? A date (and a time, for a timed activity) is required.';
        }

        $reservationId = null;

        if ($request->filled('reservation_code')) {
            $reservation = $this->findReservationByCode($request->string('reservation_code')->toString());

            if (! $reservation) {
                return 'This hotel has no reservation with that code.';
            }

            $reservationId = $reservation->id;
        }

        $date = substr($when, 0, 10);

        if ($existing = $this->openDuplicate($guest, $activity, $date)) {
            return $this->alreadyExists($existing->id, "booking {$existing->reference} for {$activity->name} on {$date}");
        }

        $isDateOnly = strlen($when) === 10;

        $booking = $this->attempt(fn () => app(BookingService::class)->takeStaffBooking($this->hotel, $this->user, [
            'guest_id' => $guest->id,
            'activity_id' => $activity->id,
            'reservation_id' => $reservationId,
            'stay_id' => $reservationId ? null : $this->currentStayId($guest),
            'scheduled_for' => $isDateOnly ? null : $when,
            'scheduled_date' => $isDateOnly ? $when : null,
            'pax' => max(1, $request->integer('pax') ?: 1),
            'notes' => $request->string('notes')->toString() ?: null,
            'charge_model' => $request->enum('charge_model', ChargeModel::class, ChargeModel::PAY_ON_SITE)->value,
            'channel' => 'desk',
        ]));

        if (is_string($booking)) {
            return $booking;
        }

        return $this->done(
            ['booking_id' => $booking->id, 'reference' => $booking->reference],
            ['booking' => 'created', 'activity' => $activity->name, 'guest' => $this->guestName($guest), 'pax' => $booking->pax, 'status' => $booking->status->value],
        );
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'guest' => $schema->string()->description("The guest's id or phone number.")->required(),
            'activity' => $schema->string()->description("The activity's id or exact name.")->required(),
            'scheduled_for' => $schema->string()->description('"YYYY-MM-DD HH:MM" in hotel time, or "YYYY-MM-DD" for a day activity.')->required(),
            'pax' => $schema->integer()->description('How many people. Default 1.'),
            'reservation_code' => $schema->string()->description('The reservation this booking belongs to, if the admin said.'),
            'charge_model' => $schema->string()->enum(ChargeModel::class)->description('How it is paid. Default pay_on_site.'),
            'notes' => $schema->string(),
        ];
    }

    private function openDuplicate(Guest $guest, Activity $activity, string $date): ?Booking
    {
        return Booking::withoutGlobalScope('hotel')
            ->where('hotel_id', $this->hotel->id)
            ->where('guest_id', $guest->id)
            ->where('activity_id', $activity->id)
            ->whereDate('scheduled_date', $date)
            ->whereNotIn('status', [BookingStatus::CANCELLED, BookingStatus::NO_SHOW])
            ->first();
    }

    /**
     * The guest's stay in the house now, so the booking is tied to it like a
     * desk booking for an in-house guest.
     */
    private function currentStayId(Guest $guest): ?string
    {
        return Stay::withoutGlobalScope('hotel')
            ->where('hotel_id', $this->hotel->id)
            ->where('guest_id', $guest->id)
            ->where('status', 'in_house')
            ->value('id');
    }
}
