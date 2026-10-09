<?php

namespace App\Services;

use App\Enums\ActorKind;
use App\Enums\AttributionMethod;
use App\Enums\BookingOrigin;
use App\Enums\BookingStatus;
use App\Enums\CancellationResolution;
use App\Enums\ChargeModel;
use App\Enums\DeliveryChannel;
use App\Enums\MeterFeature;
use App\Enums\OutcomeType;
use App\Enums\TaskStatus;
use App\Exceptions\DomainRuleException;
use App\Models\Activity;
use App\Models\Booking;
use App\Models\Hotel;
use App\Models\Recommendation;
use App\Models\Stay;
use App\Models\Transaction;
use App\Models\User;
use App\Services\Metering\MeteringService;
use App\Support\Audit\EventLogger;
use App\Support\Bookings\BookingReference;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use DateTimeInterface;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use RuntimeException;

/**
 * The only sanctioned way to create or move a booking.
 *
 * The rule this service exists to protect: **booking status and settlement are
 * independent, in both directions.** A booking can be REALISED with no
 * transaction (an all-inclusive guest pays nothing), and a transaction can
 * exist with no booking (a walk-up sale). Neither is ever derived from the
 * other, and no method here goes looking for money to decide a status.
 */
class BookingService
{
    /**
     * Bookings arrive through AI paths — CreateBookingTool in the concierge
     * agent, and the recommendation flow — so this reads like an AI feature
     * and will one day be a tempting thing to put behind a quota or a plan
     * entitlement. It is not one. A booking is an operational commitment: a
     * table held, a guest expected, staff scheduled. Gate the suggestion,
     * never the commitment.
     *
     * The failure that rule prevents: a card expires on Friday, the account
     * suspends, booking creation is gated. Guests keep booking dinner over
     * WhatsApp and the records do not save. Saturday evening the outlet has
     * no covers list and thirty guests arrive expecting tables. Nobody
     * connects it to billing, because nothing in the restaurant's world
     * mentions billing.
     *
     * No enforcement layer exists yet (Phase 2 v2.0 defers WP-9), which is
     * exactly why this is written down here rather than left in a plan.
     */
    public function create(array $data): Booking
    {
        $override = (bool) ($data['capacity_override'] ?? false);
        $hotel = Hotel::findOrFail($data['hotel_id']);
        $activity = $this->activity($hotel, $data['activity_id'] ?? null);
        $schedule = $this->schedule($hotel, $activity, $data['scheduled_for'] ?? null, $data['scheduled_date'] ?? null);

        unset($data['capacity_override'], $data['scheduled_for'], $data['scheduled_date'], $data['scheduled_time'], $data['last_date']);
        $this->requireParty((int) ($data['pax'] ?? 1));

        $booking = DB::transaction(function () use ($data, $activity, $schedule, $override) {
            $overridden = null;

            if ($activity) {
                $this->requireDate($activity, $schedule);
                $this->availability()->lock([$activity->id]);

                if ($existing = $this->repeatedAiBooking($data, $activity, $schedule)) {
                    return $existing;
                }

                $overridden = $this->availability()->assertBookable(
                    $activity, $schedule['scheduled_date'], $schedule['scheduled_time'], (int) ($data['pax'] ?? 1), override: $override,
                );
            }

            $booking = new Booking([
                ...$data,
                'reference' => $data['reference'] ?? BookingReference::generate(),
                'status' => $data['status'] ?? BookingStatus::PENDING->value,
            ]);
            $booking->forceFill($schedule)->save();

            if ($overridden) {
                EventLogger::record($booking, 'capacity_overridden', changes: $overridden);
            }

            return $booking;
        });

        // A retried AI booking returns the one already recorded: it was
        // credited and counted the first time.
        if (! $booking->wasRecentlyCreated) {
            return $booking;
        }

        $this->creditRecommendation($booking);

        // Metered for reporting only. Nothing bills on it and nothing gates on
        // it — it accumulates so that the eventual "volume or bookings?"
        // pricing decision is made against real data rather than a guess.
        $this->meter($booking, MeterFeature::BOOKINGS_CREATED);

        return $booking;
    }

