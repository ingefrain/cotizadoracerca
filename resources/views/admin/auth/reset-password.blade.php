@extends('layouts.guest')

@section('title', 'Nueva contraseña')

@section('content')
    <p class="eyebrow">Recuperación de acceso</p>
    <h1>Elige una contraseña nueva</h1>
    <form method="POST" action="{{ route('admin.password.update') }}" class="stack-form">
        @csrf
        <input type="hidden" name="token" value="{{ $token }}">
        <label for="email">Correo electrónico</label>
        <input id="email" name="email" type="email" value="{{ old('email', $email) }}" autocomplete="email" required>
        <x-admin.field-error name="email" />
        <label for="password">Nueva contraseña</label>
        <input id="password" name="password" type="password" autocomplete="new-password" required>
        <p class="field-hint">{{ \App\Support\PasswordPolicy::summary() }}</p>
        <x-admin.field-error name="password" />
        <label for="password_confirmation">Confirma la contraseña</label>
        <input id="password_confirmation" name="password_confirmation" type="password" autocomplete="new-password" required>
        <button class="button button-primary button-wide" type="submit">Guardar contraseña</button>
    </form>
@endsection
