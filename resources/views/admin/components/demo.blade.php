@extends('layouts.admin')

@section('title', $demoTitle)

@section('content')
    <div class="page-intro">
        <div>
            <p class="eyebrow">Componentes → {{ $demoTitle }}</p>
            <p class="muted">@yield('usage')</p>
        </div>
        <a class="button button-secondary" href="{{ route('admin.components.index') }}">Volver a Componentes</a>
    </div>
    <section class="panel form-panel admin-form-surface admin-form-surface-wide">
        <h2>Ejemplo</h2>
        @yield('example')
    </section>
    <section class="panel admin-form-surface admin-form-surface-wide">
        <h2>Integración</h2>
        <div class="markdown-content"><pre><code>@yield('integration')</code></pre></div>
    </section>
@endsection
