@extends('layouts.admin')

@section('title', 'Auditoría del sistema')

@section('content')
    <div class="page-intro"><p class="muted">Herramientas técnicas para revisar seguridad de acceso, actividad administrativa, permisos, entregas y estado de la aplicación.</p><a class="button button-secondary" href="{{ route('admin.settings.index') }}">Volver a configuración</a></div>
    <section class="settings-grid" aria-label="Herramientas de auditoría del sistema">
        @foreach($cards as $card)
            <a class="settings-card" href="{{ route($card['route']) }}">
                <h2>{{ $card['title'] }}</h2>
                <p>{{ $card['description'] }}</p>
                <span class="settings-card-action">{{ $card['action'] }} <span aria-hidden="true">→</span></span>
            </a>
        @endforeach
    </section>
@endsection
