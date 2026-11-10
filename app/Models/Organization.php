<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use Illuminate\Database\Eloquent\Relations\MorphOne;
use Illuminate\Support\Str;

class Organization extends Model
{
    use HasFactory;

    protected $fillable = [
        'uuid', 'name', 'legal_name', 'slug', 'email', 'phone', 'website', 'tax_id', 'notes', 'is_active',
    ];

    protected function casts(): array
    {
        return ['is_active' => 'boolean'];
    }

    protected static function booted(): void
    {
        static::creating(function (Organization $organization): void {
            $organization->uuid ??= (string) Str::uuid();
        });
    }

    public function locations(): HasMany
    {
        return $this->hasMany(Location::class);
    }

    public function documents(): MorphMany
    {
        return $this->morphMany(Document::class, 'documentable');
    }

    public function logo(): MorphOne
    {
        return $this->morphOne(Document::class, 'documentable')
            ->where('category', 'organization.logo')
            ->latestOfMany();
    }

    public function getRouteKeyName(): string
    {
        return 'uuid';
    }
}
