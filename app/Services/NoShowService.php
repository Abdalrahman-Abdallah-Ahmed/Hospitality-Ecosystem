<?php

namespace App\Services;

use App\Enums\ReservationStatus;
use App\Enums\StayStatus;
use App\Exceptions\CheckInOutException;
use App\Models\Hotel;
use App\Models\Reservation;
use App\Models\ReservationRoom;
use App\Models\Room;
use App\Models\Stay;
use App\Support\Audit\EventLogger;
use App\Support\Reservations\ReservationLifecycle;
use App\Support\Reservations\ReservationLock;
use App\Support\Reservations\RoomAssignmentRules;
use App\Support\Stays\FrontDeskLists;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Marks guests who never arrived as no-shows, and undoes a mistaken one
 * (SPEC-012), for a whole reservation or one room (stay). Always a person's
 * decision — staff or the Admin AI acting for them; nothing marks a no-show
 * on its own (FR-011a).
 *
 * The no-show lives on the stays: availability, the arrivals list, pitching,
 * room assignment and the last-check-out rule all read stay status, so a
 * no-show room is released everywhere at once. The reservation becomes
 * `no_show` when every live stay is one, remembering the status to restore.
 *
 * Each action is one transaction that locks like every reservation write —
 * room types, then ReservationLock — then checks its rules, so a repeat
 * changes nothing and a no-show racing a check-in or an edit waits for it.
 */
class NoShowService
{
    public function __construct(
        private readonly StayService $stays,
        private readonly AvailabilityService $availability,
    ) {}

    /**
     * Every room of the reservation never arrived (FR-005).
     *
     * @return array{reservation: Reservation, stays: Collection<int, Stay>, warnings: list<string>}
     */
    public function markReservation(Reservation $reservation, ?string $reason = null): array
    {
        return DB::transaction(function () use ($reservation, $reason) {
            [$reservation, $stays] = $this->lock($reservation);
            $live = $stays->filter(fn (Stay $stay) => $stay->isLive());

            if ($reservation->status === ReservationStatus::NO_SHOW) {
                return $this->result($reservation, $live);
            }

            $reasons = $this->arrivalReasons($reservation);

            if (! ReservationLifecycle::allows($reservation->status, ReservationStatus::NO_SHOW, ReservationLifecycle::NO_SHOW)) {
                $reasons['stays'][] = "Reservation is {$reservation->status->value}; only a pending or confirmed reservation can be a no-show.";
            }

            if ($live->contains(fn (Stay $stay) => in_array($stay->status, [StayStatus::IN_HOUSE, StayStatus::DEPARTED], true))) {
                $reasons['stays'][] = 'Some rooms have checked in; mark the rooms that did not arrive one by one instead.';
            }

            $this->throwIf($reasons);

            $marked = $live->filter(fn (Stay $stay) => $stay->status === StayStatus::EXPECTED);
            $marked->each(fn (Stay $stay) => $this->stays->markNoShow($stay));
            $this->becomeNoShow($reservation);

            EventLogger::record($reservation, 'no_show_marked', changes: ['stays' => $marked->modelKeys()], reason: $reason);

            return $this->result($reservation, $live);
        });
    }

