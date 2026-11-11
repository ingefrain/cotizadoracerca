@props(['logo' => null, 'maintenance' => false])
@php
    $faviconService = app(\App\Services\BrandingFavicon::class);
    $favicon = $maintenance ? $faviconService->cached() : $faviconService->forLogo($logo);
@endphp
<link rel="icon" type="{{ str_starts_with($favicon, 'data:image/png;') ? 'image/png' : 'image/svg+xml' }}" sizes="{{ str_starts_with($favicon, 'data:image/png;') ? '64x64' : 'any' }}" href="{{ $favicon }}">
