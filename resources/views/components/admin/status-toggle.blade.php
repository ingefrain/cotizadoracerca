@props([
    'active', 'action' => null, 'label', 'activeLabel' => 'Activo',
    'inactiveLabel' => 'Inactivo', 'blockedNote' => null,
    'disabled' => false, 'demo' => null,
])

@php
    $pending = $demo === 'loading';
    $noteId = 'status-note-'.\Illuminate\Support\Str::uuid();
@endphp

<form {{ $attributes->class('admin-toggle') }} method="POST" @if($action) action="{{ $action }}" @endif
    data-status-toggle data-active-label="{{ $activeLabel }}" data-inactive-label="{{ $inactiveLabel }}"
    @if($demo) data-toggle-demo="{{ $demo }}" @endif>
    @if($action)
        @csrf
        @method('PATCH')
    @endif
    <input type="hidden" name="is_active" value="{{ $active ? '0' : '1' }}">
    <button class="admin-toggle-button" type="{{ $action ? 'submit' : 'button' }}" role="switch"
        aria-label="{{ $label }}" aria-checked="{{ $active ? 'true' : 'false' }}"
        @if($pending) aria-busy="true" @endif
        @if($blockedNote && $active) aria-describedby="{{ $noteId }}" @endif
        @disabled($disabled || $pending) data-toggle-switch>
        <span class="admin-toggle-track" aria-hidden="true"></span>
        <span data-toggle-label aria-hidden="true">{{ $active ? $activeLabel : $inactiveLabel }}</span>
    </button>
    @if($blockedNote)
        <small class="admin-toggle-note" id="{{ $noteId }}" data-toggle-note @if(!$active) hidden @endif>{{ $blockedNote }}</small>
    @endif
</form>
