<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use Illuminate\Database\Eloquent\Relations\MorphOne;
use Throwable;

class ApplicationBranding extends Model
{
    protected $table = 'application_brandings';

    protected $fillable = ['visible_name'];

    public function documents(): MorphMany
    {
        return $this->morphMany(Document::class, 'documentable');
    }

    public function logo(): MorphOne
    {
        return $this->morphOne(Document::class, 'documentable')->where('category', 'branding.logo')->latestOfMany();
    }

    public function displayName(): string
    {
        return filled($this->visible_name) ? $this->visible_name : (string) config('app.name', 'Cotizador Acerca');
    }

    public static function current(): self
    {
        try {
            return static::query()->with('logo')->find(1) ?? new self(['id' => 1]);
        } catch (Throwable) {
            return new self(['id' => 1]);
        }
    }
}
