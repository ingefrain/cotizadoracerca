@extends('layouts.admin')

@section('title', 'Importación de datos')

@section('content')
    <div class="page-intro"><p class="muted">Carga un CSV, revisa el resultado y confirma la creación de los registros.</p></div>
    @if($canCreate)
        <section class="settings-grid" aria-label="Tipos de importación">
            @foreach($types as $type => $definition)
                <a class="settings-card" href="{{ route('admin.imports.create', $type) }}">
                    <h2>{{ $definition['title'] }}</h2>
                    <span class="settings-card-action">Nueva importación <span aria-hidden="true">→</span></span>
                </a>
            @endforeach
        </section>
    @endif
    <section class="card">
        <h2>Historial de importaciones</h2>
        <div class="table-wrap">
            <table>
                <thead><tr><th scope="col">Archivo</th><th scope="col">Tipo</th><th scope="col">Estado</th><th scope="col">Fecha</th><th scope="col">Actor</th><th scope="col">Filas</th><th scope="col">Aplicadas</th><th scope="col">Acciones</th></tr></thead>
                <tbody>
                    @forelse($runs as $run)
                        <tr>
                            <td>{{ $run->original_name }}</td>
                            <td>{{ $types[$run->type]['title'] }}</td>
                            <td>{{ $run->statusLabel() }}</td>
                            <td>{{ $run->created_at->format('d/m/Y H:i') }}</td>
                            <td>{{ $run->creator->name }}</td>
                            <td>{{ $run->total_rows }}</td>
                            <td>{{ $run->applied_rows }}</td>
                            <td><div class="row-actions">
                                <a class="button button-secondary button-small" href="{{ route('admin.imports.show', $run) }}" aria-label="Ver {{ $run->original_name }}">Ver</a>
                                @if($canCreate && $run->canBeDeleted())
                                    @include('admin.imports.delete', ['deleteLabel' => 'Eliminar'])
                                @endif
                            </div></td>
                        </tr>
                    @empty
                        <tr><td colspan="8">Todavía no hay importaciones.</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
        {{ $runs->links() }}
    </section>
@endsection
