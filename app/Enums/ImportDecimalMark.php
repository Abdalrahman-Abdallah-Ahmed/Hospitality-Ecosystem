<?php

namespace App\Enums;

/**
 * How an amount's "." and "," are read, chosen per file (016, FR-017).
 * AUTO keeps the rule that refuses to guess "1,200" and "1.200"; DOT and
 * COMMA name the decimal mark, so the other one separates thousands.
 */
enum ImportDecimalMark: string
{
    case AUTO = 'auto';
    case DOT = 'dot';
    case COMMA = 'comma';
}
