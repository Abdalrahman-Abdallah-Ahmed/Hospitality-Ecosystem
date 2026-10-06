<?php

namespace App\Ai\Tools;

use App\Enums\ActivityUnavailableReason;
use App\Enums\BookingOrigin;
use App\Enums\BookingStatus;
use App\Enums\ChargeModel;
use App\Enums\StayStatus;
use App\Exceptions\ActivityUnavailableException;
use App\Models\Activity;
use App\Models\Guest;
use App\Models\Hotel;
use App\Models\Recommendation;
use App\Models\Reservation;
use App\Models\Stay;
use App\Services\ActivityAvailabilityService;
use App\Services\BookingService;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Validation\ValidationException;
use Laravel\Ai\Contracts\Tool;
use Laravel\Ai\Tools\Request;
use Stringable;
use Throwable;

/**
 * Records the commitment when a guest says yes.
 *
 * This is the conversion event: the moment the recommendation agent's job is
 * complete. Whether money ever follows is a separate process — an included
 * activity produces no payment at all and the recommendation still worked.
 *
 * It books through the same availability check the desk uses (SPEC-041), only
 * for the guest's own reservation and only on its dates, and never past an
 * activity's capacity: overriding is a staff decision.
 */
class CreateBookingTool implements Tool
{
    /**
     * How far ahead alternatives are looked for when a date is unavailable.
     */
    private const ALTERNATIVE_DAYS = 14;

    public function __construct(
        private readonly Hotel $hotel,
        private readonly Guest $guest,
        private readonly ?Reservation $reservation = null,
    ) {}

    public function description(): Stringable|string
    {
        return 'Record a booking once the guest has actually agreed to an activity — a real commitment, not just interest. Check the date with the activities tool first. The booking is refused if the activity is closed, outside its hours, full, or the date is outside the guest\'s reservation; you then get the reason and the nearest available dates to offer instead. If the booking follows a recommendation you showed them, pass its id so the recommendation can be credited.';
    }

