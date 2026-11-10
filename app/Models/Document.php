<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Str;

class Document extends Model
{
    use SoftDeletes;

    protected $fillable = [
        'uuid', 'category', 'original_name', 'stored_name', 'disk', 'path',
        'mime_type', 'extension', 'size_bytes', 'checksum', 'title', 'description',
        'uploaded_by', 'metadata',
    ];

    protected function casts(): array
    {
        return ['metadata' => 'array', 'size_bytes' => 'integer'];
    }

    protected static function booted(): void
    {
        static::creating(function (Document $document): void {
            $document->uuid ??= (string) Str::uuid();
        });
    }

    public function documentable(): MorphTo
    {
        return $this->morphTo();
    }

    public function uploader(): BelongsTo
    {
        return $this->belongsTo(User::class, 'uploaded_by');
    }

    public function getRouteKeyName(): string
    {
        return 'uuid';
    }

    public function scopeSearch(Builder $query, string $term): Builder
    {
        $escaped = addcslashes($term, '%_\\');

        return $query->where(function (Builder $builder) use ($escaped, $term): void {
            $builder->where('uuid', $term)
                ->orWhere('original_name', 'like', "%{$escaped}%")
                ->orWhere('title', 'like', "%{$escaped}%")
                ->orWhere('category', 'like', "%{$escaped}%");
        });
    }

    public function safeOriginalName(): string
    {
        $name = preg_replace('/[\x00-\x1F\x7F]/u', '', $this->original_name) ?? '';
        $name = str_replace(['/', '\\'], '-', $name);

        return trim($name) !== '' ? trim($name) : 'documento';
    }

    public function humanSize(): string
    {
        $bytes = max(0, $this->size_bytes);

        if ($bytes < 1024) {
            return $bytes.' B';
        }

        return $bytes >= 1048576
            ? number_format($bytes / 1048576, 1).' MB'
            : number_format($bytes / 1024, 0).' KB';
    }
}
