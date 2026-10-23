<?php

namespace App\Support\Import\Reservations;

use App\Enums\ReservationStatus;
use Carbon\CarbonImmutable;

/**
 * One data row after the normalize stage: values in the platform's form,
 * plus the problems found so far. Errors are keyed by the file column they
 * concern and make the row's reservation invalid; warnings are reported but
 * do not change the outcome.
 */
class NormalizedRow
{
    /** @var array<string, string> column => staff-readable message */
    public array $errors = [];

    /** @var list<array{code: string, message: string}> */
    public array $warnings = [];

    /**
     * @param  list<int>|null  $childrenAges  null when the file gives none
     * @param  list<array{first_name: ?string, last_name: ?string, phone: ?string, email: ?string, external_id: ?string, guest_id: ?string, is_primary: ?bool}>  $guests
     * @param  array{name: string, date: ?CarbonImmutable, time: ?string, pax: ?int}|null  $activity
     */
    public function __construct(
        public readonly int $rowNumber,
        public readonly bool $blank,
        public ?string $reference = null,
        public ?CarbonImmutable $arrival = null,
        public ?CarbonImmutable $departure = null,
        public ?ReservationStatus $status = null,
        public bool $statusGiven = false,
        public ?string $statusLabel = null,
        public ?string $source = null,
        public ?int $adults = null,
        public ?int $children = null,
        public ?array $childrenAges = null,
        public ?string $specialRequests = null,
        public ?float $reservationValue = null,
        public ?string $currency = null,
        public ?string $roomNumber = null,
        public ?string $roomType = null,
        public ?string $floor = null,
        public ?int $roomCount = null,
        public array $guests = [],
        public ?array $activity = null,
    ) {}

    public function error(string $field, string $message): void
    {
        $this->errors[$field] ??= $message;
    }

    public function warn(string $code, string $message): void
    {
        $this->warnings[] = ['code' => $code, 'message' => $message];
    }
}
