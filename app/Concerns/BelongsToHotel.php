<?php

namespace App\Concerns;

use App\Support\Tenancy\TenantContext;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

trait BelongsToHotel
{
    public static function bootBelongsToHotel(): void
    {
        // Every query on this model is automatically filtered to the current hotel.
        // null = unrestricted (super admin, or no tenant context at all — jobs/webhooks
        // opt in explicitly via TenantContext::runForHotel()); [] = restricted to zero
        // hotels, which must match nothing, not everything.
        static::addGlobalScope('hotel', function (Builder $builder) {
            $hotelIds = TenantContext::hotelIds();

            if ($hotelIds !== null) {
                $builder->whereIn($builder->getModel()->getTable().'.hotel_id', $hotelIds);
            }
        });

        // Every new record is automatically stamped with the current hotel.
        static::creating(function ($model) {
            if (empty($model->hotel_id) && $hotelId = TenantContext::currentHotelId()) {
                $model->hotel_id = $hotelId;
            }
        });
    }

    public function hotel(): BelongsTo
    {
        return $this->belongsTo(\App\Models\Hotel::class);
    }
}

