<?php

namespace App\Support;

use App\Enums\SenderType;
use App\Models\Reservation;
use Illuminate\Database\Eloquent\Model;

final class RecognizedSender
{
    public function __construct(
        public readonly SenderType $type,
        public readonly ?Model $sender = null,
        public readonly ?string $hotelId = null,
        public readonly ?Reservation $reservation = null,
        public readonly bool $devicePaired = false,
    ) {}
}