    /**
     * Change a live booking's activity, date, time, party, notes or links
     * (SPEC-043). The guest, recommendation, origin, reference and status are
     * never changed here.
     *
     * Availability is re-checked only when what the booking holds changes —
     * activity, date, time or party — and the booking's own places never
     * count against it. A booking whose date is left alone can have its party
     * corrected even once the date has passed.
     *
     * @param  array<string, mixed>  $data
     *
     * @throws RuntimeException when the booking is no longer live
     */
    public function update(Booking $booking, array $data): Booking
    {
        $override = (bool) ($data['capacity_override'] ?? false);
        $hotel = Hotel::findOrFail($booking->hotel_id);

        return DB::transaction(function () use ($booking, $data, $override, $hotel) {
            $activityId = array_key_exists('activity_id', $data) ? $data['activity_id'] : $booking->activity_id;
            $this->availability()->lock([$booking->activity_id, $activityId]);

            $booking = Booking::withoutGlobalScope('hotel')->lockForUpdate()->findOrFail($booking->id);

            if (! $booking->status->isEditable()) {
                throw new RuntimeException("A {$booking->status->value} booking can no longer be changed.");
            }

            $activity = $this->activity($hotel, $activityId);
            $activityChanged = $activityId !== $booking->activity_id;
            $dateChanged = array_key_exists('scheduled_for', $data) || array_key_exists('scheduled_date', $data);

            if (array_key_exists('item_name', $data) && $activity) {
                throw new RuntimeException('Only a booking outside the activity catalogue can be renamed.');
            }

            $schedule = $dateChanged
                ? $this->schedule($hotel, $activity, $data['scheduled_for'] ?? null, $data['scheduled_date'] ?? null)
                : $this->currentSchedule($booking, $activity);

            $pax = (int) ($data['pax'] ?? $booking->pax);
            $this->requireParty($pax);
            // A smaller party only frees places, so it is never refused —
            // even after the activity closed or changed its hours.
            $holdsMore = $activityChanged || $dateChanged || $pax > (int) $booking->pax;
            $overridden = null;

            // A catalogue booking with no date yet (taken before dates were
            // recorded) must be given one before anything else changes.
            if ($activity && ($holdsMore || $schedule['scheduled_date'] === null)) {
                $this->requireDate($activity, $schedule);

                $overridden = $this->availability()->assertBookable(
                    $activity,
                    $schedule['scheduled_date'],
                    $schedule['scheduled_time'],
                    $pax,
                    ignoreBookingId: $booking->id,
                    override: $override,
                    checkPast: $activityChanged || $dateChanged,
                );
            }

            $booking->fill(array_intersect_key($data, array_flip(['activity_id', 'pax', 'notes', 'item_name', 'reservation_id', 'stay_id'])));
            $booking->forceFill($schedule);

            if ($activityChanged && $activity) {
                $booking->item_name = $activity->name;
            }

            $booking->save();

            if ($overridden) {
                EventLogger::record($booking, 'capacity_overridden', changes: $overridden);
            }

            return $booking;
        });
    }

    /**
     * The hotel-local schedule of a booking (research R4).
     *
     * A time with no offset is the hotel's local time — what a guest or the
     * desk means by "17:30". A time with an offset, or a date object, is an
     * instant and is converted to it. A date alone books an activity that runs
     * all day. `scheduled_for` keeps the real instant, in UTC.
     *
     * @return array{scheduled_for: ?CarbonImmutable, scheduled_date: ?string, scheduled_time: ?string, last_date: ?string}
     */
    private function schedule(Hotel $hotel, ?Activity $activity, mixed $scheduledFor, ?string $scheduledDate): array
    {
        $timezone = $hotel->timezone ?: 'UTC';

        // A bare date is a date with no time, not midnight: the time check
        // then asks for one instead of calling 00:00 outside the hours.
        if (is_string($scheduledFor) && preg_match('/^\d{4}-\d{2}-\d{2}$/', trim($scheduledFor))) {
            [$scheduledFor, $scheduledDate] = [null, trim($scheduledFor)];
        }

        if ($scheduledFor !== null && $scheduledFor !== '') {
            $local = match (true) {
                $scheduledFor instanceof DateTimeInterface => CarbonImmutable::instance($scheduledFor)->setTimezone($timezone),
                (bool) preg_match('/(Z|[+-]\d{2}:?\d{2})$/i', trim((string) $scheduledFor)) => CarbonImmutable::parse($scheduledFor)->setTimezone($timezone),
                default => CarbonImmutable::parse($scheduledFor, $timezone),
            };

            return [
                'scheduled_for' => $local->utc(),
                'scheduled_date' => $local->toDateString(),
                'scheduled_time' => $local->format('H:i'),
                'last_date' => $this->availability()->lastDate($activity, $local->toDateString()),
            ];
        }

        if ($scheduledDate !== null && $scheduledDate !== '') {
            return [
                'scheduled_for' => null,
                'scheduled_date' => $scheduledDate,
                'scheduled_time' => null,
                'last_date' => $this->availability()->lastDate($activity, $scheduledDate),
            ];
        }

        return ['scheduled_for' => null, 'scheduled_date' => null, 'scheduled_time' => null, 'last_date' => null];
    }

