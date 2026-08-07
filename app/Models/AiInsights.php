<?php

namespace App\Models;

use App\Models\Concerns\Filterable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

class AiInsights extends Model
{
    use Filterable, HasUuids;

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
    ];

    public function insightable()
    {
        return $this->morphTo();
    }
}
