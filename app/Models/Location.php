<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use Illuminate\Support\Str;

class Location extends Model
{
    use HasFactory;

    protected $fillable = [
        'uuid', 'organization_id', 'name', 'code', 'email', 'phone', 'address_line_1', 'address_line_2',
        'city', 'state', 'postal_code', 'country_code', 'latitude', 'longitude', 'is_active',
    ];

    protected function casts(): array
    {
        return ['is_active' => 'boolean', 'latitude' => 'decimal:7', 'longitude' => 'decimal:7'];
    }

    protected static function booted(): void
    {
        static::creating(function (Location $location): void {
            $location->uuid ??= (string) Str::uuid();
        });
    }

    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    public function documents(): MorphMany
    {
        return $this->morphMany(Document::class, 'documentable');
    }

    public function isOperationallyActive(): bool
    {
        return $this->is_active && (bool) $this->organization?->is_active;
    }

    public function getRouteKeyName(): string
    {
        return 'uuid';
    }
}
