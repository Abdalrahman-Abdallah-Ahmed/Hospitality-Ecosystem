<?php

namespace App\Models;

use App\Concerns\BelongsToHotel;
use App\Enums\VoucherLanguage;
use App\Models\Concerns\Filterable;
use App\Models\Concerns\RecordsEvents;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * A hotel's voucher design (SPEC-013): a single-page image with a layout of
 * field boxes placed on it as fractions of the image size. The file columns
 * are written only by VoucherTemplateService.
 */
class VoucherTemplate extends Model
{
    use BelongsToHotel, Filterable, HasUuids, RecordsEvents, SoftDeletes;

    protected $keyType = 'string';

    public $incrementing = false;

    protected $attributes = [
        'is_default' => false,
        'layout' => '[]',
    ];

    protected $fillable = [
        'name',
        'language',
        'is_default',
        'layout',
    ];

    public array $searchable = ['name'];

    protected $casts = [
        'language' => VoucherLanguage::class,
        'is_default' => 'boolean',
        'layout' => 'array',
        'size_bytes' => 'integer',
        'width_px' => 'integer',
        'height_px' => 'integer',
    ];

    public function eventLoggedAttributes(): array
    {
        return ['name', 'language', 'is_default', 'mime_type', 'width_px', 'height_px'];
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function scopeDefaultFor(Builder $query, VoucherLanguage $language): Builder
    {
        return $query->where('language', $language->value)->where('is_default', true);
    }
}
