<?php

namespace App\Services\Proactive;

use App\Enums\BookingStatus;
use App\Enums\PitchResult;
use App\Enums\ProactiveTrigger;
use App\Enums\StayStatus;
use App\Models\Booking;
use App\Models\Hotel;
use App\Models\PitchDecision;
use App\Models\Recommendation;
use App\Models\Stay;
use App\Support\Proactive\ProactiveSettings;
use Carbon\CarbonInterface;
use Illuminate\Support\Carbon;

/**
 * Which proactive messages a hotel's triggers call for right now
 * (SPEC-073 FR-025, R10). Each method is a fixed number of queries,
 * whatever the number of guests, and returns rows for proactive_messages;
 * the unique (hotel, guest, trigger, event) key makes inserting them again
 * harmless.
 *
 * Messages go to the reservation's primary guest, once per reservation, so a
 * guest with three rooms gets one milestone message. Whether a message may
 * actually go out is decided later, at send time (ProactiveGuardrails).
 */
class ProactiveTriggerFinder
{
    /** A milestone message stays worth sending for this long after it is due. */
    public const MILESTONE_VALID_HOURS = 3;

    /** Bookings starting within this many hours are considered for a reminder. */
    public const REMINDER_HORIZON_HOURS = 36;

    /**
     * @return list<array<string, mixed>>
     */
    public function find(Hotel $hotel, ProactiveTrigger $trigger, CarbonInterface $now, ?string $reservationId = null): array
    {
        $settings = $hotel->proactiveSettings();
        $local = $now->copy()->setTimezone($hotel->timezone ?: 'UTC');

        return match ($trigger) {
            ProactiveTrigger::FIRST_MORNING => $this->firstMorning($hotel, $settings, $local),
            ProactiveTrigger::MID_STAY => $this->midStay($hotel, $settings, $local),
            ProactiveTrigger::UPCOMING_ACTIVITY => $this->upcomingActivity($hotel, $settings, $local),
            ProactiveTrigger::RECOMMENDATION_APPROVED => $this->recommendationApproved($hotel, $local, $reservationId),
        };
    }

    /**
     * Guests who checked in yesterday (hotel time): a message this morning.
     *
     * @return list<array<string, mixed>>
     */
    private function firstMorning(Hotel $hotel, ProactiveSettings $settings, CarbonInterface $local): array
    {
        $yesterday = $local->copy()->subDay();

        $stays = $this->inHouseStays($hotel)
            ->whereBetween('stays.checked_in_at', [$yesterday->copy()->startOfDay()->utc(), $yesterday->copy()->endOfDay()->utc()])
            ->get();

        $due = $local->copy()->setTimeFromTimeString($settings->milestoneTime)->startOfMinute();

        return $this->onePerReservation($hotel, $stays, ProactiveTrigger::FIRST_MORNING, 'first_morning', $due, $due->copy()->addHours(self::MILESTONE_VALID_HOURS));
    }

    /**
     * The middle day of a stay of four nights or more.
     *
     * @return list<array<string, mixed>>
     */
    private function midStay(Hotel $hotel, ProactiveSettings $settings, CarbonInterface $local): array
    {
        $today = $local->toDateString();

        $stays = $this->inHouseStays($hotel)
            ->get()
            ->filter(function (Stay $stay) use ($today) {
                $arrival = Carbon::parse($stay->reservation_arrival);
                $nights = (int) $arrival->diffInDays(Carbon::parse($stay->reservation_departure));

                return $nights >= 4 && $arrival->copy()->addDays(intdiv($nights, 2))->toDateString() === $today;
            });

        $due = $local->copy()->setTimeFromTimeString($settings->milestoneTime)->startOfMinute();

        return $this->onePerReservation($hotel, $stays, ProactiveTrigger::MID_STAY, 'mid_stay', $due, $due->copy()->addHours(self::MILESTONE_VALID_HOURS));
    }

    /**
     * Confirmed bookings starting soon. Morning activities are reminded the
     * evening before; later ones a few hours ahead. Never later than an hour
     * before the start.
     *
     * @return list<array<string, mixed>>
     */
    private function upcomingActivity(Hotel $hotel, ProactiveSettings $settings, CarbonInterface $local): array
    {
        $now = $local->copy()->utc();

        return Booking::query()
            ->where('hotel_id', $hotel->id)
            ->where('status', BookingStatus::CONFIRMED->value)
            ->whereNotNull('guest_id')
            ->whereBetween('scheduled_for', [$now, $now->copy()->addHours(self::REMINDER_HORIZON_HOURS)])
            ->get()
            ->map(function (Booking $booking) use ($settings, $hotel) {
                $start = $booking->scheduled_for->copy()->setTimezone($hotel->timezone ?: 'UTC');
                $due = $start->format('H:i') < $settings->reminderMorningCutoff
                    ? $start->copy()->subDay()->setTimeFromTimeString($settings->reminderEveningBeforeAt)->startOfMinute()
                    : $start->copy()->subHours($settings->reminderHoursBefore);

                return $this->row(
                    $hotel,
                    ProactiveTrigger::UPCOMING_ACTIVITY,
                    "booking:{$booking->id}",
                    guestId: $booking->guest_id,
                    reservationId: $booking->reservation_id,
                    stayId: $booking->stay_id,
                    due: $due,
                    validUntil: $start->copy()->subHour(),
                    bookingId: $booking->id,
                );
            })
            ->filter(fn (array $row) => $row['due_at'] < $row['valid_until'])
            ->values()
            ->all();
    }

