@extends('admin.components.demo', ['demoTitle' => 'Contraseñas'])

@section('usage', 'Prueba la visibilidad y los requisitos con texto ficticio. Estos campos no se envían.')

@section('example')
    <div class="stack-form" data-password-policy data-minimum="{{ \App\Support\PasswordPolicy::minimumLength() }}" data-maximum="{{ \App\Support\PasswordPolicy::maximumLength() }}" data-require-mixed-case="{{ config('password_policy.require_mixed_case') ? 'true' : 'false' }}" data-require-number="{{ config('password_policy.require_number') ? 'true' : 'false' }}">
        <x-admin.password-fields :creating="true" />
    </div>
@endsection

@section('integration')
&lt;x-admin.password-fields :creating="true" /&gt;
Dentro de un contenedor data-password-policy.
@endsection
