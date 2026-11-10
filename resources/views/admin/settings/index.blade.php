@extends('layouts.admin')

@section('title', 'Configuración')

@section('content')
    <div class="page-intro"><p class="muted">Administra el acceso y las notificaciones de tu sistema.</p></div>
    <section class="settings-grid" aria-label="Áreas de configuración">
        @foreach($cards as $card)
            <a class="settings-card" href="{{ route($card['route']) }}">
                <h2>{{ $card['title'] }}</h2>
                <p>{{ $card['description'] }}</p>
                <span class="settings-card-action">{{ $card['action'] }} <span aria-hidden="true">→</span></span>
            </a>
        @endforeach
    </section>
@endsection
