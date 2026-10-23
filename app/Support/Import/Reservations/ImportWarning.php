<?php

namespace App\Support\Import\Reservations;

use App\Support\Import\PhoneNormalizer;

/**
 * The warning codes an import row can carry (data-model.md). A warning is
 * reported but never changes the row's outcome.
 */
final class ImportWarning
{
    public const NO_PHONE = 'no_phone';

    public const NO_ROOM_ASSIGNED = 'no_room_assigned';

    public const ROOM_CLASH = 'room_clash';

    public const DATA_DIFFERS = 'data_differs';

    public const RESTORED = 'restored';

    public const PHONE_COUNTRY_UNKNOWN = PhoneNormalizer::WARNING_COUNTRY_UNKNOWN;

    public const PHONE_UNRECOGNIZED = PhoneNormalizer::WARNING_UNRECOGNIZED;

    public const BOOKING_UNRESOLVED = 'booking_unresolved';

    public const STATUS_CORRECTED = 'status_corrected';
}
