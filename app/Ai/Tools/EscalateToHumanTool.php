<?php

namespace App\Ai\Tools;

use App\Enums\CreatedBy;
use App\Enums\Priority;
use App\Models\Guest;
use App\Models\Hotel;
use App\Models\Task;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Ai\Contracts\Tool;
use Laravel\Ai\Tools\Request;
use Stringable;

class EscalateToHumanTool implements Tool
{
    public function __construct(
        private readonly Guest $guest,
        private readonly Hotel $hotel,
    ) {}

    /**
     * Get the description of the tool's purpose.
     */
    public function description(): Stringable|string
    {
        return 'Flag this conversation for a staff member to take over directly — use when the guest is frustrated, asking for something outside what you can help with, or explicitly asks for a human.';
    }

    /**
     * Execute the tool.
     */
    public function handle(Request $request): Stringable|string
    {
        $reason = $request->string('reason')->toString();

        Task::create([
            'hotel_id' => $this->hotel->id,
            'guest_id' => $this->guest->id,
            'title' => 'Guest needs human assistance',
            'description' => $reason,
            'created_by' => CreatedBy::AI,
            'priority' => Priority::HIGH,
        ]);

        return 'A staff member has been notified and will follow up with the guest directly.';
    }

    /**
     * Get the tool's schema definition.
     */
    public function schema(JsonSchema $schema): array
    {
        return [
            'reason' => $schema->string()->description('Why this conversation needs a human.')->required(),
        ];
    }
}
