@extends('layouts.admin')

@section('title', 'Reportes')

@section('content')
    <div class="admin-form-surface report-hub">
        <div class="page-intro"><p class="muted">Consulta y exporta información administrativa del sistema.</p></div>
        <section class="settings-grid report-cards" aria-label="Reportes disponibles">
            @forelse($cards as $card)
                <a class="settings-card" href="{{ route($card['route']) }}">
                    <h2>{{ $card['title'] }}</h2>
                    <p>{{ $card['description'] }}</p>
                    <span class="settings-card-action">Abrir reporte <span aria-hidden="true">→</span></span>
                </a>
            @empty
                <x-admin.empty-state title="No hay reportes disponibles" description="Los reportes disponibles dependen de tus permisos." />
            @endforelse
        </section>
    </div>
@endsection