    /**
     * An approved recommendation for an in-house guest who has had no
     * unsolicited pitch this reservation.
     *
     * @return list<array<string, mixed>>
     */
    private function recommendationApproved(Hotel $hotel, CarbonInterface $local, ?string $reservationId): array
    {
        $pitched = PitchDecision::withoutGlobalScope('hotel')
            ->join('stays', 'stays.id', '=', 'pitch_decisions.stay_id')
            ->join('recommendations', 'recommendations.pitch_decision_id', '=', 'pitch_decisions.id')
            ->where('pitch_decisions.hotel_id', $hotel->id)
            ->where('pitch_decisions.explicit_request', false)
            ->where(fn ($query) => $query->whereNull('pitch_decisions.result')->orWhere('pitch_decisions.result', PitchResult::PITCHED->value))
            ->select('stays.reservation_id');

        $stays = $this->inHouseStays($hotel)
            ->when($reservationId, fn ($query) => $query->where('stays.reservation_id', $reservationId))
            ->whereNotIn('stays.reservation_id', $pitched)
            ->get()
            ->unique('reservation_id');

        $recommendations = Recommendation::query()
            ->whereIn('reservation_id', $stays->pluck('reservation_id'))
            ->offerable()
            ->get()
            ->groupBy('reservation_id');

        $rows = [];

        foreach ($stays as $stay) {
            foreach ($recommendations->get($stay->reservation_id, collect()) as $recommendation) {
                $departure = Carbon::parse($stay->reservation_departure, $hotel->timezone ?: 'UTC')->startOfDay();

                $rows[] = $this->row(
                    $hotel,
                    ProactiveTrigger::RECOMMENDATION_APPROVED,
                    "recommendation:{$recommendation->id}",
                    guestId: $stay->reservation_guest_id,
                    reservationId: $stay->reservation_id,
                    stayId: $stay->id,
                    due: $local,
                    validUntil: $departure,
                    recommendationId: $recommendation->id,
                );
            }
        }

        return array_values(array_filter($rows, fn (array $row) => $row['due_at'] < $row['valid_until']));
    }

    /**
     * In-house stays of the hotel with their reservation's primary guest and
     * dates alongside.
     */
    private function inHouseStays(Hotel $hotel)
    {
        return Stay::query()
            ->join('reservations', 'reservations.id', '=', 'stays.reservation_id')
            ->where('stays.hotel_id', $hotel->id)
            ->where('stays.status', StayStatus::IN_HOUSE->value)
            ->whereNull('stays.checked_out_at')
            ->select('stays.*', 'reservations.guest_id as reservation_guest_id', 'reservations.arrival_date as reservation_arrival', 'reservations.departure_date as reservation_departure')
            ->orderBy('stays.id');
    }

    /**
     * @param  iterable<Stay>  $stays
     * @return list<array<string, mixed>>
     */
    private function onePerReservation(Hotel $hotel, iterable $stays, ProactiveTrigger $trigger, string $prefix, CarbonInterface $due, CarbonInterface $validUntil): array
    {
        return collect($stays)
            ->unique('reservation_id')
            ->map(fn (Stay $stay) => $this->row(
                $hotel,
                $trigger,
                "{$prefix}:{$stay->reservation_id}",
                guestId: $stay->reservation_guest_id,
                reservationId: $stay->reservation_id,
                stayId: $stay->id,
                due: $due,
                validUntil: $validUntil,
            ))
            ->values()
            ->all();
    }

    /**
     * @return array<string, mixed>
     */
    private function row(
        Hotel $hotel,
        ProactiveTrigger $trigger,
        string $eventKey,
        string $guestId,
        ?string $reservationId,
        ?string $stayId,
        CarbonInterface $due,
        CarbonInterface $validUntil,
        ?string $bookingId = null,
        ?string $recommendationId = null,
    ): array {
        return [
            'hotel_id' => $hotel->id,
            'guest_id' => $guestId,
            'reservation_id' => $reservationId,
            'stay_id' => $stayId,
            'trigger' => $trigger->value,
            'event_key' => $eventKey,
            'booking_id' => $bookingId,
            'recommendation_id' => $recommendationId,
            'due_at' => $due->copy()->setTimezone(config('app.timezone'))->format('Y-m-d H:i:s'),
            'valid_until' => $validUntil->copy()->setTimezone(config('app.timezone'))->format('Y-m-d H:i:s'),
        ];
    }
}
