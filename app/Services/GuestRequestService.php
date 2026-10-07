<?php

namespace App\Services;

use App\Enums\CreatedBy;
use App\Enums\GuestSignal;
use App\Enums\Priority;
use App\Enums\TaskStatus;
use App\Models\Guest;
use App\Models\Hotel;
use App\Models\Reservation;
use App\Models\Stay;
use App\Models\Task;
use App\Support\Audit\EventLogger;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Guest requests the Concierge files on a guest's behalf (SPEC-007):
 * escalations to a person, room-change requests, and extra detail added to a
 * request that is already open.
 *
 * Every query names the hotel: the Concierge runs without tenant context.
 * The open-request rules (one escalation per guest, one room change per stay)
 * are enforced here and by partial unique indexes, so two messages racing
 * cannot both create one.
 */
class GuestRequestService
{
    public function __construct(
        private readonly CreationNotificationService $notifications,
    ) {}

    /**
     * The guest needs a person (SPEC-054, R5). One open escalation per guest
     * per hotel: asking again adds the new reason to it, without a second
     * task or a second email to the admins. Returns the escalation and
     * whether it is new.
     *
     * @return array{task: Task, created: bool}
     */
    public function escalate(Guest $guest, Hotel $hotel, ?Reservation $reservation, string $reason): array
    {
        try {
            return DB::transaction(function () use ($guest, $hotel, $reservation, $reason): array {
                if ($open = $this->openEscalation($guest, $hotel, lock: true)) {
                    $this->appendToDescription($open, $reason, $hotel);
                    EventLogger::record($open, 'escalation_repeated', changes: ['reason' => $reason]);

                    return ['task' => $open, 'created' => false];
                }

                $task = Task::make([
                    'hotel_id' => $hotel->id,
                    'guest_id' => $guest->id,
                    // Only a current or upcoming stay: a past guest's old
                    // reservation would point staff at the wrong visit, and
                    // the recognised one may be cancelled while another is live.
                    'reservation_id' => ($reservation?->isActive() ? $reservation : Reservation::activeFor($hotel, $guest))?->id,
                    'title' => 'Guest needs human assistance',
                    'description' => $reason,
                    'created_by' => CreatedBy::AI,
                    'status' => TaskStatus::PENDING,
                    'priority' => Priority::HIGH,
                ]);
                // Why the task exists; pitching stays quiet for the rest of the stay.
                $task->guest_signal = GuestSignal::ESCALATION;
                $task->save();

                DB::afterCommit(fn () => $this->notifications->taskCreated($task, createdByAi: true));

                return ['task' => $task, 'created' => true];
            });
        } catch (QueryException $e) {
            // Another message won the race to the unique index.
            if ($e->getCode() !== '23505' || ! ($open = $this->openEscalation($guest, $hotel))) {
                throw $e;
            }

            $this->appendToDescription($open, $reason, $hotel);
            EventLogger::record($open, 'escalation_repeated', changes: ['reason' => $reason]);

            return ['task' => $open, 'created' => false];
        }
    }

