@extends('layouts.guest')

@section('title', 'Recuperar contraseña')

@section('content')
    <p class="eyebrow">Recuperación de acceso</p>
    <h1>Restablecer contraseña</h1>
    <p class="muted">Si el correo pertenece a una cuenta activa, enviaremos instrucciones.</p>
    @if(session('status'))<x-admin.alert variant="success">{{ session('status') }}</x-admin.alert>@endif
    <form method="POST" action="{{ route('admin.password.email') }}" class="stack-form">
        @csrf
        <label for="email">Correo electrónico</label>
        <input id="email" name="email" type="email" value="{{ old('email') }}" autocomplete="email" required>
        <x-admin.field-error name="email" />
        <button class="button button-primary button-wide" type="submit">Enviar instrucciones</button>
    </form>
    <p class="form-footnote"><a href="{{ route('admin.login') }}">Volver al acceso</a></p>
@endsection
