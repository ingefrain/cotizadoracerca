@extends('layouts.admin')

@section('title', $creating ? 'Crear usuario' : 'Editar usuario')

@section('content')
    <div class="page-intro">
        <div><p class="eyebrow">Usuarios</p><p class="muted">{{ $creating ? 'Agrega una cuenta al sistema.' : 'Actualiza la cuenta y sus accesos.' }}</p></div>
        <a class="button button-secondary" href="{{ route('admin.users.index') }}">Volver a usuarios</a>
    </div>
    <section class="panel form-panel admin-form-surface">
        <form method="POST" action="{{ $creating ? route('admin.users.store') : route('admin.users.update', $user) }}" class="stack-form" data-password-policy data-password-optional="{{ $creating ? 'false' : 'true' }}" data-minimum="{{ \App\Support\PasswordPolicy::minimumLength() }}" data-maximum="{{ \App\Support\PasswordPolicy::maximumLength() }}" data-require-mixed-case="{{ config('password_policy.require_mixed_case') ? 'true' : 'false' }}" data-require-number="{{ config('password_policy.require_number') ? 'true' : 'false' }}">
            @csrf
            @unless($creating) @method('PUT') @endunless
            <div class="field-grid admin-form-grid">
                <div class="field"><label for="name">Nombre</label><input id="name" name="name" value="{{ old('name', $user->name) }}" maxlength="120" autocomplete="name" required><x-admin.field-error name="name" /></div>
                <div class="field"><label for="email">Correo electrónico</label><input id="email" name="email" type="email" value="{{ old('email', $user->email) }}" maxlength="255" autocomplete="email" required><x-admin.field-error name="email" /></div>
            </div>
            <x-admin.password-fields :creating="$creating" />
            @can('roles.assign')
                <x-admin.role-selector :roles="$roles" :selected-roles="$selectedRoles" />
            @endcan
            <div class="form-actions"><button class="button button-primary" type="submit">{{ $creating ? 'Crear usuario' : 'Guardar cambios' }}</button><a class="button button-secondary" href="{{ route('admin.users.index') }}">Cancelar</a></div>
        </form>
    </section>
@endsection
