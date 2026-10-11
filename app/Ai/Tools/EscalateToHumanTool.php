<?php

namespace App\Ai\Tools;

use App\Ai\Tools\Contracts\WritesData;
use App\Enums\EscalationRequestType;
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
 * The guest needs a person (SPEC-054). Always available, also to a guest with
 * only past stays. One open escalation per guest: asking again adds to it.
 */
class EscalateToHumanTool implements Tool, WritesData
{
    public function __construct(
        private readonly Guest $guest,
        private readonly Hotel $hotel,
        private readonly ?Reservation $reservation = null,
        private readonly ?PitchTurn $pitchTurn = null,
    ) {}

    /**
     * Get the description of the tool's purpose.
     */
    public function description(): Stringable|string
    {
        return 'Flag this conversation for a staff member to take over directly — use when the guest is frustrated, asking for something outside what you can help with, explicitly asks for a human, or hotel policy says the topic needs a person.';
    }

    /**
     * Execute the tool.
     */
    public function handle(Request $request): Stringable|string
    {
        ['created' => $created] = app(GuestRequestService::class)->escalate(
            $this->guest,
            $this->hotel,
            $this->reservation,
            $request->string('reason')->toString(),
            $request->enum('request_type', EscalationRequestType::class),
            $request->filled('detail') ? $request->string('detail')->toString() : null,
        );

        // Nothing may be pitched in the reply to a guest who needed a human.
        $this->pitchTurn?->markBlockingRequest();

        return $created
            ? 'A staff member has been notified and will follow up with the guest directly.'
            : "Staff already have this guest's request for a person; the new detail was added to it.";
    }

    /**
     * Get the tool's schema definition.
     */
    public function schema(JsonSchema $schema): array
    {
        return [
            'reason' => $schema->string()->description('Why this conversation needs a human.')->required(),
            'request_type' => $schema->string()
                ->enum(EscalationRequestType::class)
                ->description('Only when the guest asks what you remember about them (see_memories) or asks you to forget or correct something you remember (forget_memory).'),
            'detail' => $schema->string()
                ->description('For forget_memory: what the guest asked you to forget or correct, in a few words.'),
        ];
    }
}
