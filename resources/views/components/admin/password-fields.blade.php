@props(['creating'])

<div class="field-grid admin-form-grid password-fields">
    <div class="field">
        <label for="password">{{ $creating ? 'Contraseña' : 'Nueva contraseña (opcional)' }}</label>
        <div class="password-input-wrap"><input id="password" name="password" type="password" autocomplete="new-password" @required($creating) data-password-value><button class="password-visibility-toggle" type="button" aria-label="Mostrar contraseña" aria-controls="password" aria-pressed="false" data-password-toggle hidden><svg viewBox="0 0 24 24" aria-hidden="true" focusable="false"><path d="M2.1 12s3.5-6.5 9.9-6.5 9.9 6.5 9.9 6.5-3.5 6.5-9.9 6.5S2.1 12 2.1 12Zm9.9 3.4a3.4 3.4 0 1 0 0-6.8 3.4 3.4 0 0 0 0 6.8Z"/><path data-eye-slash d="m3 3 18 18"/></svg></button></div>
        @unless($creating)<p class="field-hint">Déjalo vacío si no quieres cambiarla.</p>@endunless
        <x-admin.field-error name="password" />
    </div>
    <div class="field">
        <label for="password_confirmation">Confirmar contraseña</label>
        <div class="password-input-wrap"><input id="password_confirmation" name="password_confirmation" type="password" autocomplete="new-password" @required($creating) data-password-confirm><button class="password-visibility-toggle" type="button" aria-label="Mostrar contraseña" aria-controls="password_confirmation" aria-pressed="false" data-password-toggle hidden><svg viewBox="0 0 24 24" aria-hidden="true" focusable="false"><path d="M2.1 12s3.5-6.5 9.9-6.5 9.9 6.5 9.9 6.5-3.5 6.5-9.9 6.5S2.1 12 2.1 12Zm9.9 3.4a3.4 3.4 0 1 0 0-6.8 3.4 3.4 0 0 0 0 6.8Z"/><path data-eye-slash d="m3 3 18 18"/></svg></button></div>
        <x-admin.field-error name="password_confirmation" />
    </div>
</div>
<p class="password-limit-warning" data-password-limit role="alert" aria-live="assertive" hidden>La contraseña no puede superar {{ \App\Support\PasswordPolicy::maximumLength() }} caracteres.</p>
<ul class="password-checklist" aria-label="Requisitos de la contraseña" data-password-checklist @unless($creating) hidden @endunless>
        <li data-rule="minimum"><span aria-hidden="true">✗</span><span>Mínimo {{ \App\Support\PasswordPolicy::minimumLength() }} caracteres</span><span class="sr-only" data-rule-status>Falta</span></li>
        @if(config('password_policy.require_mixed_case'))
            <li data-rule="uppercase"><span aria-hidden="true">✗</span><span>Una letra mayúscula</span><span class="sr-only" data-rule-status>Falta</span></li>
            <li data-rule="lowercase"><span aria-hidden="true">✗</span><span>Una letra minúscula</span><span class="sr-only" data-rule-status>Falta</span></li>
        @endif
        @if(config('password_policy.require_number'))
            <li data-rule="number"><span aria-hidden="true">✗</span><span>Un número</span><span class="sr-only" data-rule-status>Falta</span></li>
        @endif
        <li data-rule="match"><span aria-hidden="true">✗</span><span>Las contraseñas coinciden</span><span class="sr-only" data-rule-status>Falta</span></li>
    </ul>