    /**
     * The booking's schedule as saved, with its last date recomputed for the
     * activity it now belongs to.
     *
     * @return array{scheduled_for: mixed, scheduled_date: ?string, scheduled_time: ?string, last_date: ?string}
     */
    private function currentSchedule(Booking $booking, ?Activity $activity): array
    {
        $date = $booking->scheduled_date?->toDateString();

        return [
            'scheduled_for' => $booking->scheduled_for,
            'scheduled_date' => $date,
            'scheduled_time' => $booking->scheduled_time !== null ? substr($booking->scheduled_time, 0, 5) : null,
            'last_date' => $date !== null ? $this->availability()->lastDate($activity, $date) : null,
        ];
    }

    /**
     * A party of none or fewer would lower the booked load and let the
     * activity be oversold without an override, whichever path asked.
     */
    private function requireParty(int $pax): void
    {
        if ($pax < 1) {
            throw ValidationException::withMessages(['pax' => 'A booking is for at least one person.']);
        }
    }

    /**
     * A catalogue booking always holds a date: without one it would hold no
     * places, and the activity could be sold past its capacity.
     *
     * @param  array{scheduled_date: ?string}  $schedule
     */
    private function requireDate(Activity $activity, array $schedule): void
    {
        if ($schedule['scheduled_date'] === null) {
            throw ValidationException::withMessages(['scheduled_for' => "A date is required to book {$activity->name}."]);
        }
    }

    /**
     * The same booking an AI agent already made in the last ten minutes — a
     * retried message, not a second request (FR-017). Staff entering two
     * identical walk-ups get two bookings. Called under the activity lock, so
     * two retries racing each other see one another.
     *
     * @param  array<string, mixed>  $data
     * @param  array{scheduled_date: ?string, scheduled_time: ?string}  $schedule
     */
    private function repeatedAiBooking(array $data, Activity $activity, array $schedule): ?Booking
    {
        if (EventLogger::currentActorKind() !== ActorKind::AI_AGENT || empty($data['guest_id'])) {
            return null;
        }

        return Booking::withoutGlobalScope('hotel')
            ->where('hotel_id', $activity->hotel_id)
            ->where('guest_id', $data['guest_id'])
            ->where('activity_id', $activity->id)
            ->where('scheduled_date', $schedule['scheduled_date'])
            ->when(
                $schedule['scheduled_time'] !== null,
                fn ($query) => $query->where('scheduled_time', $schedule['scheduled_time']),
                fn ($query) => $query->whereNull('scheduled_time'),
            )
            ->where('pax', (int) ($data['pax'] ?? 1))
            ->whereIn('status', [BookingStatus::PENDING->value, BookingStatus::CONFIRMED->value])
            ->where('created_at', '>=', now()->subMinutes(10))
            ->latest()
            ->first();
    }

    /**
     * The hotel's activity, deleted ones included so the check can say it is
     * no longer offered. A hallucinated or cross-hotel id finds nothing.
     */
    private function activity(Hotel $hotel, ?string $activityId): ?Activity
    {
        if (! $activityId) {
            return null;
        }

        return Activity::withoutGlobalScope('hotel')->withTrashed()
            ->where('hotel_id', $hotel->id)
            ->find($activityId);
    }

    private function availability(): ActivityAvailabilityService
    {
        return app(ActivityAvailabilityService::class);
    }

    /**
     * Metering is resolved out of the container here rather than injected, so
     * that the many places already constructing a BookingService by hand do
     * not all have to change. It goes through safely(), so a metering failure
     * can never stop a booking being saved — a booking is an operational
     * commitment and outranks the count of it.
     */
    private function meter(Booking $booking, MeterFeature $feature): void
    {
        $metering = app(MeteringService::class);

        $metering->safely(function (MeteringService $m) use ($booking, $feature) {
            $booking->loadMissing('hotel');

            if ($booking->hotel) {
                $m->recordForHotel(
                    hotel: $booking->hotel,
                    feature: $feature,
                    source: $booking,
                    idempotencyKey: $feature->value.':'.$booking->getKey(),
                );
            }
        });
    }

