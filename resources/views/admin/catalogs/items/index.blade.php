@extends('layouts.admin')

@section('title', 'Elementos de catálogo')

@section('content')
<div class="admin-form-surface admin-form-surface-wide">
    <div class="page-intro">
        <div><p class="eyebrow">Catálogos</p><p>{{ $catalog->name }}</p></div>
        <div class="page-actions">
            <a class="button button-secondary" href="{{ route('admin.catalogs.edit', $catalog) }}">Volver al catálogo</a>
            @can('create', [\App\Models\CatalogItem::class, $catalog])
                <a class="button button-primary" href="{{ route('admin.catalogs.items.create', $catalog) }}">Agregar elemento</a>
            @endcan
        </div>
    </div>
    <section class="panel">
        @include('admin.catalogs.filters', ['url' => route('admin.catalogs.items.index', $catalog), 'placeholder' => 'Nombre o código'])
        @if($items->isEmpty())
            <x-admin.empty-state title="Sin elementos para mostrar" description="Agrega un elemento o cambia los filtros." />
        @else
            <div class="table-wrap" role="region" aria-label="Elementos" tabindex="0">
                <table>
                    <thead><tr><th>Imagen</th><th>Código</th><th>Nombre</th><th>Estado</th><th>Orden</th><th>Creación</th><th>Acciones</th></tr></thead>
                    <tbody>
                        @foreach($items as $item)
                            <tr>
                                <td>
                                    @if($item->image)
                                        <img class="catalog-thumbnail" src="{{ route('admin.catalogs.items.image', [$catalog, $item]) }}" alt="" width="48" height="48" loading="lazy">
                                    @else
                                        —
                                    @endif
                                </td>
                                <td>{{ $item->code ?? '—' }}</td>
                                <td><strong>{{ $item->name }}</strong></td>
                                <td>
                                    @can('disable', $item)
                                        <x-admin.status-toggle :active="$item->is_active" :action="route('admin.catalogs.items.status', [$catalog, $item])" :label="'Estado de '.$item->name" :blocked-note="!$catalog->isOperationallyActive() ? 'Inactivo por catálogo' : null" />
                                    @else
                                        <span class="badge {{ $item->isOperationallyActive() ? 'badge-success' : 'badge-muted' }}">{{ $item->isOperationallyActive() ? 'Activo' : ($item->is_active ? 'Inactivo por catálogo' : 'Inactivo') }}</span>
                                    @endcan
                                </td>
                                <td>{{ $item->sort_order }}</td>
                                <td><time datetime="{{ $item->created_at->toAtomString() }}">{{ $item->created_at->format('d/m/Y') }}</time></td>
                                <td class="row-actions">
                                    <a class="button button-small button-secondary" href="{{ route('admin.catalogs.items.edit', [$catalog, $item]) }}">{{ auth()->user()->can('update', $item) ? 'Editar' : 'Ver' }}</a>

                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
            <div class="pagination-wrap">{{ $items->links() }}</div>
        @endif
    </section>
</div>
@endsection
