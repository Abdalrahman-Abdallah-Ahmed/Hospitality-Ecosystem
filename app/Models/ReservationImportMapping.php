<?php

namespace App\Models;

use App\Concerns\BelongsToHotel;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

/**
 * A hotel's last confirmed import column mapping (016): normalized header
 * text => canonical field, or null for a column left unmapped on purpose,
 * plus the header row. Suggested for the hotel's next upload.
 */
class ReservationImportMapping extends Model
{
    use BelongsToHotel, HasUuids;

    protected $keyType = 'string';

    public $incrementing = false;

    protected $fillable = [
        'hotel_id',
        'header_row',
        'columns',
        'updated_by',
    ];

    protected $casts = [
        'header_row' => 'integer',
        'columns' => 'array',
    ];
}
