<?php

namespace App\Support\Import\Reservations;

/**
 * Validate stage: the rules one row can be checked against on its own.
 * Rules that need the whole reservation (dates present, party against
 * guests, ages against children, status against dates) are the mapper's,
 * once rows are grouped.
 */
class RowValidator
{
    public function validate(NormalizedRow $row): NormalizedRow
    {
        if ($row->blank) {
            return $row;
        }

        // Dates may be left empty on the further rows of a reservation; the
        // mapper requires them once the rows are merged.
        if ($row->arrival && $row->departure && $row->departure->lte($row->arrival)) {
            $row->error('departure_date', "The departure ({$row->departure->toDateString()}) must be after the arrival ({$row->arrival->toDateString()}).");
        }

        foreach ($row->guests as $guest) {
            $this->validateGuest($row, $guest);
        }

        return $row;
    }

    /**
     * FR-019a: a guest needs something to be recognized by. A guest with no
     * phone is imported, but cannot be recognized on WhatsApp until staff add
     * one, so the report says so.
     *
     * @param  array<string, mixed>  $guest
     */
    private function validateGuest(NormalizedRow $row, array $guest): void
    {
        if ($guest['guest_id'] !== null) {
            return;
        }

        $name = trim(($guest['first_name'] ?? '').' '.($guest['last_name'] ?? '')) ?: 'A guest';

        if ($guest['phone'] === null && $guest['email'] === null && $guest['external_id'] === null) {
            $row->error($guest['field'] === 'guest' ? 'guest_phone' : $guest['field'], "{$name} has no phone, email or guest id. Add at least one.");

            return;
        }

        if ($guest['phone'] === null) {
            $row->warn(ImportWarning::NO_PHONE, "{$name} has no phone number, so they cannot be recognized on WhatsApp until staff add one.");
        }
    }
}
