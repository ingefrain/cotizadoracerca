@extends('layouts.admin')

@section('title', 'Catálogos')

@section('content')
<div class="admin-form-surface admin-form-surface-wide">
    <div class="page-intro">
        <p class="muted">Gestiona conjuntos reutilizables de elementos administrables.</p>
        @can('create', \App\Models\Catalog::class)
            <a class="button button-primary" href="{{ route('admin.catalogs.create') }}">Crear catálogo</a>
        @endcan
    </div>
    <section class="panel">
        @include('admin.catalogs.filters', ['url' => route('admin.catalogs.index'), 'placeholder' => 'Nombre del catálogo'])
        @if($catalogs->isEmpty())
            <x-admin.empty-state title="Sin catálogos para mostrar" description="Crea un catálogo o cambia los filtros." />
        @else
            <div class="table-wrap" role="region" aria-label="Catálogos" tabindex="0">
                <table>
                    <thead><tr><th>Nombre</th><th>Organización</th><th>Estado</th><th>Elementos</th><th>Creación</th><th>Acciones</th></tr></thead>
                    <tbody>
                        @foreach($catalogs as $catalog)
                            <tr>
                                <td><strong>{{ $catalog->name }}</strong><small class="table-subtitle">{{ $catalog->slug }}</small></td>
                                <td>{{ $catalog->organization?->name ?? 'Global' }}</td>
                                <td>
                                    @can('disable', $catalog)
                                        <x-admin.status-toggle :active="$catalog->is_active" :action="route('admin.catalogs.status', $catalog)" :label="'Estado de '.$catalog->name" :blocked-note="$catalog->organization_id !== null && !$catalog->organization?->is_active ? 'Inactivo por organización' : null" />
                                    @else
                                        <span class="badge {{ $catalog->isOperationallyActive() ? 'badge-success' : 'badge-muted' }}">{{ $catalog->isOperationallyActive() ? 'Activo' : ($catalog->is_active ? 'Inactivo por organización' : 'Inactivo') }}</span>
                                    @endcan
                                </td>
                                <td>{{ $catalog->items_count }}</td>
                                <td><time datetime="{{ $catalog->created_at->toAtomString() }}">{{ $catalog->created_at->format('d/m/Y') }}</time></td>
                                <td class="row-actions">
                                    <a class="button button-small button-secondary" href="{{ route('admin.catalogs.edit', $catalog) }}">{{ auth()->user()->can('update', $catalog) ? 'Editar' : 'Ver' }}</a>
                                    @can('viewAny', [\App\Models\CatalogItem::class, $catalog])
                                        <a class="button button-small button-secondary" href="{{ route('admin.catalogs.items.index', $catalog) }}">Elementos</a>
                                    @endcan

                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
            <div class="pagination-wrap">{{ $catalogs->links() }}</div>
        @endif
    </section>
</div>
@endsection
