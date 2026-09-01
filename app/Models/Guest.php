<?php

namespace App\Models;

use App\Concerns\BelongsToHotel;
use App\Models\Concerns\Filterable;
use App\Models\Concerns\RecordsEvents;
use App\Services\GuestIdentityService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Laravel\Ai\Models\Conversation;
use Laravel\Ai\Models\ConversationMessage;

class Guest extends Model
{
    use BelongsToHotel, Filterable, HasUuids, RecordsEvents, SoftDeletes;

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
        'identity_resolved_at' => 'datetime',
    ];

    protected static function booted(): void
    {
        // Recompute the identity fingerprint whenever the fields it's
        // derived from change, so nobody has to remember to call
        // GuestIdentityService by hand at every creation site.
        static::saving(function (Guest $guest) {
            if ($guest->isDirty(['email', 'phone_number'])) {
                $guest->identity_hash = app(GuestIdentityService::class)->computeIdentityHash($guest);
                $guest->identity_resolved_at = $guest->identity_hash ? now() : null;
            }
        });
    }

    /**
     * The identity fingerprint (identity_hash) is deliberately excluded — it
     * is derived, not entered, and adds noise to the trail.
     *
     * @return array<int, string>
     */
    public function eventLoggedAttributes(): array
    {
        return [
            'first_name', 'last_name', 'email', 'phone_number',
            'preferred_language', 'nationality', 'preferences', 'loyalty_status',
            'marketing_consent', 'external_id', 'channel', 'master_guest_id',
        ];
    }

    public function reservations(): HasMany
    {
        return $this->hasMany(Reservation::class);
    }

    public function stays(): HasMany
    {
        return $this->hasMany(Stay::class);
    }

    public function transactions(): HasMany
    {
        return $this->hasMany(Transaction::class);
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
