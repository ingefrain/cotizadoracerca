@extends('layouts.admin')

@section('title', 'Reporte de '.$definition['title'])

@section('content')
    <div class="admin-form-surface admin-form-surface-wide">
        <div class="page-intro">
            <p class="muted">{{ $definition['description'] }}</p>
            <a class="button button-secondary" href="{{ route('admin.reports.index') }}">Volver a Reportes</a>
        </div>
        <section class="panel">
            <form method="GET" action="{{ route($definition['route']) }}" class="filter-bar report-filters" aria-label="Filtrar reporte">
                @foreach($fields as $name => $field)
                    <label for="report-{{ $name }}">{{ $field['label'] }}
                        @if(isset($field['options']))
                            @php($options = is_callable($field['options']) ? $field['options']() : $field['options'])
                            <select id="report-{{ $name }}" name="{{ $name }}">
                                <option value="">Todos</option>
                                @foreach($options as $value => $label)
                                    <option value="{{ $value }}" @selected(($filters[$name] ?? '') === (string) $value)>{{ $label }}</option>
                                @endforeach
                            </select>
                        @else
                            <input id="report-{{ $name }}" name="{{ $name }}" type="{{ $field['type'] }}" value="{{ $filters[$name] ?? '' }}" @if($name === 'q') maxlength="120" @endif>
                        @endif
                    </label>
                @endforeach
                <div class="filter-actions">
                    <button class="button button-primary button-small" type="submit">Aplicar filtros</button>
                    <a class="button button-secondary button-small" href="{{ route($definition['route']) }}">Limpiar</a>
                </div>
            </form>

            @if($filterErrors->isNotEmpty())
                <x-admin.alert variant="error">
                    <ul>@foreach($filterErrors->all() as $error)<li>{{ $error }}</li>@endforeach</ul>
                </x-admin.alert>
            @else
                <div class="page-intro">
                    <p class="muted">{{ number_format($results->total()) }} {{ $results->total() === 1 ? 'resultado' : 'resultados' }}</p>
                    @if($canExport)
                        <div class="page-actions">
                            @if($results->total() > $maxRows)
                                <small class="muted">El CSV incluirá {{ $maxRows === 1 ? 'la primera fila' : 'las primeras '.number_format($maxRows).' filas' }}.</small>
                            @endif
                            <a class="button button-secondary button-small" href="{{ route($definition['route'].'.export', $filters) }}">Exportar CSV</a>
                        </div>
                    @endif
                </div>
                @if($results->isEmpty())
                    <x-admin.empty-state title="Sin resultados" description="Cambia o limpia los filtros para ampliar la búsqueda." />
                @else
                    <div class="table-wrap" role="region" aria-label="Resultados del reporte" tabindex="0">
                        <table class="report-table">
                            <thead><tr>@foreach($columns as $column)<th scope="col">{{ $column }}</th>@endforeach</tr></thead>
                            <tbody>
                                @foreach($rows as $row)
                                    <tr>
                                        @foreach($row as $value)
                                            <td>
                                                @if($value instanceof \DateTimeInterface)
                                                    <time datetime="{{ $value->format(DATE_ATOM) }}">{{ $value->format('d/m/Y H:i') }}</time>
                                                @else
                                                    {{ $value === '' || $value === null ? '—' : $value }}
                                                @endif
                                            </td>
                                        @endforeach
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>
                @endif
                <div class="pagination-wrap">{{ $results->links() }}</div>
            @endif
        </section>
    </div>
@endsection
