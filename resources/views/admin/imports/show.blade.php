@extends('layouts.admin')

@section('title', 'Detalle de importación')

@section('content')
    <div class="page-intro">
        <a href="{{ route('admin.imports.index') }}">Volver a importaciones</a>
    </div>
    <section class="card">
        <h2>{{ $definition['title'] }}</h2>
        <p>{{ $run->original_name }} · {{ $run->statusLabel() }}</p>
        <p class="muted">Cargada por {{ $run->creator->name }} el {{ $run->created_at->format('d/m/Y H:i') }}.
            @if($run->delimiter) Delimitador: {{ $run->delimiter === ',' ? 'coma' : 'punto y coma' }}. @endif
            @if($run->applied_at) Aplicada el {{ $run->applied_at->format('d/m/Y H:i') }}. @endif
        </p>
        <p>Filas totales: {{ $run->total_rows }} · Válidas: {{ $run->valid_rows }} · Inválidas: {{ $run->invalid_rows }} · Aplicadas: {{ $run->applied_rows }}</p>
        @if($run->path && $run->expires_at->isFuture())
            <p class="muted">El archivo fuente vence el {{ $run->expires_at->format('d/m/Y H:i') }}.</p>
        @endif
        @if($canApply)
            <form method="post" action="{{ route('admin.imports.apply', $run) }}" data-confirm-form>
                @csrf
                <button class="button" type="submit">Importar {{ $run->valid_rows }} registros</button>
                <x-admin.confirm-dialog title="Aplicar importación" variant="warning"
                    :message="'Se crearán '.$run->valid_rows.' registros después de volver a validar el CSV.'"
                    consequence="Si alguna fila falla, se cancela toda la corrida." confirm-label="Aplicar importación" />
            </form>
        @endif
        @if($canDelete)
            @include('admin.imports.delete', ['deleteLabel' => 'Eliminar importación'])
        @endif
    </section>
    @if($sample)
        <section class="card">
            <h2>Vista previa</h2>
            <p class="muted">Se muestran hasta 25 filas y se recortan los textos largos.</p>
            <div class="table-wrap">
                <table>
                    <thead><tr><th scope="col">Fila</th>@foreach($definition['headers'] as $field)<th scope="col">{{ $field }}</th>@endforeach</tr></thead>
                    <tbody>
                        @foreach($sample as $number => $row)
                            <tr><th scope="row">{{ $number }}</th>@foreach($definition['headers'] as $field)<td>{{ $row[$field] }}</td>@endforeach</tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        </section>
    @endif
    @if($rowErrors->total())
        <section class="card">
            <h2>Errores de validación</h2>
            <div class="table-wrap">
                <table>
                    <thead><tr><th scope="col">Fila</th><th scope="col">Campo</th><th scope="col">Error</th></tr></thead>
                    <tbody>
                        @foreach($rowErrors as $error)
                            <tr><td>{{ $error->row_number ?? 'Archivo' }}</td><td>{{ $error->field ?? '—' }}</td><td>{{ $error->message }}</td></tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
            {{ $rowErrors->links() }}
        </section>
    @endif
@endsection
