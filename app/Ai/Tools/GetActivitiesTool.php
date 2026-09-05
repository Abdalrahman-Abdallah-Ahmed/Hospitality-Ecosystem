<?php

namespace App\Ai\Tools;

use App\Models\Activity;
use App\Models\Hotel;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Ai\Contracts\Tool;
use Laravel\Ai\Tools\Request;
use Stringable;

class GetActivitiesTool implements Tool
{
    public function __construct(
        private readonly Hotel $hotel
    ) {}

    /**
     * Get the description of the tool's purpose.
     */
    public function description(): Stringable|string
    {
        return 'Retrieve the activities offered by the current hotel, including each activity\'s category, name, description, and price.';
    }

    /**
     * Execute the tool.
     */
    public function handle(Request $request): Stringable|string
    {
        $activities = Activity::where('hotel_id', $this->hotel->id)
            ->active()
            ->with('category')
            ->get()
            ->map(fn (Activity $activity) => [
                'id' => $activity->id,
                'category' => $activity->category?->name,
                'name' => $activity->name,
                'description' => $activity->description,
                'price' => $activity->price,
                'currency' => $activity->currency,
            ])
            ->values();

        return json_encode($activities);
    }

    /**
     * Get the tool's schema definition.
     */
    public function schema(JsonSchema $schema): array
    {
        return [];
    }
}
