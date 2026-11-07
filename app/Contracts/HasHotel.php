<?php

namespace App\Contracts;

/**
 * A queued job that acts on one hotel. Null means no hotel, such as a global
 * knowledge document, or a record that has since been deleted.
 */
interface HasHotel
{
    public function hotelId(): ?string;
}
