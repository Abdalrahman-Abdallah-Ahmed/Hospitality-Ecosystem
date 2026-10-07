<?php

namespace App\Ai\Tools;

use App\Ai\Tools\Concerns\ResolvesGuestStay;
use App\Models\Guest;
use App\Models\Hotel;
use App\Models\Reservation;
use App\Services\GuestRequestService;
use App\Support\Pitching\PitchTurn;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Ai\Contracts\Tool;
use Laravel\Ai\Tools\Request;
use Stringable;

/**
 * Passes a guest's wish for another room to staff (SPEC-007, R4). The
 * Concierge can never move a guest: staff decide, and carry out any move with
 * room assignment. This tool changes no room, stay or reservation line.
 */
class RequestRoomChangeTool implements Tool
{
    use ResolvesGuestStay;

    public function __construct(
        private readonly Guest $guest,
        private readonly Hotel $hotel,
        private readonly ?Reservation $reservation = null,
        private readonly ?PitchTurn $pitchTurn = null,
    ) {}

    public function description(): Stringable|string
    {
        return 'Ask hotel staff to move the guest to another room, with the reason and any preference. This does not move them: staff decide and contact the guest. Never promise a move.';
    }

    public function handle(Request $request): Stringable|string
    {
        if ($refusal = $this->requiresActiveReservation()) {
            return $refusal;
        }

        $roomNumber = $request->string('room_number')->toString();

        if ($ask = $this->askWhichRoom($roomNumber)) {
            return $ask;
        }

        ['task' => $task, 'created' => $created] = app(GuestRequestService::class)->requestRoomChange(
            $this->guest,
            $this->hotel,
            $this->activeReservation(),
            $this->stayFor($roomNumber),
            $request->string('reason')->toString(),
            $request->string('preference')->toString() ?: null,
        );

        // The guest is unhappy with their room: nothing may be pitched in this reply.
        $this->pitchTurn?->markBlockingRequest();

        return $created
            ? "Room-change request passed to staff (task id: {$task->id}). Tell the guest staff will decide and contact them; do not promise a move."
            : "A room-change request is already with staff (task id: {$task->id}).";
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'reason' => $schema->string()->description('Why the guest wants to move, in their words.')->required(),
            'preference' => $schema->string()->description('What they would prefer, if they said (e.g. "higher floor", "quieter side").'),
            'room_number' => $schema->string()
                ->description('Which of their rooms, only if the guest is staying in more than one room and said which.'),
        ];
    }
}
