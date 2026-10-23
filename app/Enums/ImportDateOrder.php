<?php

namespace App\Enums;

/**
 * How a numeric date like "12/09/2025" is read, chosen per file at upload
 * (FR-008a). ISO dates and named months are never ambiguous and ignore it.
 */
enum ImportDateOrder: string
{
    case DAY_FIRST = 'day_first';
    case MONTH_FIRST = 'month_first';
}
