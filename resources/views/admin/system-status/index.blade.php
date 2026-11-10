@extends('layouts.admin')

@section('title', 'Estado del sistema')

@section('content')
    <div class="page-intro"><p class="muted">Indicadores técnicos disponibles para esta aplicación.</p><a class="button button-secondary" href="{{ route('admin.system-audit.index') }}">Volver a auditoría del sistema</a></div>
    <section class="panel status-panel admin-content-wide" aria-label="Indicadores del sistema">
        <dl class="system-status-list">
            @foreach($indicators as $label => $value)
                <div><dt>{{ $label }}</dt><dd>{{ $value }}</dd></div>
            @endforeach
        </dl>
    </section>
@endsection
