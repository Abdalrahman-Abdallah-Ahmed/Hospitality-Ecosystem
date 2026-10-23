<?php

namespace App\Enums;

/**
 * What happened to one data row of an import (FR-025). Every row of a
 * reservation shares its outcome.
 */
enum ImportRowOutcome: string
{
    case IMPORTED = 'imported';     // written (or, on a dry run, would be)
    case SKIPPED = 'skipped';       // a data row with no values at all
    case DUPLICATE = 'duplicate';   // the reservation already exists at the hotel
    case INVALID = 'invalid';       // the data breaks a rule; the reason names it
    case UNRESOLVED = 'unresolved'; // the guest could not be identified safely
}
