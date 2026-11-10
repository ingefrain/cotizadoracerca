@extends('layouts.admin')

@section('title', 'Mi perfil')

@section('content')
    <div class="page-intro"><p class="muted">Administra tu foto y tus datos personales.</p></div>
    <div class="profile-grid admin-form-surface admin-form-surface-wide">
        <section class="panel form-panel profile-panel">
            <h2>Foto de perfil</h2>
            <div class="avatar-profile-row"><x-admin.user-avatar :user="auth()->user()" size="large" />
                <form method="POST" action="{{ route('admin.profile.avatar.update') }}" enctype="multipart/form-data" class="profile-avatar-form">
                    @csrf
                    @php($avatarRules = config('documents.category_rules')['user.avatar'])
                    <x-admin.dropzone name="file" label="Seleccionar foto" :accept="'.jpg,.jpeg,.png,.webp,image/jpeg,image/png,image/webp'" :mime-types="array_keys($avatarRules['allowed_mime_types'])" :max-size-kb="$avatarRules['max_size_kb']" required instructions="Arrastra una foto aquí o selecciónala." />
                    <button class="button button-primary" type="submit">Guardar foto</button>
                </form>
            </div>
            @if(auth()->user()->avatar)
                <form method="POST" action="{{ route('admin.profile.avatar.delete') }}" class="profile-avatar-delete">@csrf @method('DELETE')<button class="button button-danger button-small" type="submit">Eliminar foto</button></form>
            @endif
        </section>

        <section class="panel form-panel profile-panel">
            <h2>Datos personales</h2>
            <dl class="profile-summary"><div><dt>Correo electrónico</dt><dd>{{ auth()->user()->email }}</dd></div><div><dt>Estado</dt><dd><span class="badge badge-success">Activo</span></dd></div></dl>
            <form method="POST" action="{{ route('admin.profile.update') }}" class="stack-form profile-details-form">
                @csrf @method('PUT')
                <div class="field"><label for="name">Nombre</label><input id="name" name="name" value="{{ old('name', auth()->user()->name) }}" maxlength="120" required><x-admin.field-error name="name" /></div>
                <button class="button button-primary" type="submit">Guardar cambios</button>
            </form>
        </section>
    </div>

    <section class="panel profile-security admin-form-surface admin-form-surface-wide">
        <div><h2>Seguridad</h2><p class="muted">Actualiza tu contraseña de acceso cuando lo necesites.</p></div>
        <button class="button button-secondary" type="button" data-modal-open="password-change-dialog">Cambiar contraseña</button>
    </section>

    @php($passwordErrors = $errors->has('password') || $errors->has('password_confirmation'))
    <dialog class="profile-modal" id="password-change-dialog" aria-labelledby="password-dialog-title" data-profile-modal @if($passwordErrors) open data-open-on-load @endif>
        <div class="profile-modal-header">
            <div><p class="eyebrow">Seguridad de la cuenta</p><h2 id="password-dialog-title">Cambiar contraseña</h2></div>
            <button class="modal-close" type="button" aria-label="Cerrar ventana" data-modal-close>×</button>
        </div>
        <form method="POST" action="{{ route('admin.profile.password') }}" class="stack-form password-form" data-password-policy data-minimum="{{ \App\Support\PasswordPolicy::minimumLength() }}" data-maximum="{{ \App\Support\PasswordPolicy::maximumLength() }}" data-require-mixed-case="{{ config('password_policy.require_mixed_case') ? 'true' : 'false' }}" data-require-number="{{ config('password_policy.require_number') ? 'true' : 'false' }}">
            @csrf @method('PUT')
            <div class="password-fields">
                <div class="field"><label for="password">Nueva contraseña</label><div class="password-input-wrap"><input id="password" name="password" type="password" autocomplete="new-password" required data-password-value data-modal-initial-focus><button class="password-visibility-toggle" type="button" aria-label="Mostrar contraseña" aria-controls="password" aria-pressed="false" data-password-toggle hidden><svg viewBox="0 0 24 24" aria-hidden="true" focusable="false"><path d="M2.1 12s3.5-6.5 9.9-6.5 9.9 6.5 9.9 6.5-3.5 6.5-9.9 6.5S2.1 12 2.1 12Zm9.9 3.4a3.4 3.4 0 1 0 0-6.8 3.4 3.4 0 0 0 0 6.8Z"/><path data-eye-slash d="m3 3 18 18"/></svg></button></div><x-admin.field-error name="password" /></div>
                <div class="field"><label for="password_confirmation">Confirmar contraseña</label><div class="password-input-wrap"><input id="password_confirmation" name="password_confirmation" type="password" autocomplete="new-password" required data-password-confirm><button class="password-visibility-toggle" type="button" aria-label="Mostrar contraseña" aria-controls="password_confirmation" aria-pressed="false" data-password-toggle hidden><svg viewBox="0 0 24 24" aria-hidden="true" focusable="false"><path d="M2.1 12s3.5-6.5 9.9-6.5 9.9 6.5 9.9 6.5-3.5 6.5-9.9 6.5S2.1 12 2.1 12Zm9.9 3.4a3.4 3.4 0 1 0 0-6.8 3.4 3.4 0 0 0 0 6.8Z"/><path data-eye-slash d="m3 3 18 18"/></svg></button></div><x-admin.field-error name="password_confirmation" /></div>
                <p class="password-limit-warning" data-password-limit role="alert" aria-live="assertive" hidden>✕ La contraseña no puede superar {{ \App\Support\PasswordPolicy::maximumLength() }} caracteres.</p>
                <ul class="password-checklist" aria-label="Requisitos de la contraseña">
                    <li data-rule="minimum"><span aria-hidden="true">✕</span><span>Mínimo {{ \App\Support\PasswordPolicy::minimumLength() }} caracteres</span><span class="sr-only" data-rule-status>Falta</span></li>
                    @if(config('password_policy.require_mixed_case'))
                        <li data-rule="uppercase"><span aria-hidden="true">✕</span><span>Una letra mayúscula</span><span class="sr-only" data-rule-status>Falta</span></li>
                        <li data-rule="lowercase"><span aria-hidden="true">✕</span><span>Una letra minúscula</span><span class="sr-only" data-rule-status>Falta</span></li>
                    @endif
                    @if(config('password_policy.require_number'))<li data-rule="number"><span aria-hidden="true">✕</span><span>Un número</span><span class="sr-only" data-rule-status>Falta</span></li>@endif
                    <li data-rule="match"><span aria-hidden="true">✕</span><span>Las contraseñas coinciden</span><span class="sr-only" data-rule-status>Falta</span></li>
                </ul>
            </div>
            <div class="profile-modal-actions">
                <button class="button button-secondary" type="button" data-modal-close>Cancelar</button>
                <button class="button button-primary" type="submit">Actualizar contraseña</button>
            </div>
        </form>
    </dialog>
@endsection
