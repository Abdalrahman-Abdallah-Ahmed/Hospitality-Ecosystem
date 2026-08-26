<?php

namespace App\Models;

use App\Concerns\BelongsToHotel;
use App\Models\Concerns\Filterable;
use Illuminate\Database\Eloquent\Model;

class WhatsAppDevice extends Model
{
    use BelongsToHotel, Filterable;

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
