@php
    $types = ['success', 'info', 'warning', 'error'];
    $flash = session('notify');
    $type = is_array($flash) && in_array($flash['type'] ?? null, $types, true) ? $flash['type'] : 'info';
    $message = is_array($flash) && is_string($flash['message'] ?? null) ? $flash['message'] : '';
@endphp

<div class="admin-notify-stack" data-notify-stack></div>
<div class="sr-only" role="status" aria-atomic="true" data-notify-polite></div>
<div class="sr-only" role="alert" aria-atomic="true" data-notify-urgent></div>

@foreach($types as $variant)
    <template data-notify-template="{{ $variant }}">
        <x-admin.alert :variant="$variant" :announce="false" class="admin-notify-item">
            <span data-notify-message></span>
            <x-slot:actions>
                <button type="button" class="admin-notify-close" aria-label="Cerrar notificación" data-notify-close>×</button>
            </x-slot:actions>
        </x-admin.alert>
    </template>
@endforeach

@if($message !== '')
    <x-admin.alert :variant="$type" :announce="false" data-notify-flash="{{ $type }}">
        <span data-notify-message>{{ $message }}</span>
    </x-admin.alert>
@endif
