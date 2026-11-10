@extends('layouts.admin')

@section('title', 'Sedes')

@section('content')
    <div class="page-intro"><p class="muted">Administra ubicaciones asociadas a una organización.</p><div class="page-actions">@can('organizations.view')<a class="button button-secondary" href="{{ route('admin.organizations.index') }}">Ver organizaciones</a>@endcan @can('locations.create')<a class="button button-primary" href="{{ route('admin.locations.create', ['organization' => $filters['organization'] ?? null]) }}">Crear sede</a>@endcan</div></div>
    <section class="panel admin-content-wide">
        <form method="GET" action="{{ route('admin.locations.index') }}" class="filter-bar" aria-label="Filtrar sedes">
            <label class="filter-search" for="q">Buscar<input id="q" name="q" type="search" value="{{ $filters['q'] ?? '' }}" maxlength="120" placeholder="Nombre, código, ciudad u organización"></label>
            <label for="status">Estado propio<select id="status" name="status"><option value="">Todos</option><option value="active" @selected(($filters['status'] ?? '') === 'active')>Activas</option><option value="inactive" @selected(($filters['status'] ?? '') === 'inactive')>Inactivas</option></select></label>
            <div class="filter-actions"><button class="button button-primary button-small" type="submit">Aplicar</button><a class="button button-secondary button-small" href="{{ route('admin.locations.index') }}">Limpiar</a></div>
        </form>
        @if($locations->isEmpty())<x-admin.empty-state title="No hay sedes para mostrar" description="Registra una sede desde una organización activa." />@else
            <div class="table-wrap"><table><thead><tr><th>Sede</th><th>Organización</th><th>Ubicación</th><th>Estado operativo</th><th><span class="sr-only">Acciones</span></th></tr></thead><tbody>
            @foreach($locations as $location)
                <tr><td><strong>{{ $location->name }}</strong>@if($location->code)<small class="table-subtitle">{{ $location->code }}</small>@endif</td><td>{{ $location->organization?->name ?? 'Sin organización' }}</td><td>{{ collect([$location->city, $location->state])->filter()->implode(', ') ?: '—' }}</td><td><span class="badge {{ $location->isOperationallyActive() ? 'badge-success' : 'badge-muted' }}">{{ $location->isOperationallyActive() ? 'Activa' : 'Inactiva' }}</span>@if($location->is_active && !$location->organization?->is_active)<small class="table-subtitle">Organización inactiva</small>@endif</td><td class="row-actions">@can('locations.update')<a class="button button-secondary button-small" href="{{ route('admin.locations.edit', $location) }}">Editar</a>@endcan @can('locations.disable')<form method="POST" action="{{ route('admin.locations.status', $location) }}">@csrf @method('PATCH')
                                    @if($location->is_active)
                                        <x-admin.confirm-dialog title="Desactivar sede" variant="warning" confirm-label="Desactivar sede"
                                            :message="'¿Deseas desactivar la sede «'.$location->name.'»?'"
                                            consequence="La sede dejará de estar operativamente activa. Sus datos se conservarán y podrás volver a activarla." />
                                    @endif
                                    <button class="button {{ $location->is_active ? 'button-danger' : 'button-secondary' }} button-small" type="submit">{{ $location->is_active ? 'Desactivar' : 'Activar' }}</button></form>@endcan</td></tr>
            @endforeach
            </tbody></table></div><div class="pagination-wrap">{{ $locations->links() }}</div>
        @endif
    </section>
@endsection