    /**
     * The guest would like another room (SPEC-007, R4). A request for staff,
     * never a move: the stay's room and the reservation's lines are untouched,
     * and staff carry out any move with room assignment. No team: every staff
     * member who can see the hotel's tasks sees it, and the admins are
     * emailed. One open request per stay, or per reservation before arrival.
     *
     * @return array{task: Task, created: bool}
     */
    public function requestRoomChange(Guest $guest, Hotel $hotel, Reservation $reservation, ?Stay $stay, string $reason, ?string $preference): array
    {
        try {
            return DB::transaction(function () use ($guest, $hotel, $reservation, $stay, $reason, $preference): array {
                if ($open = $this->openRoomChange($guest, $hotel, $reservation, $stay, lock: true)) {
                    return ['task' => $open, 'created' => false];
                }

                $roomNumber = $stay?->room?->room_number;
                $preference = $preference !== null && trim($preference) !== '' ? trim($preference) : null;

                $task = Task::make([
                    'hotel_id' => $hotel->id,
                    'guest_id' => $guest->id,
                    'reservation_id' => $reservation->id,
                    'stay_id' => $stay?->id,
                    'room_id' => $stay?->room_id,
                    'title' => 'Room change request'.($roomNumber ? " (room {$roomNumber})" : ''),
                    'description' => trim($reason).($preference ? "\nPreference: {$preference}" : ''),
                    'created_by' => CreatedBy::GUEST,
                    'status' => TaskStatus::PENDING,
                    'priority' => $guest->is_vip ? Priority::HIGH : Priority::NORMAL,
                ]);
                $task->guest_signal = GuestSignal::ROOM_CHANGE_REQUEST;
                $task->save();

                EventLogger::record($task, 'room_change_requested', changes: ['reason' => $reason, 'preference' => $preference]);

                DB::afterCommit(fn () => $this->notifications->taskCreated($task, createdByAi: true));

                return ['task' => $task, 'created' => true];
            });
        } catch (QueryException $e) {
            // Another message won the race to the unique index.
            if ($e->getCode() !== '23505' || ! ($open = $this->openRoomChange($guest, $hotel, $reservation, $stay))) {
                throw $e;
            }

            return ['task' => $open, 'created' => false];
        }
    }

    /**
     * Add what the guest just said to one of their own open requests of the
     * same kind (R14), instead of filing a duplicate. Null when the id is not
     * such a request, so the caller files a new one.
     */
    public function appendDetail(string $taskId, string $detail, Guest $guest, Hotel $hotel, GuestSignal $kind): ?Task
    {
        // The id comes from the model: anything that is not a uuid would make
        // Postgres reject the query and fail the whole turn.
        if (! Str::isUuid($taskId)) {
            return null;
        }

        return DB::transaction(function () use ($taskId, $detail, $guest, $hotel, $kind): ?Task {
            $task = Task::ownedByGuest($hotel, $guest)
                ->where('guest_signal', $kind->value)
                ->open()
                ->lockForUpdate()
                ->find($taskId);

            if (! $task) {
                return null;
            }

            $this->appendToDescription($task, $detail, $hotel);
            EventLogger::record($task, 'request_detail_added', changes: ['detail' => $detail]);

            return $task;
        });
    }

    /**
     * The open room-change request for this stay, or for the reservation when
     * the guest is not in yet (the same key as the partial unique index).
     */
    private function openRoomChange(Guest $guest, Hotel $hotel, Reservation $reservation, ?Stay $stay, bool $lock = false): ?Task
    {
        return Task::ownedByGuest($hotel, $guest)
            ->where('guest_signal', GuestSignal::ROOM_CHANGE_REQUEST->value)
            ->when(
                $stay,
                fn ($query) => $query->where('stay_id', $stay->id),
                fn ($query) => $query->whereNull('stay_id')->where('reservation_id', $reservation->id),
            )
            ->open()
            ->when($lock, fn ($query) => $query->lockForUpdate())
            ->first();
    }

    private function openEscalation(Guest $guest, Hotel $hotel, bool $lock = false): ?Task
    {
        return Task::ownedByGuest($hotel, $guest)
            ->where('guest_signal', GuestSignal::ESCALATION->value)
            ->open()
            ->when($lock, fn ($query) => $query->lockForUpdate())
            ->first();
    }

    /**
     * Appends a timestamped paragraph, in the hotel's time zone.
     */
    private function appendToDescription(Task $task, string $text, Hotel $hotel): void
    {
        $stamp = now($hotel->timezone)->format('Y-m-d H:i');
        $current = trim((string) $task->description);

        $task->description = ($current === '' ? '' : $current."\n\n")."[{$stamp}] ".trim($text);
        $task->save();
    }
}
