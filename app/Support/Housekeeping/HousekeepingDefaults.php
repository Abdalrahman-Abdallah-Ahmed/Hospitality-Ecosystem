<?php

namespace App\Support\Housekeeping;

use App\Models\Hotel;
use App\Models\TaskCategory;
use App\Models\Team;

/**
 * Where a hotel's automatic housekeeping and maintenance tasks go: the teams
 * and task categories in its settings. Read by id, never by name, so teams
 * named in any language work. A missing, deleted or inactive choice gives
 * null and the task is created without it (FR-013).
 *
 * HotelOperationalDefaults fills these settings for every hotel.
 */
class HousekeepingDefaults
{
    /**
     * @return array{
     *     housekeepingTeam: ?Team,
     *     cleaningCategory: ?TaskCategory,
     *     inspectionCategory: ?TaskCategory,
     *     maintenanceTeam: ?Team,
     *     maintenanceCategory: ?TaskCategory,
     * }
     */
    public static function for(Hotel $hotel): array
    {
        return [
            'housekeepingTeam' => self::team($hotel, $hotel->housekeeping_team_id),
            'cleaningCategory' => self::category($hotel, $hotel->cleaning_task_category_id),
            'inspectionCategory' => self::category($hotel, $hotel->inspection_task_category_id),
            'maintenanceTeam' => self::team($hotel, $hotel->maintenance_team_id),
            'maintenanceCategory' => self::category($hotel, $hotel->maintenance_task_category_id),
        ];
    }

    private static function team(Hotel $hotel, ?string $id): ?Team
    {
        if (! $id) {
            return null;
        }

        return Team::withoutGlobalScope('hotel')
            ->where('hotel_id', $hotel->id)
            ->where('is_active', true)
            ->find($id);
    }

    private static function category(Hotel $hotel, ?string $id): ?TaskCategory
    {
        if (! $id) {
            return null;
        }

        return TaskCategory::withoutGlobalScope('hotel')
            ->where('hotel_id', $hotel->id)
            ->find($id);
    }
}
