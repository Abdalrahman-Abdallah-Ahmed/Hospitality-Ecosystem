<?php

namespace App\Http\Requests;

use App\Http\Requests\Generic\GenericIndexRequest;
use App\Models\Task;

/**
 * The maintenance list: the generic task listing, plus a filter on whether
 * the task's room is out of order (FR-031).
 */
class MaintenanceTaskIndexRequest extends GenericIndexRequest
{
    protected function modelClass(): string
    {
        return Task::class;
    }

    /**
     * @return array<int, string>
     */
    protected function filterColumns(): array
    {
        return [...parent::filterColumns(), 'room_out_of_order'];
    }
}
