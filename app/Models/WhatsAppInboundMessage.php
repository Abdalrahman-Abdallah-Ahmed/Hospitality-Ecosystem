<?php

namespace App\Models;

use App\Enums\InboundMessageStatus;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * An inbound WhatsApp message and the reply it got.
 *
 * Deliberately not BelongsToHotel: rows are written by the webhook, which
 * has no tenant context, and the sender's hotel is not always known (an
 * unrecognised number has none).
 */
class WhatsAppInboundMessage extends Model
{
    use HasUuids, SoftDeletes;

    protected $table = 'whatsapp_inbound_messages';

    protected $keyType = 'string';

    public $incrementing = false;

    protected $fillable = [
        'wamid',
        'phone_number',
        'message_type',
        'hotel_id',
        'status',
        'reply_text',
        'generation_started_at',
        'replied_at',
    ];

    protected $casts = [
        'status' => InboundMessageStatus::class,
        'generation_started_at' => 'datetime',
        'replied_at' => 'datetime',
    ];
}
