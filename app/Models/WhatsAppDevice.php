<?php

namespace App\Models;

use App\Concerns\BelongsToHotel;
use App\Models\Concerns\Filterable;
use Illuminate\Database\Eloquent\Model;

class WhatsAppDevice extends Model
{
    use BelongsToHotel, Filterable;

    /**
     * The Sanctum token name pairing codes are issued under. A token with
     * this name redeems a pairing and nothing else — AppServiceProvider
     * refuses it as an API credential.
     */
    public const PAIRING_TOKEN_NAME = 'whatsapp_device_token';

    protected $fillable = [
        'user_id',
        'phone_number',
        'hotel_id',
        'wa_user_id',
        'status',
    ];

    public function user()
    {
        return $this->belongsTo(User::class);
    }
}
