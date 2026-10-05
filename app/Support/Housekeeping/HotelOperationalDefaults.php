<?php

namespace App\Support\Housekeeping;

use App\Models\Hotel;
use App\Models\TaskCategory;
use App\Models\Team;
use Illuminate\Support\Facades\DB;

/**
 * Gives a hotel its default Housekeeping and Maintenance teams and their
 * Cleaning, Inspection and Maintenance categories (SPEC-004, D7), and points
 * the hotel's settings at them.
 *
 * Only empty settings are filled: an admin's choice is never overwritten.
 * Idempotent, so it runs on every hotel creation and once for existing
 * hotels. Teams are unique by name per hotel, so an existing team of the
 * default name is reused rather than duplicated — the only lookup by name,
 * made once here; everything afterwards reads the ids.
 */
class HotelOperationalDefaults
{
    public static function ensure(Hotel $hotel): void
    {
        DB::transaction(function () use ($hotel): void {
            $changes = [];

            $housekeeping = self::team($hotel, $hotel->housekeeping_team_id, 'Housekeeping', $changes, 'housekeeping_team_id');
            $maintenance = self::team($hotel, $hotel->maintenance_team_id, 'Maintenance', $changes, 'maintenance_team_id');

            self::category($hotel, $housekeeping, $hotel->cleaning_task_category_id, 'Cleaning', $changes, 'cleaning_task_category_id');
            self::category($hotel, $housekeeping, $hotel->inspection_task_category_id, 'Inspection', $changes, 'inspection_task_category_id');
            self::category($hotel, $maintenance, $hotel->maintenance_task_category_id, 'Maintenance', $changes, 'maintenance_task_category_id');

            if ($changes !== []) {
                $hotel->forceFill($changes)->saveQuietly();
            }
        });
    }

    /**
     * @param  array<string, string>  $changes
     */
    private static function team(Hotel $hotel, ?string $currentId, string $name, array &$changes, string $setting): Team
    {
        $current = $currentId ? Team::withoutGlobalScope('hotel')->where('hotel_id', $hotel->id)->find($currentId) : null;

        if ($current) {
            return $current;
        }

        $team = Team::withoutGlobalScope('hotel')->where('hotel_id', $hotel->id)->where('name', $name)->first()
            ?? Team::withoutGlobalScope('hotel')->create(['hotel_id' => $hotel->id, 'name' => $name, 'is_active' => true]);

        $changes[$setting] = $team->id;

        return $team;
    }

    /**
     * @param  array<string, string>  $changes
     */
    private static function category(Hotel $hotel, Team $team, ?string $currentId, string $name, array &$changes, string $setting): void
    {
        if ($currentId && TaskCategory::withoutGlobalScope('hotel')->where('hotel_id', $hotel->id)->whereKey($currentId)->exists()) {
            return;
        }

        $category = TaskCategory::withoutGlobalScope('hotel')
            ->where('hotel_id', $hotel->id)
            ->where('team_id', $team->id)
            ->where('name', $name)
            ->first()
            ?? TaskCategory::withoutGlobalScope('hotel')->create(['hotel_id' => $hotel->id, 'team_id' => $team->id, 'name' => $name]);

        $changes[$setting] = $category->id;
    }
}
