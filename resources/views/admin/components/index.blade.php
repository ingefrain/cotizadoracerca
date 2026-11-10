@extends('layouts.admin')

@section('title', 'Componentes')

@section('content')
    <div class="page-intro"><p class="muted">Referencia visual y funcional de los componentes reutilizables de Cotizador Acerca.</p></div>
    <section class="settings-grid" aria-label="Catálogo de componentes">
        @foreach(config('admin_components.cards', []) as $card)
            <a class="settings-card" href="{{ route($card['route']) }}">
                <h2>{{ $card['title'] }}</h2>
                <p>{{ $card['description'] }}</p>
                <span class="settings-card-action">Abrir ejemplo <span aria-hidden="true">→</span></span>
            </a>
        @endforeach
    </section>
@endsection
