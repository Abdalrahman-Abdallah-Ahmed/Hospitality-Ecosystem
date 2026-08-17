<?php

namespace App\Ai\Tools;

use App\Models\Hotel;
use App\Models\TaskCategory;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Ai\Contracts\Tool;
use Laravel\Ai\Tools\Request;
use Stringable;

class GetTaskCategoriesTool implements Tool
{
    public function __construct(
        private readonly Hotel $hotel,
    ) {}

    /**
     * Get the description of the tool's purpose.
     */
    public function description(): Stringable|string
    {
        return "Retrieve this hotel's task categories (e.g. Housekeeping, Maintenance), including which team each belongs to. Use this to find a category id before creating a task.";
    }

    /**
     * Execute the tool.
     */
    public function handle(Request $request): Stringable|string
    {
        $categories = TaskCategory::where('hotel_id', $this->hotel->id)
            ->with('team')
            ->get()
            ->map(fn (TaskCategory $category) => [
                'id' => $category->id,
                'name' => $category->name,
                'description' => $category->description,
                'team' => $category->team?->name,
            ])
            ->values();

        return json_encode($categories);
    }

    /**
     * Get the tool's schema definition.
     */
    public function schema(JsonSchema $schema): array
    {
        return [];
    }
}
