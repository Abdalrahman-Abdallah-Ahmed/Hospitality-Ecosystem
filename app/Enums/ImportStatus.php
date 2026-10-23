<?php

namespace App\Enums;

/**
 * Where a reservation import run is (FR-003). Completed and failed are final:
 * re-uploading the file is the retry.
 */
enum ImportStatus: string
{
    case QUEUED = 'queued';         // uploaded, waiting for its turn
    case RUNNING = 'running';       // preparing or writing
    case COMPLETED = 'completed';   // every row has an outcome
    case FAILED = 'failed';         // stopped; failure_reason says why

    public function isFinished(): bool
    {
        return in_array($this, [self::COMPLETED, self::FAILED], true);
    }
}
