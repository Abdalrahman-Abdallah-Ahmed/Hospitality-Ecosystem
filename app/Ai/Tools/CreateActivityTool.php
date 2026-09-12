<?php

namespace App\Ai\Tools;

use App\Models\Activity;
use App\Models\ActivityCategory;
use App\Models\Hotel;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Ai\Contracts\Tool;
use Laravel\Ai\Tools\Request;
use Stringable;

class CreateActivityTool implements Tool
{
    public function __construct(
        private readonly Hotel $hotel,
    ) {}

    public function description(): Stringable|string
    {
        return 'Add an activity this hotel offers — a tour, excursion, spa treatment, or similar. Once created it becomes available to recommend to guests, so only create activities the hotel actually offers.';
    }

    public function handle(Request $request): Stringable|string
    {
        $name = $request->string('name')->trim()->toString();

        if ($name === '') {
            return 'An activity name is required.';
        }

        $activity = Activity::create([
            'hotel_id' => $this->hotel->id,
            'category_id' => $this->categoryId($request),
            'name' => $name,
            'description' => $request->string('description')->toString() ?: null,
            'price' => $request->float('price', 0),
            'currency' => $request->string('currency')->toString() ?: $this->hotel->currency,
            'is_active' => $request->boolean('is_active', true),
        ]);

        return "Activity \"{$activity->name}\" created (activity id: {$activity->id}) at {$activity->price} {$activity->currency}. It can now be recommended to guests.";
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'name' => $schema->string()->description('The name of the activity.')->required(),
            'description' => $schema->string()->description('What the activity involves — this is what guests will be told about it.'),
            'price' => $schema->number()->description('Price per guest. Use 0 for a complimentary activity.'),
            'currency' => $schema->string()->description("3-letter currency code. Defaults to the hotel's own currency."),
            'category_id' => $schema->string()
                ->description('The id of the activity category this belongs to, if one clearly fits. Look the categories up first rather than guessing an id.'),
            'is_active' => $schema->boolean()
                ->description('Whether the activity is currently offered. Defaults to true.')
                ->default(true),
        ];
    }

    /**
     * A category from another hotel would leak one property's taxonomy into
     * another's, so an id that does not belong here is dropped rather than
     * honoured — the activity is still created, just uncategorised.
     */
    private function categoryId(Request $request): ?string
    {
        if (! $request->filled('category_id')) {
            return null;
        }

        return ActivityCategory::withoutGlobalScope('hotel')
            ->where('hotel_id', $this->hotel->id)
            ->find($request->string('category_id')->toString())
            ?->id;
    }
}
