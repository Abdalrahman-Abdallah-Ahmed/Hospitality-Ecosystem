<?php

namespace App\Support\Export;

use App\Models\Activity;

/**
 * The activity catalogue as a spreadsheet (spec 021).
 */
class ActivitiesExport extends ModuleExport
{
    public function title(): string
    {
        return 'Activities';
    }

    protected function relations(): array
    {
        return ['category'];
    }

    protected function dateColumns(): array
    {
        return ['Available From', 'Available Until'];
    }

    protected function dateTimeColumns(): array
    {
        return ['Created At'];
    }

    protected function columns(): array
    {
        return [
            'Name' => fn (Activity $a) => $a->name,
            'Category' => fn (Activity $a) => $a->category?->name,
            'Description' => fn (Activity $a) => $a->description,
            'Price' => fn (Activity $a) => $this->amount($a->price),
            'Currency' => fn (Activity $a) => $a->currency,
            'Active' => fn (Activity $a) => $this->yesNo($a->is_active),
            'Available From' => fn (Activity $a) => $a->available_from,
            'Available Until' => fn (Activity $a) => $a->available_until,
            'Audience' => fn (Activity $a) => $this->label($a->audience),
            'Duration (Days)' => fn (Activity $a) => $a->duration_days,
            'Daily Capacity' => fn (Activity $a) => $a->daily_capacity,
            'Created At' => fn (Activity $a) => $a->created_at,
        ];
    }
}
