<?php

namespace App\Support;

use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Validation\Rules\Unique;

final class CatalogFields
{
    public static function validate(Request $request, Unique $slug, array $extra): array
    {
        $rawSlug = $request->input('slug');
        $name = $request->input('name');
        if ($rawSlug === null && is_string($name)) {
            $request->merge(['slug' => Str::limit(Str::slug(Str::limit($name, 180, '')), 140, '')]);
        } elseif (is_string($rawSlug)) {
            $request->merge(['slug' => Str::lower($rawSlug)]);
        }

        return $request->validate([
            'name' => ['required', 'string', 'max:180'],
            'slug' => ['required', 'string', 'alpha_dash:ascii', 'max:140', $slug],
            'description_markdown' => ['nullable', 'string', 'max:16000'],
            'sort_order' => ['nullable', 'integer', 'between:-2147483648,2147483647'],
            ...$extra,
        ], [
            'slug.required' => 'Escribe un slug si el nombre no permite generarlo.',
            'slug.unique' => 'Este slug ya está en uso.',
            'code.unique' => 'Este código ya está en uso en el catálogo.',
        ]);
    }

    public static function filters(Request $request, array $extra = []): array
    {
        return $request->validate([
            'q' => ['nullable', 'string', 'max:120'],
            'status' => ['nullable', 'in:active,inactive'],
            ...$extra,
        ]);
    }
}
