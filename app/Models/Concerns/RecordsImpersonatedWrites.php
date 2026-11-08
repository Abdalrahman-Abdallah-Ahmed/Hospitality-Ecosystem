<?php

namespace App\Models\Concerns;

use App\Models\Hotel;
use App\Support\Audit\EventLogger;
use App\Support\Impersonation\CurrentImpersonation;
use Illuminate\Database\Eloquent\Model;

/**
 * For a model that is not audited but that an impersonation session can write:
 * during a session each write records one event, naming the super admin, with
 * the changed field names but never their values (spec 027 FR-019b). Outside a
 * session it does nothing, so no other caller's writes are recorded.
 */
trait RecordsImpersonatedWrites
{
    /**
     * Set while a delete is in progress: the hotel settings it will clear.
     *
     * @var list<string>
     */
    private array $hotelSettingsBeingCleared = [];

    public static function bootRecordsImpersonatedWrites(): void
    {
        static::created(fn (Model $model) => self::recordImpersonatedWrite($model, 'created', array_keys($model->getAttributes())));

        static::updated(function (Model $model): void {
            $fields = array_keys($model->getChanges());

            // Only a timestamp moved, so nothing was changed.
            if (array_diff($fields, ['updated_at']) !== []) {
                self::recordImpersonatedWrite($model, 'updated', $fields);
            }
        });

        // Read before the row goes: the database clears these itself, and no
        // Hotel model event fires for that.
        static::deleting(function (Model $model): void {
            $columns = $model->hotelSettingsClearedOnDelete();

            if ($columns === [] || ! CurrentImpersonation::token()) {
                return;
            }

            $hotel = Hotel::find($model->getAttribute('hotel_id'));

            $model->hotelSettingsBeingCleared = array_keys(array_filter(
                $hotel?->only($columns) ?? [],
                fn ($value) => $value === $model->getKey(),
            ));
        });

        static::deleted(function (Model $model): void {
            self::recordImpersonatedWrite($model, 'deleted', []);

            if ($model->hotelSettingsBeingCleared !== []) {
                self::recordImpersonatedWrite(Hotel::find($model->getAttribute('hotel_id')), 'updated', $model->hotelSettingsBeingCleared);
            }
        });
    }

    /**
     * The hotel settings columns that point at this record, which its deletion
     * clears through a foreign key.
     *
     * @return list<string>
     */
    protected function hotelSettingsClearedOnDelete(): array
    {
        return [];
    }

    /**
     * @param  list<string>  $fields
     */
    private static function recordImpersonatedWrite(Model $model, string $verb, array $fields): void
    {
        if (CurrentImpersonation::token()) {
            EventLogger::recordSessionWrite($model, $verb, array_values(array_diff($fields, ['id', 'created_at', 'updated_at'])));
        }
    }
}
