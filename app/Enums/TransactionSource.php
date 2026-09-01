<?php

namespace App\Enums;

/**
 * Where a transaction row came from. Recorded on every ledger entry so a
 * disputed figure can be traced back to its origin system.
 */
enum TransactionSource: string
{
    case ELEANOR = 'eleanor';
    case OPERA = 'opera';
    case MANUAL = 'manual';   // hand-entered, and the source of every reversal
    case IMPORT = 'import';   // a generic spreadsheet upload with no named origin system
}
