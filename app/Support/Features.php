<?php

namespace App\Support;

use InvalidArgumentException;

/**
 * The platform feature switches in config/features.php, read in one place so
 * every route, resource and report that shows a feature's data asks the same
 * question (spec 019).
 *
 * An unknown name throws instead of reading as "off": a typo would otherwise
 * quietly close a route group everywhere.
 */
final class Features
{
    public const TRANSACTIONS = 'transactions';

    public static function enabled(string $feature): bool
    {
        $features = config('features', []);

        if (! array_key_exists($feature, $features)) {
            throw new InvalidArgumentException("Unknown feature [{$feature}]; add it to config/features.php.");
        }

        return (bool) $features[$feature];
    }
}
