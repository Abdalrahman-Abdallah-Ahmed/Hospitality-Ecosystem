<?php

namespace App\Support\Import\Reservations;

use RuntimeException;

/**
 * One reservation of an import could not be written for a reason staff can
 * act on. The message completes "Could not be saved: …".
 */
class DraftRejected extends RuntimeException {}
