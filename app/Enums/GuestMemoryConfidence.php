<?php

namespace App\Enums;

/**
 * Only medium and high are ever stored; low is what the extractor may
 * answer and is discarded (SPEC-084 FR-013).
 */
enum GuestMemoryConfidence: string
{
    case LOW = 'low';
    case MEDIUM = 'medium';
    case HIGH = 'high';

    public function rank(): int
    {
        return match ($this) {
            self::LOW => 1,
            self::MEDIUM => 2,
            self::HIGH => 3,
        };
    }
}
