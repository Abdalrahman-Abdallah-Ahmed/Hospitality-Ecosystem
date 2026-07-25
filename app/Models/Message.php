<?php

namespace App\Models;

use App\Enums\MessageDeliveryStatus;
use App\Enums\MessageType;
use App\Models\Concerns\Filterable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;

class Message extends Model
{
    use Filterable, HasUuids;

    protected $keyType = 'string';

    public $incrementing = false;

    protected $fillable = [
        'conversation_id',
        'sender_id',
        'sender_type',
        'reservation_id',
        'content',
        'message_type',
        'is_ai_generated',
        'delivery_status',
        'sent_at',
    ];

    protected $casts = [
        'message_type' => MessageType::class,
        'delivery_status' => MessageDeliveryStatus::class,
        'is_ai_generated' => 'boolean',
        'sent_at' => 'datetime',
    ];

    public function conversation(): BelongsTo
    {
        return $this->belongsTo(Conversation::class);
    }

    public function sender(): MorphTo
    {
        return $this->morphTo();
    }

    public function reservation(): BelongsTo
    {
        return $this->belongsTo(Reservation::class);
    }
}
