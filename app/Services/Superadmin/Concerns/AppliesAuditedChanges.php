<?php

namespace App\Services\Superadmin\Concerns;

use App\Support\Audit\EventLogger;
use Illuminate\Database\Eloquent\Model;

trait AppliesAuditedChanges
{
    /**
     * Save these attributes and list what changed, as from/to pairs for a
     * platform event. An empty list means nothing changed, and so nothing
     * should be recorded. Only pass fields that are safe to keep in the audit
     * trail, never a password.
     *
     * @param  array<string, mixed>  $attributes
     * @return array<string, array{from: mixed, to: mixed}>
     */
    protected function applyChanges(Model $model, array $attributes): array
    {
        $model->fill($attributes);

        $dirty = array_keys($model->getDirty());
        $before = [];

        foreach ($dirty as $field) {
            $before[$field] = EventLogger::normalize($model->getOriginal($field));
        }

        $model->save();

        $changes = [];

        foreach ($dirty as $field) {
            $changes[$field] = ['from' => $before[$field], 'to' => EventLogger::normalize($model->getAttribute($field))];
        }

        return $changes;
    }
}
