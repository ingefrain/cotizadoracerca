<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class ImportRun extends Model
{
    use HasUuids;

    protected $guarded = ['id', 'uuid'];

    protected $hidden = ['disk', 'path', 'checksum_sha256'];

    protected function casts(): array
    {
        return ['validated_at' => 'datetime', 'applied_at' => 'datetime', 'expires_at' => 'datetime'];
    }

    public function uniqueIds(): array
    {
        return ['uuid'];
    }

    public function getRouteKeyName(): string
    {
        return 'uuid';
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function errors(): HasMany
    {
        return $this->hasMany(ImportRowError::class);
    }

    public function sourcePath(): string
    {
        return 'imports/'.$this->uuid.'.csv';
    }

    public function statusLabel(): string
    {
        return match ($this->status) {
            'uploaded' => 'Cargada',
            'validating' => 'Validando',
            'invalid' => 'Con errores',
            'ready' => 'Lista para aplicar',
            'applying' => 'Aplicando',
            'completed' => 'Aplicada',
            'failed' => 'Fallida',
            'expired' => 'Vencida',
        };
    }

    public function canBeDeleted(): bool
    {
        return in_array($this->status, ['uploaded', 'invalid', 'ready', 'failed', 'expired'], true)
            && $this->applied_at === null && (int) $this->applied_rows === 0;
    }
}