    public function handle(Request $request): Stringable|string
    {
        if (! $this->reservation) {
            return 'I can only book activities for a guest with a reservation at this hotel. Ask the guest for their reservation details, or offer to have staff help them.';
        }

        $activity = $this->resolveActivity($request);
        $itemName = trim($request->string('item_name')->toString()) ?: $activity?->name;

        if (! $itemName) {
            return 'Provide either an activity_id from the activities tool or an item_name describing what the guest is booking.';
        }

        $scheduledFor = trim($request->string('scheduled_for')->toString());

        if ($activity && $scheduledFor === '') {
            return "Ask the guest which date (and time, if it has opening hours) they want for {$activity->name}, then try again with scheduled_for.";
        }

        if ($scheduledFor !== '' && ($outside = $this->outsideReservation($scheduledFor))) {
            return $outside;
        }

        $recommendation = $this->resolveRecommendation($request);
        $pax = $request->integer('pax', 1) ?: 1;

        try {
            $booking = app(BookingService::class)->create([
                'hotel_id' => $this->hotel->id,
                'guest_id' => $this->guest->id,
                'reservation_id' => $this->reservation->id,
                'stay_id' => $this->inHouseStay()?->id,
                'activity_id' => $activity?->id,
                'recommendation_id' => $recommendation?->id,
                'item_name' => $itemName,
                'status' => BookingStatus::PENDING->value,
                // Raw: a time without an offset is the hotel's local time.
                'scheduled_for' => $scheduledFor !== '' ? $scheduledFor : null,
                'pax' => $pax,
                'charge_model' => $request->enum('charge_model', ChargeModel::class, ChargeModel::PAY_ON_SITE)->value,
                'expected_value' => $activity?->price,
                'currency' => $activity?->currency ?? $this->hotel->currency,
                // A guest asking unprompted is a different signal from one acting
                // on an offer we made — that distinction is what tells you whether
                // the agent creates demand or merely records it.
                'origin' => $recommendation ? BookingOrigin::RECOMMENDATION->value : BookingOrigin::GUEST_REQUEST->value,
                'channel' => 'whatsapp',
            ]);
        } catch (ActivityUnavailableException $e) {
            return $e->getMessage().' No booking was made. '.$this->alternatives($activity, $e, $pax);
        } catch (ValidationException $e) {
            return $e->getMessage().' No booking was made.';
        }

        return "Booking recorded for '{$itemName}'. The guest's reference is {$booking->reference} — give it to them and ask them to quote it at the desk.";
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'activity_id' => $schema->string()
                ->description('The id of the activity being booked, from the activities tool. Leave unset only if the guest is booking something not in the catalogue.'),
            'item_name' => $schema->string()
                ->description('What is being booked, in plain words. Required only when there is no activity_id.'),
            'recommendation_id' => $schema->string()
                ->description('The id of the recommendation this booking follows, from the get-recommendations tool. Set it whenever the guest is acting on something you suggested; leave it unset if they asked unprompted.'),
            'scheduled_for' => $schema->string()
                ->description('When the guest is expected, in the hotel\'s local time with no offset: "YYYY-MM-DD HH:MM", or "YYYY-MM-DD" for an activity with no opening hours. Required for an activity from the catalogue. It must fall on or before the guest\'s departure date.'),
            'pax' => $schema->integer()
                ->description('How many people the booking is for.')
                ->default(1),
            'charge_model' => $schema->string()
                ->enum(ChargeModel::class)
                ->description("How it is paid for: 'included' if it is part of the guest's package and costs nothing extra, 'pay_on_site' if they pay at the outlet, 'folio' if it goes on their room bill, 'prepaid' if already paid. Use the knowledge base or activity details rather than guessing.")
                ->default(ChargeModel::PAY_ON_SITE->value),
        ];
    }

    /**
     * The guest can only book from today until they leave (spec Q5): a date
     * they will not be at the hotel is refused before anything is checked.
     */
    private function outsideReservation(string $scheduledFor): ?string
    {
        try {
            $date = CarbonImmutable::parse($scheduledFor, $this->hotel->timezone ?: 'UTC')->toDateString();
        } catch (Throwable) {
            return "'{$scheduledFor}' is not a date I understand. Use YYYY-MM-DD HH:MM in the hotel's local time.";
        }

        $today = app(ActivityAvailabilityService::class)->today($this->hotel);
        $departure = $this->reservation->departure_date->toDateString();

        if ($date >= $today && $date <= $departure) {
            return null;
        }

        return "{$date} is outside the guest's stay (bookable from {$today} to their departure on {$departure}). No booking was made. "
            .'Reason: '.ActivityUnavailableReason::OUTSIDE_RESERVATION->value.'.';
    }

    /**
     * Up to three nearby dates the party fits, from today to the earlier of
     * two weeks ahead and the guest's departure.
     */
    private function alternatives(?Activity $activity, ActivityUnavailableException $e, int $pax): string
    {
        if (! $activity || $e->reason === ActivityUnavailableReason::INACTIVE || $e->reason === ActivityUnavailableReason::PARTY_EXCEEDS_CAPACITY) {
            return '';
        }

        $availability = app(ActivityAvailabilityService::class);
        $today = $availability->today($this->hotel);
        $until = min(
            CarbonImmutable::parse($today)->addDays(self::ALTERNATIVE_DAYS)->toDateString(),
            $this->reservation->departure_date->toDateString(),
        );

        $dates = $availability->nearestOpenDates($activity, $e->unavailable['date'], $pax, 3, $today, $until);

        return $dates === []
            ? 'No other dates are available during the guest\'s stay.'
            : 'Nearest available dates: '.implode(', ', $dates).'. Check the time windows with the activities tool before offering one.';
    }

    /**
     * The reservation's stay the guest is in now, when they have checked in.
     */
    private function inHouseStay(): ?Stay
    {
        return Stay::withoutGlobalScope('hotel')
            ->where('reservation_id', $this->reservation->id)
            ->where('status', StayStatus::IN_HOUSE->value)
            ->orderBy('created_at')
            ->first();
    }

    /**
     * A hallucinated or cross-hotel id must not create a booking against
     * another property's catalogue.
     */
    private function resolveActivity(Request $request): ?Activity
    {
        if (! $request->filled('activity_id')) {
            return null;
        }

        return Activity::where('hotel_id', $this->hotel->id)
            ->find($request->string('activity_id')->toString());
    }

    private function resolveRecommendation(Request $request): ?Recommendation
    {
        if (! $request->filled('recommendation_id')) {
            return null;
        }

        return Recommendation::where('hotel_id', $this->hotel->id)
            ->find($request->string('recommendation_id')->toString());
    }
}