    /**
     * One room never arrived (FR-006). Allowed while other rooms are in the
     * house; that changes only the room. The reservation follows when it was
     * the last live room still waiting (FR-008).
     *
     * @return array{reservation: Reservation, stays: Collection<int, Stay>, warnings: list<string>}
     */
    public function markStay(Stay $stay, ?string $reason = null): array
    {
        return DB::transaction(function () use ($stay, $reason) {
            [$reservation, $stays] = $this->lock($stay->reservationOrFail());
            $stay = $stays->get($stay->id);

            if ($stay->status === StayStatus::NO_SHOW) {
                return $this->result($reservation, collect([$stay]));
            }

            $reasons = $this->arrivalReasons($reservation);

            if (! in_array($reservation->status, [ReservationStatus::PENDING, ReservationStatus::CONFIRMED, ReservationStatus::CHECKED_IN], true)) {
                $reasons['stays'][] = "Reservation is {$reservation->status->value}; its rooms can't be marked as no-shows.";
            }

            if (! $stay->isLive() || $stay->status !== StayStatus::EXPECTED) {
                $reasons['stays'][] = 'This room is '.($stay->isLive() ? $stay->status->value : 'cancelled').'; only a room waiting to check in can be a no-show.';
            }

            $this->throwIf($reasons);

            // The locked collection holds this very instance, now updated.
            $this->stays->markNoShow($stay);

            if ($stays->filter(fn (Stay $other) => $other->isLive())->every(fn (Stay $other) => $other->status === StayStatus::NO_SHOW)) {
                $this->becomeNoShow($reservation);
            }

            EventLogger::record($reservation, 'no_show_marked', changes: ['stays' => [$stay->id]], reason: $reason);

            return $this->result($reservation, collect([$stay]));
        });
    }

    /**
     * Restores a no-show reservation, at any time (FR-009): back to the status
     * it had, every no-show room back to waiting and in step with the
     * reservation as it is now, availability checked again.
     *
     * @return array{reservation: Reservation, stays: Collection<int, Stay>, warnings: list<string>}
     */
    public function undoReservation(Reservation $reservation, ?string $reason = null, bool $overbook = false): array
    {
        return DB::transaction(function () use ($reservation, $reason, $overbook) {
            [$reservation, $stays] = $this->lock($reservation);
            $live = $stays->filter(fn (Stay $stay) => $stay->isLive());

            if ($reservation->status !== ReservationStatus::NO_SHOW) {
                throw CheckInOutException::for(['status' => ["Reservation is {$reservation->status->value}, not a no-show."]]);
            }

            $before = $this->availability->footprint($reservation);
            $restored = $live->filter(fn (Stay $stay) => $stay->status === StayStatus::NO_SHOW);

            $this->restoreStatus($reservation);
            $warnings = $this->restoreStays($reservation, $restored);
            $this->availability->guard($reservation, $before, $overbook);

            EventLogger::record($reservation, 'no_show_undone', changes: ['stays' => $restored->modelKeys()], reason: $reason);

            return $this->result($reservation, $live, $warnings);
        });
    }

    /**
     * Restores one no-show room while the reservation is still open; once it
     * is checked out the room's no-show is final (clarification 2026-10-21),
     * and a cancelled reservation or line has nothing to restore into.
     *
     * @return array{reservation: Reservation, stays: Collection<int, Stay>, warnings: list<string>}
     */
    public function undoStay(Stay $stay, ?string $reason = null, bool $overbook = false): array
    {
        return DB::transaction(function () use ($stay, $reason, $overbook) {
            [$reservation, $stays] = $this->lock($stay->reservationOrFail());
            $stay = $stays->get($stay->id);

            if ($stay->status === StayStatus::EXPECTED && $stay->isLive()) {
                return $this->result($reservation, collect([$stay]));
            }

            if ($reservation->status === ReservationStatus::CHECKED_OUT) {
                throw CheckInOutException::for(['stays' => ['This reservation is checked out; its no-show rooms are final.']]);
            }

            if (! in_array($reservation->status, [ReservationStatus::PENDING, ReservationStatus::CONFIRMED, ReservationStatus::CHECKED_IN, ReservationStatus::NO_SHOW], true)) {
                throw CheckInOutException::for(['stays' => ["Reservation is {$reservation->status->value}; its rooms can't be restored."]]);
            }

            if (! $stay->isLive()) {
                throw CheckInOutException::for(['stays' => ['This room was cancelled; there is nothing to restore.']]);
            }

            if ($stay->status !== StayStatus::NO_SHOW) {
                throw CheckInOutException::for(['stays' => ["This room is {$stay->status->value}, not a no-show."]]);
            }

            $before = $this->availability->footprint($reservation);

            if ($reservation->status === ReservationStatus::NO_SHOW) {
                $this->restoreStatus($reservation);
            }

            $warnings = $this->restoreStays($reservation, collect([$stay]));
            $this->availability->guard($reservation, $before, $overbook);

            EventLogger::record($reservation, 'no_show_undone', changes: ['stays' => [$stay->id]], reason: $reason);

            return $this->result($reservation, collect([$stay]), $warnings);
        });
    }

