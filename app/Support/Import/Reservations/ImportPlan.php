<?php

namespace App\Support\Import\Reservations;

use App\Enums\ImportRowOutcome;
use App\Enums\ImportStage;

/**
 * What the prepare phase decided for a file: a result for every data row,
 * and the drafts (one per reservation still to be written) in file order.
 * The mapper builds it and the resolver refines it; a dry run reports it as
 * it stands.
 *
 * A row result is `{row_number, external_reference, outcome, stage, fields,
 * reason, warnings, reservation_id}`; `outcome` stays null while the row's
 * reservation waits in a draft. A draft is keyed by its first row number.
 */
class ImportPlan
{
    /** @var array<int, array<string, mixed>> row number => result */
    public array $rows = [];

    /** @var array<int, array<string, mixed>> first row number => draft */
    public array $drafts = [];

    /** @var list<string> */
    public array $createdRooms = [];

    /** @var list<string> */
    public array $createdRoomTypes = [];

    public function addRow(int $rowNumber, ?string $reference): void
    {
        $this->rows[$rowNumber] = [
            'row_number' => $rowNumber,
            'external_reference' => $reference,
            'outcome' => null,
            'stage' => ImportStage::PARSE,
            'fields' => [],
            'reason' => null,
            'warnings' => [],
            'reservation_id' => null,
        ];
    }

    /**
     * Gives every row in `$rowNumbers` its final outcome, and drops their
     * draft if they had one.
     *
     * @param  list<int>  $rowNumbers
     * @param  list<string>  $fields
     */
    public function settle(array $rowNumbers, ImportRowOutcome $outcome, ImportStage $stage, ?string $reason = null, array $fields = [], ?string $reservationId = null): void
    {
        foreach ($rowNumbers as $rowNumber) {
            $this->rows[$rowNumber] = [
                ...$this->rows[$rowNumber],
                'outcome' => $outcome,
                'stage' => $stage,
                'reason' => $reason,
                'fields' => array_values(array_unique($fields)),
                'reservation_id' => $reservationId,
            ];
        }

        unset($this->drafts[$rowNumbers[0] ?? 0]);
    }

    /** @param list<int> $rowNumbers */
    public function stage(array $rowNumbers, ImportStage $stage): void
    {
        foreach ($rowNumbers as $rowNumber) {
            $this->rows[$rowNumber]['stage'] = $stage;
        }
    }

    public function warn(int $rowNumber, string $code, string $message): void
    {
        $this->rows[$rowNumber]['warnings'][] = ['code' => $code, 'message' => $message];
    }

    public function noteCreatedRoom(string $roomNumber): void
    {
        if (! in_array($roomNumber, $this->createdRooms, true)) {
            $this->createdRooms[] = $roomNumber;
        }
    }

    public function noteCreatedRoomType(string $name): void
    {
        foreach ($this->createdRoomTypes as $existing) {
            if (mb_strtolower($existing) === mb_strtolower($name)) {
                return;
            }
        }

        $this->createdRoomTypes[] = $name;
    }
}
