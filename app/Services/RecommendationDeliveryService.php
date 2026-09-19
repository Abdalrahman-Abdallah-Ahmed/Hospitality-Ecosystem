<?php

namespace App\Services;

use App\Enums\ActorKind;
use App\Enums\DeliveryChannel;
use App\Enums\MeterFeature;
use App\Enums\RecommendationStatus;
use App\Models\Recommendation;
use App\Services\Metering\MeteringService;
use App\Support\Audit\EventLogger;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\DB;

/**
 * The only writer of a recommendation's delivery.
 *
 * Retrieving is not delivering: the agent reading a recommendation proves
 * nothing about whether the guest saw it. Delivery is stamped only when the
 * offer went out in a reply WhatsApp accepted, or a person or a booking shows
 * the guest was offered it. Never by inference — an inferred booking says
 * nothing about whether our offer reached anyone.
 */
class RecommendationDeliveryService
{
    public function __construct(
        private readonly MeteringService $metering,
    ) {}

    /**
     * Stamp delivery once. Returns true only when this call stamped it — a
     * second call is a no-op and does not meter again.
     *
     * Also moves status PENDING → SENT, and records one
     * RECOMMENDATIONS_DELIVERED meter event through MeteringService::safely().
     */
    public function markDelivered(
        Recommendation $recommendation,
        DeliveryChannel $channel,
        CarbonInterface $at,
        ActorKind $actorKind,
    ): bool {
        $at = $at->copy()->setTimezone(config('app.timezone'));

        // A conditional update, not a read-then-write, so two callers racing
        // to stamp the same recommendation cannot both win and both meter.
        // Plain queries skip the model's own event logging; the delivery is
        // recorded below as one named event instead.
        [$stamped, $sent] = DB::transaction(function () use ($recommendation, $channel, $at) {
            $stamped = Recommendation::withoutGlobalScope('hotel')
                ->whereKey($recommendation->getKey())
                ->whereNull('delivered_at')
                ->update(['delivered_at' => $at, 'delivery_channel' => $channel->value]) === 1;

            $sent = $stamped && Recommendation::withoutGlobalScope('hotel')
                ->whereKey($recommendation->getKey())
                ->where('status', RecommendationStatus::PENDING->value)
                ->update(['status' => RecommendationStatus::SENT->value]) === 1;

            return [$stamped, $sent];
        });

        if (! $stamped) {
            return false;
        }

        $recommendation->refresh();

        EventLogger::record($recommendation, 'delivered', changes: array_filter([
            'delivered_at' => ['from' => null, 'to' => EventLogger::normalize($recommendation->delivered_at)],
            'delivery_channel' => ['from' => null, 'to' => $channel->value],
            'status' => $sent
                ? ['from' => RecommendationStatus::PENDING->value, 'to' => RecommendationStatus::SENT->value]
                : null,
        ]));

        $this->meter($recommendation, $channel, $actorKind);

        return true;
    }

    /**
     * A count, never a value: no price, no expected revenue. Through
     * safely(), so counting a delivery can never break a reply or a booking.
     */
    private function meter(Recommendation $recommendation, DeliveryChannel $channel, ActorKind $actorKind): void
    {
        $this->metering->safely(function (MeteringService $m) use ($recommendation, $channel, $actorKind) {
            $recommendation->loadMissing('hotel');

            if ($recommendation->hotel) {
                $m->recordForHotel(
                    hotel: $recommendation->hotel,
                    feature: MeterFeature::RECOMMENDATIONS_DELIVERED,
                    source: $recommendation,
                    idempotencyKey: MeterFeature::RECOMMENDATIONS_DELIVERED->value.':'.$recommendation->getKey(),
                    metadata: ['channel' => $channel->value],
                    actorKind: $actorKind,
                );
            }
        });
    }
}
