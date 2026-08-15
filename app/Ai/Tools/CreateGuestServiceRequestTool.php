<?php

namespace App\Ai\Tools;

use App\Enums\CreatedBy;
use App\Enums\Priority;
use App\Models\Guest;
use App\Models\Hotel;
use App\Models\Reservation;
use App\Models\Task;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Ai\Contracts\Tool;
use Laravel\Ai\Tools\Request;
use Stringable;

class CreateGuestServiceRequestTool implements Tool
{
    public function __construct(
        private readonly Guest $guest,
        private readonly Hotel $hotel,
        private readonly ?Reservation $reservation = null,
    ) {}

    /**
     * Get the description of the tool's purpose.
     */
    public function description(): Stringable|string
    {
        return "Create a service request task for hotel staff on the guest's behalf (e.g. extra towels, a maintenance issue, a housekeeping request).";
    }

    /**
     * Execute the tool.
     */
    public function handle(Request $request): Stringable|string
    {
        $task = Task::create([
            'hotel_id' => $this->hotel->id,
            'guest_id' => $this->guest->id,
            'reservation_id' => $this->reservation?->id,
            'title' => $request->string('title')->toString(),
            'description' => $request->string('description')->toString(),
            'created_by' => CreatedBy::GUEST,
            'priority' => Priority::NORMAL,
        ]);

        return "Service request created (task id: {$task->id}). Staff will follow up.";
    }

    /**
     * Get the tool's schema definition.
     */
    public function schema(JsonSchema $schema): array
    {
        return [
            'title' => $schema->string()->description('A short title for the request.')->required(),
            'description' => $schema->string()->description('What the guest is asking for.')->required(),
        ];
    }
}
