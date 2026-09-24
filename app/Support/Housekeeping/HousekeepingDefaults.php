<?php

namespace App\Support\Housekeeping;

use App\Models\Hotel;
use App\Models\TaskCategory;
use App\Models\Team;

/**
 * Where a check-out's cleaning task goes: the team and task category the
 * hotel's admin chose (hotel settings). Read by id, never by name, so a team
 * named in any language works. A missing, deleted or inactive choice gives
 * null and the task is created without it (FR-013).
 *
 * SPEC-004: fills these hotel columns for every hotel when it creates the
 * default Housekeeping team and its categories.
 */
class HousekeepingDefaults
{
    /**
     * @return array{team: ?Team, category: ?TaskCategory}
     */
    public static function for(Hotel $hotel): array
    {
        $team = $hotel->housekeeping_team_id
            ? Team::withoutGlobalScope('hotel')
                ->where('hotel_id', $hotel->id)
                ->where('is_active', true)
                ->find($hotel->housekeeping_team_id)
            : null;

        $category = $hotel->cleaning_task_category_id
            ? TaskCategory::withoutGlobalScope('hotel')
                ->where('hotel_id', $hotel->id)
                ->find($hotel->cleaning_task_category_id)
            : null;

        return ['team' => $team, 'category' => $category];
    }
}
