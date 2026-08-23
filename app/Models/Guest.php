<?php

namespace App\Models;

use App\Models\Concerns\Filterable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Laravel\Ai\Models\Conversation;
use Laravel\Ai\Models\ConversationMessage;

class Guest extends Model
{
    use Filterable, HasUuids, SoftDeletes;

    protected $keyType = 'string';

    public $incrementing = false;

    protected $fillable = [
        'hotel_id',
        'first_name',
        'last_name',
        'email',
        'phone_number',
        'preferred_language',
        'nationality',
        'preferences',
        'loyalty_status',
        'marketing_consent',
        'external_id',
        'channel',
    ];

    protected $casts = [
        'preferences' => 'array',
        'marketing_consent' => 'boolean',
    ];

    public function hotel(): BelongsTo
    {
        return $this->belongsTo(Hotel::class);
    }

    public function reservations(): HasMany
    {
        return $this->hasMany(Reservation::class);
    }

    public function conversations(): HasMany
    {
        return $this->hasMany(Conversation::class, 'participant_id', 'id')
            ->where('participant_type', $this->getMorphClass());
    }

    /**
     * Scope agent conversation messages to those authored by a guest of the given hotel.
     */
    public static function hotelConversationMessagesQuery(Hotel $hotel): Builder
    {
        $morphType = (new self)->getMorphClass();

        return ConversationMessage::query()
            ->where('role', 'user')
            ->where('participant_type', $morphType)
            ->whereHas('conversation', fn ($query) => $query
                ->where('participant_type', $morphType)
                ->whereIn('participant_id', self::query()
                    ->where('hotel_id', $hotel->id)
                    ->select('id')));
    }
}