    /**
     * A booking carrying a recommendation_id *is* the conversion, observed
     * directly — the strongest link in the system, needing no inference at
     * all. Recorded here so it happens whatever created the booking.
     *
     * It is also proof the guest was offered it, so delivery is stamped —
     * after the outcome, so the booking is not measured as instant. Through
     * rescue(): the booking is already saved and outranks the record of how
     * the offer reached the guest.
     */
    private function creditRecommendation(Booking $booking): void
    {
        $recommendation = $booking->recommendation()->withoutGlobalScope('hotel')->first();

        if (! $recommendation) {
            return;
        }

        app(RecommendationOutcomeService::class)->record(
            $recommendation,
            OutcomeType::BOOKED,
            AttributionMethod::DIRECT,
            $booking,
            ['channel' => $booking->channel, 'occurred_at' => $booking->created_at],
        );

        rescue(fn () => app(RecommendationDeliveryService::class)->markDelivered(
            $recommendation,
            DeliveryChannel::fromRecorded($booking->channel),
            $booking->created_at ?? Carbon::now(),
            EventLogger::currentActorKind(),
        ));
    }

    /**
     * The slot is held and the guest is expected.
     */
    public function confirm(Booking $booking): Booking
    {
        $this->moveTo($booking, BookingStatus::CONFIRMED, [
            'confirmed_at' => $booking->confirmed_at ?? Carbon::now(),
        ]);

        return $booking;
    }

    /**
     * The guest attended.
     *
     * Deliberately does not look for a transaction. On an INCLUDED booking
     * none will ever exist, and treating its absence as failure would report
     * an ideal outcome as a loss.
     */
    public function realise(Booking $booking, ?CarbonInterface $at = null): Booking
    {
        $moved = $this->moveTo($booking, BookingStatus::REALISED, [
            'realised_at' => $at ?? Carbon::now(),
        ]);

        // Created counts intent; realised counts what actually happened. The
        // gap between them is the number worth watching.
        if ($moved) {
            $this->meter($booking, MeterFeature::BOOKINGS_REALISED);
        }

        return $booking;
    }

    /**
     * Confirmed, and the guest never came. Distinct from a cancellation: one
     * is a broken commitment, the other is one withdrawn in time. Different
     * operational responses, different signals.
     */
    public function markNoShow(Booking $booking): Booking
    {
        $this->moveTo($booking, BookingStatus::NO_SHOW, [
            'realised_at' => null,
        ]);

        return $booking;
    }

    /**
     * Withdrawn before the date. A booking is never deleted — the history is
     * the point, same as the ledger.
     */
    public function cancel(Booking $booking, string $reason): Booking
    {
        DB::transaction(function () use ($booking, $reason) {
            $this->moveTo($booking, BookingStatus::CANCELLED, [
                'cancelled_at' => Carbon::now(),
                'cancellation_reason' => $reason,
            ]);

            $this->closeCancellationRequest($booking);
        });

        return $booking;
    }

    /**
     * However the booking came to be cancelled — from the request queue or
     * the status endpoint — the guest's open request to cancel it has been
     * answered yes (FR-026).
     */
    private function closeCancellationRequest(Booking $booking): void
    {
        $request = $booking->openCancellationRequest()->withoutGlobalScope('hotel')->first();

        $request?->forceFill([
            'status' => TaskStatus::COMPLETED,
            'resolution' => CancellationResolution::APPROVED,
            'resolved_by_user_id' => Auth::id(),
            'resolved_at' => now(),
        ])->save();
    }

    /**
     * Attach a payment to the commitment it settles. Direct reference only —
     * there is no booking↔transaction inference in Phase 1, and the booking's
     * own status is untouched by this.
     */
    public function linkSettlement(Booking $booking, Transaction $transaction): Booking
    {
        if ($transaction->hotel_id !== $booking->hotel_id) {
            throw new RuntimeException('The transaction belongs to a different hotel.');
        }

        if ($booking->charge_model === ChargeModel::INCLUDED) {
            throw new RuntimeException('An included booking never settles; it cannot carry a payment.');
        }

        // The ledger is append-only, so this is a direct column write rather
        // than a model update — see Transaction's append-only guard.
        Transaction::withoutGlobalScope('hotel')
            ->whereKey($transaction->id)
            ->update(['booking_id' => $booking->id]);

        return $booking;
    }

    /**
     * Apply a status change and return whether the status actually moved.
     *
     * Asking for the status the booking already has does nothing, so a
     * repeated click neither fails nor overwrites the original timestamp.
     *
     * @throws RuntimeException when the move is not allowed from here
     */
    private function moveTo(Booking $booking, BookingStatus $target, array $attributes): bool
    {
        if ($booking->status === $target) {
            return false;
        }

        if (! in_array($target, $this->nextStatuses($booking->status), true)) {
            throw new RuntimeException("A {$booking->status->value} booking cannot be marked {$target->value}.");
        }

        $booking->update(['status' => $target, ...$attributes]);

        return true;
    }

