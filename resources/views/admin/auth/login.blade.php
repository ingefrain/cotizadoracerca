@extends('layouts.guest')

@section('title', 'Iniciar sesión')

@section('content')
    <p class="eyebrow">Acceso seguro</p>
    <h1>Iniciar sesión</h1>
    <p class="muted">Ingresa con tu cuenta administrativa.</p>
    @if(isset($retryAfter))
        <section class="login-limit-notice" data-login-retry="{{ $retryAfter }}" aria-labelledby="login-limit-title">
            <h2 id="login-limit-title">Acceso temporalmente limitado</h2>
            <p role="alert">Por seguridad, hemos limitado temporalmente los intentos de inicio de sesión.</p>
            <p>Podrás intentarlo nuevamente en <strong data-login-seconds>{{ $retryAfter }}</strong> segundos.</p>
            <p>¿Olvidaste tu contraseña? <a href="{{ route('admin.password.request') }}">Puedes recuperarla desde este enlace.</a></p>
            <noscript><p><a href="{{ route('admin.login') }}">Volver al formulario de acceso</a> cuando termine la espera.</p></noscript>
        </section>
        <p role="status" data-login-ready></p>
        <script src="{{ asset('assets/base-admin/login-retry.js') }}?v={{ filemtime(public_path('assets/base-admin/login-retry.js')) }}" defer></script>
    @endif
    <script src="{{ asset('assets/base-admin/admin.js') }}?v={{ filemtime(public_path('assets/base-admin/admin.js')) }}" defer></script>
    <form method="POST" action="{{ route('admin.login.store') }}" class="stack-form">
        @csrf
        <label for="email">Correo electrónico</label>
        <input id="email" name="email" type="email" value="{{ $loginEmail ?? old('email') }}" autocomplete="username" required autofocus>
        <x-admin.field-error name="email" />
        <label for="password">Contraseña</label>
        <div class="password-input-wrap">
            <input id="password" name="password" type="password" autocomplete="current-password" required @disabled(isset($retryAfter))>
            <button class="password-visibility-toggle" type="button" aria-label="Mostrar contraseña" aria-controls="password" aria-pressed="false" data-password-toggle @disabled(isset($retryAfter)) hidden><svg viewBox="0 0 24 24" aria-hidden="true" focusable="false"><path d="M2.1 12s3.5-6.5 9.9-6.5 9.9 6.5 9.9 6.5-3.5 6.5-9.9 6.5S2.1 12 2.1 12Zm9.9 3.4a3.4 3.4 0 1 0 0-6.8 3.4 3.4 0 0 0 0 6.8Z"/><path data-eye-slash d="m3 3 18 18"/></svg></button>
        </div>
        <x-admin.field-error name="password" />
        <label class="check-row login-remember"><input type="checkbox" name="remember" value="1" aria-describedby="login-remember-help"> <span>Mantener sesión iniciada en este dispositivo</span></label>
        <small id="login-remember-help">Actívalo únicamente en un dispositivo de confianza.</small>
        <button class="button button-primary button-wide" type="submit" @disabled(isset($retryAfter))>Entrar</button>
    </form>
    <p class="form-footnote"><a href="{{ route('admin.password.request') }}">¿Olvidaste tu contraseña?</a></p>
@endsection
