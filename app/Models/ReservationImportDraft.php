<?php

namespace App\Models;

use App\Concerns\BelongsToHotel;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

/**
 * A mapped and resolved reservation waiting for the write phase, in file
 * order (`position`). Deleted as soon as it is written, and all of an
 * import's drafts go when the import ends, so guest contact data in the
 * payload never outlives the run.
 */
class ReservationImportDraft extends Model
{
    use BelongsToHotel, HasUuids;

    protected $keyType = 'string';

    public $incrementing = false;

    protected $fillable = ['hotel_id', 'reservation_import_id', 'position', 'payload'];

    protected $casts = [
        'position' => 'integer',
        'payload' => 'array',
    ];
}
