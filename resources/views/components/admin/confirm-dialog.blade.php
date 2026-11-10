@props([
    'title', 'message', 'id' => null, 'consequence' => null,
    'cancelLabel' => 'Cancelar', 'confirmLabel' => null,
    'variant' => 'default', 'icon' => null, 'phrase' => null, 'phraseLabel' => null,
    'standalone' => false,
])
@php
    $id ??= 'confirmation-'.\Illuminate\Support\Str::uuid();
    $requiresPhrase = $variant === 'danger-confirm';
    if ($requiresPhrase && blank($phrase)) {
        throw new \InvalidArgumentException('La variante danger-confirm requiere una frase de confirmación.');
    }

    [$kind, $symbol] = match ($variant) {
        'warning' => ['Advertencia', '!'],
        'danger' => ['Acción destructiva', '−'],
        'danger-confirm' => ['Confirmación reforzada', '!'],
        'info' => ['Información', 'i'],
        default => ['Confirmación', '?'],
    };
    $confirmLabel ??= match ($variant) {
        'info' => 'Entendido',
        'danger', 'danger-confirm' => $title,
        default => 'Confirmar',
    };
@endphp
<dialog id="{{ $id }}" class="profile-modal confirm-dialog" aria-modal="true"
    aria-labelledby="{{ $id }}-title" aria-describedby="{{ $id }}-description"
    data-profile-modal data-confirm-dialog data-variant="{{ $variant }}">
    <div class="profile-modal-header">
        <div>
            <p class="eyebrow confirm-kind"><span aria-hidden="true">{{ $icon ?? $symbol }}</span> {{ $kind }}</p>
            <h2 id="{{ $id }}-title">{{ $title }}</h2>
        </div>
        <button class="modal-close" type="button" aria-label="Cerrar ventana" data-modal-close>×</button>
    </div>
    <div id="{{ $id }}-description">
        <p>{{ $message }}</p>
        @if($consequence)<p class="muted">{{ $consequence }}</p>@endif
    </div>
    @if($requiresPhrase)
        <div class="field">
            <label for="{{ $id }}-phrase">{{ $phraseLabel ?? "Escribe «{$phrase}» para continuar" }}</label>
            <input id="{{ $id }}-phrase" type="text" autocomplete="off" spellcheck="false" data-confirm-phrase="{{ $phrase }}">
        </div>
    @endif
    <div class="profile-modal-actions">
        @if($variant !== 'info' || !$standalone)
            <button class="button button-secondary" type="button" data-modal-close data-modal-initial-focus>{{ $cancelLabel }}</button>
        @endif
        <button class="button {{ in_array($variant, ['danger', 'danger-confirm'], true) ? 'button-danger' : 'button-primary' }}" type="button"
            data-confirm-accept @disabled($requiresPhrase) @if($variant === 'info' && $standalone) data-modal-initial-focus @endif>{{ $confirmLabel }}</button>
    </div>
</dialog>