    /**
     * Puts no-show rooms back to waiting. While they were no-shows their room
     * could be given to someone else (RoomAssignmentRules skips them): a room
     * now booked for overlapping nights comes off the line, with a warning,
     * so the same room never sits on two bookings. Then the stays catch up
     * with the reservation as it is now (dates, room, revenue share), which
     * the sync skipped while they were no-shows.
     *
     * @param  Collection<int|string, Stay>  $restored
     * @return list<string>
     */
    private function restoreStays(Reservation $reservation, Collection $restored): array
    {
        $warnings = [];

        foreach ($restored as $stay) {
            $line = ReservationRoom::withoutGlobalScope('hotel')->find($stay->reservation_room_id);
            $room = $line?->room_id ? Room::withoutGlobalScope('hotel')->find($line->room_id) : null;

            if ($room && ($clash = RoomAssignmentRules::reason($line, $room))) {
                // Through the model, so the change is audited.
                $line->update(['room_id' => null]);
                $warnings[] = "{$clash} It was taken off this booking; assign another room before check-in.";
            }

            $this->stays->markExpected($stay);
        }

        $this->stays->syncForReservation($reservation);

        return $warnings;
    }

    private function becomeNoShow(Reservation $reservation): void
    {
        $reservation->forceFill([
            'status_before_no_show' => $reservation->status,
            'status' => ReservationStatus::NO_SHOW,
        ])->save();
    }

    private function restoreStatus(Reservation $reservation): void
    {
        $to = $reservation->status_before_no_show ?? ReservationStatus::CONFIRMED;

        if (! ReservationLifecycle::allows(ReservationStatus::NO_SHOW, $to, ReservationLifecycle::UNDO_NO_SHOW)) {
            $to = ReservationStatus::CONFIRMED;
        }

        $reservation->forceFill(['status' => $to, 'status_before_no_show' => null])->save();
    }

    /**
     * Room types first, as every reservation write does
     * (AvailabilityService::lockTypes()), then the reservation, its lines and
     * stays (ReservationLock): the order ReservationCreator::update() uses, so
     * a no-show and an edit of the same reservation wait for each other.
     *
     * @return array{Reservation, Collection<string, Stay>}
     */
    private function lock(Reservation $reservation): array
    {
        $this->availability->lockTypes(
            $reservation->hotel_id,
            ReservationRoom::withoutGlobalScope('hotel')->where('reservation_id', $reservation->id)->pluck('room_type_id')->all(),
        );

        return ReservationLock::lock($reservation);
    }

    /**
     * A guest can't fail to arrive before their arrival day (hotel time).
     *
     * @return array<string, list<string>>
     */
    private function arrivalReasons(Reservation $reservation): array
    {
        $today = FrontDeskLists::today(Hotel::findOrFail($reservation->hotel_id));

        return $reservation->arrival_date->toDateString() > $today
            ? ['stays' => ["The guest is due on {$reservation->arrival_date->toDateString()}; a no-show can be marked from the arrival day."]]
            : [];
    }

    /**
     * @param  array<string, list<string>>  $reasons
     */
    private function throwIf(array $reasons): void
    {
        if ($reasons !== []) {
            throw CheckInOutException::for($reasons, collect($reasons)->flatten()->first());
        }
    }

    /**
     * @param  Collection<string|int, Stay>  $stays
     * @param  list<string>  $warnings
     * @return array{reservation: Reservation, stays: Collection<int, Stay>, warnings: list<string>}
     */
    private function result(Reservation $reservation, Collection $stays, array $warnings = []): array
    {
        return [
            'reservation' => $reservation->fresh(),
            'stays' => $stays->map(fn (Stay $stay) => $stay->fresh())->values(),
            'warnings' => $warnings,
        ];
    }
}
