<?php

namespace App\Services\Reports;

use App\Enums\Priority;
use App\Enums\RoomStatusesEnum;
use App\Models\Hotel;
use App\Models\Task;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;

/**
 * The maintenance list (SPEC-033, FR-031): tasks for the hotel's Maintenance
 * team or in its categories. Shared by the maintenance endpoint and the Admin
 * AI (SPEC-055 R4).
 */
class MaintenanceList
{
    /**
     * The hotel's maintenance tasks, optionally only those whose room is (or
     * is not) out of order.
     */
    public function query(Hotel $hotel, ?bool $roomOutOfOrder = null): Builder
    {
        $teamId = $hotel->maintenance_team_id;

        // No Maintenance team means no maintenance tasks — not every task
        // without a team, which is what matching a null team would give.
        $query = Task::query()
            ->where('tasks.hotel_id', $hotel->id)
            ->when($teamId === null, fn (Builder $query) => $query->whereRaw('false'))
            ->when($teamId !== null, fn (Builder $query) => $query->where(fn (Builder $query) => $query
                ->where('assigned_to_team_id', $teamId)
                ->orWhereHas('taskCategory', fn (Builder $category) => $category->where('team_id', $teamId))))
            ->with(['room', 'createdByUser']);

        if ($roomOutOfOrder !== null) {
            $query->whereHas('room', fn (Builder $room) => $roomOutOfOrder
                ? $room->where('status', RoomStatusesEnum::OUT_OF_ORDER)
                : $room->where('status', '!=', RoomStatusesEnum::OUT_OF_ORDER));
        }

        return $query;
    }

    /**
     * Open first, most urgent first, oldest first.
     */
    public function defaultOrder(Builder $query): Builder
    {
        $priorities = array_map(fn (Priority $priority) => $priority->value, [Priority::HIGH, Priority::NORMAL, Priority::LOW]);

        return $query->orderByRaw("CASE WHEN status IN ('pending', 'in_progress') THEN 0 ELSE 1 END")
            ->orderByRaw('array_position(?::text[], priority::text)', ['{'.implode(',', $priorities).'}'])
            ->orderBy('created_at');
    }

    /**
     * The room part of a maintenance row: number, status and, when out of
     * order, why, until when, and whether that date has passed.
     *
     * @return array<string, mixed>|null
     */
    public function roomSummary(Task $task, Hotel $hotel): ?array
    {
        if (! $task->room) {
            return null;
        }

        $today = CarbonImmutable::now($hotel->timezone)->toDateString();

        return [
            'id' => $task->room->id,
            'room_number' => $task->room->room_number,
            'status' => $task->room->status,
            'out_of_order' => $task->room->isOutOfOrder() ? [
                'reason' => $task->room->out_of_order_reason,
                'expected_end_date' => $task->room->out_of_order_until?->toDateString(),
                'overdue' => $task->room->out_of_order_until !== null
                    && $task->room->out_of_order_until->toDateString() < $today,
            ] : null,
        ];
    }
}
