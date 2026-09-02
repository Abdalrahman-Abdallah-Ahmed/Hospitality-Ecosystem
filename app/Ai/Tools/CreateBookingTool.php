<?php

namespace App\Ai\Tools;

use App\Enums\BookingOrigin;
use App\Enums\BookingStatus;
use App\Enums\ChargeModel;
use App\Models\Activity;
use App\Models\Guest;
use App\Models\Hotel;
use App\Models\Recommendation;
use App\Models\Reservation;
use App\Services\BookingService;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Support\Carbon;
use Laravel\Ai\Contracts\Tool;
use Laravel\Ai\Tools\Request;
use Stringable;

/**
 * Records the commitment when a guest says yes.
 *
 * This is the conversion event: the moment the recommendation agent's job is
 * complete. Whether money ever follows is a separate process — an included
 * activity produces no payment at all and the recommendation still worked.
 */
class CreateBookingTool implements Tool
{
    public function __construct(
        private readonly Hotel $hotel,
        private readonly Guest $guest,
        private readonly ?Reservation $reservation = null,
    ) {}

    public function description(): Stringable|string
    {
        return 'Record a booking once the guest has actually agreed to an activity — a real commitment, not just interest. If the booking follows a recommendation you showed them, pass its id so the recommendation can be credited.';
    }

    public function handle(Request $request): Stringable|string
    {
        $activity = $this->resolveActivity($request);
        $itemName = trim($request->string('item_name')->toString()) ?: $activity?->name;

        if (! $itemName) {
            return 'Provide either an activity_id from the activities tool or an item_name describing what the guest is booking.';
        }

        $recommendation = $this->resolveRecommendation($request);

        $booking = app(BookingService::class)->create([
            'hotel_id' => $this->hotel->id,
            'guest_id' => $this->guest->id,
            'stay_id' => $this->reservation?->stay?->id,
            'activity_id' => $activity?->id,
            'recommendation_id' => $recommendation?->id,
            'item_name' => $itemName,
            'status' => BookingStatus::PENDING->value,
            'scheduled_for' => $request->filled('scheduled_for')
                ? Carbon::parse($request->string('scheduled_for')->toString())
                : null,
            'pax' => $request->integer('pax', 1) ?: 1,
            'charge_model' => $request->enum('charge_model', ChargeModel::class, ChargeModel::PAY_ON_SITE)->value,
            'expected_value' => $activity?->price,
            'currency' => $activity?->currency ?? $this->hotel->currency,
            // A guest asking unprompted is a different signal from one acting
            // on an offer we made — that distinction is what tells you whether
            // the agent creates demand or merely records it.
            'origin' => $recommendation ? BookingOrigin::RECOMMENDATION->value : BookingOrigin::GUEST_REQUEST->value,
            'channel' => 'whatsapp',
        ]);

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
                ->description('When the guest is expected, as an ISO 8601 datetime in the hotel\'s local time. Leave unset if no time was agreed.'),
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
