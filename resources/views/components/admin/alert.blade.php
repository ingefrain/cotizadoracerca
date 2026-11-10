@props(['variant' => 'info', 'announce' => true])
@php
    [$label, $icon] = match ($variant) {
        'success' => ['Éxito', '✓'],
        'warning' => ['Advertencia', '!'],
        'error' => ['Error', '×'],
        default => ['Información', 'i'],
    };
@endphp
<div {{ $attributes->class(['alert', 'alert-'.$variant]) }} @if($announce) role="{{ $variant === 'error' ? 'alert' : 'status' }}" @endif>
    <span class="alert-icon" aria-hidden="true"><span>{{ $icon }}</span></span>
    <div><span class="sr-only">{{ $label }}: </span>{{ $slot }}</div>
    {{ $actions ?? '' }}
</div>
