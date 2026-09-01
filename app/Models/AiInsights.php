<?php

namespace App\Models;

use App\Concerns\BelongsToHotel;
use App\Enums\EvidenceLevel;
use App\Models\Concerns\Filterable;
use App\Models\Concerns\RecordsEvents;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

class AiInsights extends Model
{
    use BelongsToHotel, Filterable, HasUuids, RecordsEvents;

    protected $keyType = 'string';

    public $incrementing = false;

    protected $fillable = [
        'hotel_id',
        'insightable_id',
        'insightable_type',
        'title',
        'description',
        'category',
        'insight_type',
        'evidence_level',
        'evidence_sources',
    ];

    protected $casts = [
        'evidence_level' => EvidenceLevel::class,
        'evidence_sources' => 'array',
    ];

    /**
     * @return array<int, string>
     */
    public function eventLoggedAttributes(): array
    {
        return [
            'insightable_id', 'insightable_type', 'title', 'description',
            'category', 'insight_type', 'evidence_level',
        ];
    }

    public function insightable()
    {
        return $this->morphTo();
    }
}
