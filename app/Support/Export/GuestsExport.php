<?php

namespace App\Support\Export;

use App\Enums\ReservationChannels;
use App\Models\Guest;

/**
 * The guest list as a spreadsheet (spec 021). Contact details are included
 * in full: the export permission is the control.
 */
class GuestsExport extends ModuleExport
{
    public function title(): string
    {
        return 'Guests';
    }

    protected function relations(): array
    {
        return [];
    }

    protected function dateTimeColumns(): array
    {
        return ['Created At'];
    }

    protected function columns(): array
    {
        return [
            'First Name' => fn (Guest $g) => $g->first_name,
            'Last Name' => fn (Guest $g) => $g->last_name,
            'Email' => fn (Guest $g) => $g->email,
            'Phone' => fn (Guest $g) => $g->phone_number,
            'Preferred Language' => fn (Guest $g) => $g->preferred_language,
            'Nationality' => fn (Guest $g) => $g->nationality,
            'Loyalty Status' => fn (Guest $g) => $g->loyalty_status,
            'VIP' => fn (Guest $g) => $this->yesNo($g->is_vip),
            'Channel' => fn (Guest $g) => $this->label($g->channel, ReservationChannels::class),
            'External ID' => fn (Guest $g) => $g->external_id,
            'Created At' => fn (Guest $g) => $g->created_at,
        ];
    }
}
