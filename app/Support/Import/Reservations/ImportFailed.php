<?php

namespace App\Support\Import\Reservations;

use RuntimeException;

/**
 * Stops a whole import before any row is processed (an unreadable file,
 * missing required columns). The message is shown to staff as the import's
 * failure reason, so it must read as an instruction, not an internal error.
 */
class ImportFailed extends RuntimeException {}
