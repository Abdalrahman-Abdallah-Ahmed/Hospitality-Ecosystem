<?php

namespace App\Models;

use App\Enums\ConversationChannel;
use App\Enums\ConversationStatus;
use App\Models\Concerns\Filterable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Illuminate\Database\Eloquent\SoftDeletes;

class Conversation extends Model
{
    use Filterable, SoftDeletes;

    protected $keyType = 'string';

    public $incrementing = false;

    protected $fillable = [
        'id',
        'sender_id',
        'sender_type',
        'hotel_id',
        'reservation_id',
        'status',
        'channel',
        'started_at',
        'closed_at',
        'context',
    ];

    protected $casts = [
        'status' => ConversationStatus::class,
        'channel' => ConversationChannel::class,
        'started_at' => 'datetime',
        'closed_at' => 'datetime',
        'context' => 'array',
    ];

    public function sender(): MorphTo
    {
        return $this->morphTo();
    }

    public function hotel(): BelongsTo
    {
        return $this->belongsTo(Hotel::class);
    }

    public function reservation(): BelongsTo
    {
        return $this->belongsTo(Reservation::class);
    }

    public function messages(): HasMany
    {
        return $this->hasMany(Message::class);
    }

    public function recommendations(): HasMany
    {
        return $this->hasMany(Recommendation::class);
    }
}
