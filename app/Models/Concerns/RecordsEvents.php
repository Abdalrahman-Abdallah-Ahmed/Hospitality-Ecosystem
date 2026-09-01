<?php

namespace App\Models\Concerns;

use App\Support\Audit\EventLogger;

/**
 * Automatically writes an entry to the audit trail (`event_log`) on every
 * create, update, and delete of the model, so nobody has to remember to log
 * a change by hand.
 *
 * A model using this trait MUST define eventLoggedAttributes() — the explicit
 * allow-list of columns whose changes are safe to record. It is an allow-list
 * on purpose: a block-list eventually misses a newly added sensitive field.
 */
trait RecordsEvents
{
    public static function bootRecordsEvents(): void
    {
        static::created(function ($model): void {
            EventLogger::record($model, 'created', $model->loggedChangeSet(withFrom: false));
        });

        static::updated(function ($model): void {
            $changes = $model->loggedChangeSet(withFrom: true);

            if ($changes === []) {
                return;
            }

            EventLogger::record($model, $model->eventVerbFor('updated'), $changes);
        });

        static::deleted(function ($model): void {
            EventLogger::record($model, 'deleted');
        });
    }

    /**
     * The columns whose changes are recorded in `event_log.changes`. Override
     * per model; never include id, timestamps, deleted_at, hashes, or tokens.
     *
     * @return array<int, string>
     */
    abstract public function eventLoggedAttributes(): array;

    /**
     * The event verb for an update. Override to map a status transition to a
     * named event (see Stay). Default: the verb unchanged.
     */
    public function eventVerbFor(string $verb): string
    {
        return $verb;
    }

    /**
     * The allow-listed subset of what just changed, shaped as
     * { field: { from, to } } (or { field: { to } } on create), values
     * normalised for JSON.
     *
     * @return array<string, array<string, mixed>>
     */
    public function loggedChangeSet(bool $withFrom): array
    {
        $allowed = array_flip($this->eventLoggedAttributes());
        $changed = array_intersect_key($this->getChanges(), $allowed);

        $set = [];

        foreach ($changed as $key => $value) {
            $entry = ['to' => EventLogger::normalize($value)];

            if ($withFrom) {
                $entry['from'] = EventLogger::normalize($this->getOriginal($key));
            }

            $set[$key] = $entry;
        }

        return $set;
    }
}
