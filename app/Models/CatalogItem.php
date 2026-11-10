<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use Illuminate\Database\Eloquent\Relations\MorphOne;

class CatalogItem extends Model
{
    use HasFactory;
    use HasUuids;

    public const IMAGE_CATEGORY = 'catalog_item.image';

    protected $fillable = ['name', 'slug', 'code', 'description_markdown', 'is_active', 'sort_order', 'metadata'];

    protected function casts(): array
    {
        return ['is_active' => 'boolean', 'metadata' => 'array'];
    }

    public function uniqueIds(): array
    {
        return ['uuid'];
    }

    public function getRouteKeyName(): string
    {
        return 'uuid';
    }

    public function catalog(): BelongsTo
    {
        return $this->belongsTo(Catalog::class);
    }

    public function documents(): MorphMany
    {
        return $this->morphMany(Document::class, 'documentable');
    }

    public function image(): MorphOne
    {
        return $this->morphOne(Document::class, 'documentable')
            ->ofMany(['id' => 'max'], fn ($query) => $query->where('category', self::IMAGE_CATEGORY));
    }

    public function isOperationallyActive(): bool
    {
        return $this->is_active && $this->catalog->isOperationallyActive();
    }
}