    /**
     * Forward only. An attended or withdrawn booking is history, and letting
     * a stale request move it back would rewrite it — or quietly resurrect a
     * commitment the guest withdrew. The one exit from NO_SHOW is REALISED: a
     * guest who turned up late did attend.
     *
     * @return array<int, BookingStatus>
     */
    private function nextStatuses(BookingStatus $from): array
    {
        return match ($from) {
            BookingStatus::PENDING => [BookingStatus::CONFIRMED, BookingStatus::REALISED, BookingStatus::NO_SHOW, BookingStatus::CANCELLED],
            BookingStatus::CONFIRMED => [BookingStatus::REALISED, BookingStatus::NO_SHOW, BookingStatus::CANCELLED],
            BookingStatus::NO_SHOW => [BookingStatus::REALISED],
            BookingStatus::REALISED, BookingStatus::CANCELLED => [],
        };
    }

    /**
     * A booking staff take on a guest's behalf: the desk endpoint and the
     * Admin AI (SPEC-055 R4). Every reference is checked against the hotel,
     * the reservation comes from the stay when only a stay is given, and the
     * origin is derived rather than taken on trust.
     *
     * @param  array<string, mixed>  $validated  as StoreBookingRequest validates it
     *
     * @throws DomainRuleException when a reference is not this hotel's, or the stay is not the reservation's
     */
    public function takeStaffBooking(Hotel $hotel, User $by, array $validated, bool $capacityOverride = false): Booking
    {
        // exists:… only proves the row exists somewhere, not that it is ours.
        if ($invalid = invalidRelation($hotel, [
            'guests' => $validated['guest_id'],
            'activities' => $validated['activity_id'] ?? null,
            'stays' => $validated['stay_id'] ?? null,
            'reservations' => $validated['reservation_id'] ?? null,
        ])) {
            throw new DomainRuleException('The selected '.Str::singular($invalid).' does not belong to this hotel.', 422);
        }

        $reservationId = $this->reservationFor($validated['stay_id'] ?? null, $validated['reservation_id'] ?? null);

        if ($reservationId === false) {
            throw new DomainRuleException('The selected stay does not belong to the selected reservation.', 422);
        }

        $activity = isset($validated['activity_id'])
            ? Activity::where('hotel_id', $hotel->id)->find($validated['activity_id'])
            : null;

        $recommendation = isset($validated['recommendation_id'])
            ? Recommendation::where('hotel_id', $hotel->id)->find($validated['recommendation_id'])
            : null;

        if (isset($validated['recommendation_id']) && ! $recommendation) {
            throw new DomainRuleException('The selected recommendation does not belong to this hotel.', 422);
        }

        return $this->create([
            'hotel_id' => $hotel->id,
            'guest_id' => $validated['guest_id'],
            'stay_id' => $validated['stay_id'] ?? null,
            'reservation_id' => $reservationId,
            'activity_id' => $activity?->id,
            'recommendation_id' => $recommendation?->id,
            'item_name' => $validated['item_name'] ?? $activity?->name,
            // Raw, so create() reads a time without an offset in the hotel's
            // timezone.
            'scheduled_for' => $validated['scheduled_for'] ?? null,
            'scheduled_date' => $validated['scheduled_date'] ?? null,
            'pax' => $validated['pax'] ?? 1,
            'notes' => $validated['notes'] ?? null,
            'charge_model' => $validated['charge_model'],
            'expected_value' => $validated['expected_value'] ?? $activity?->price,
            'currency' => $validated['currency'] ?? $activity?->currency ?? $hotel->currency,
            // Derived, never taken from the request: a booking may only claim
            // recommendation origin when it actually carries the link.
            'origin' => $recommendation
                ? BookingOrigin::RECOMMENDATION->value
                : ($validated['origin'] ?? BookingOrigin::STAFF->value),
            'channel' => $validated['channel'] ?? 'desk',
            'created_by_user_id' => $by->id,
            'capacity_override' => $capacityOverride,
        ]);
    }

    /**
     * The reservation a booking belongs to: the one given, or the stay's when
     * only a stay is given. False when the stay belongs to a different one.
     * Both ids are already proven to be this hotel's.
     */
    public function reservationFor(?string $stayId, ?string $reservationId): string|false|null
    {
        if ($stayId === null) {
            return $reservationId;
        }

        $stayReservationId = Stay::withoutGlobalScope('hotel')->whereKey($stayId)->value('reservation_id');

        if ($reservationId !== null && $stayReservationId !== $reservationId) {
            return false;
        }

        return $stayReservationId;
    }
}
