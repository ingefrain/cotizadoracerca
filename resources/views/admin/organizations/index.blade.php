@extends('layouts.admin')

@section('title', 'Organizaciones')

@section('content')
    <div class="page-intro"><p class="muted">Gestiona empresas, instituciones o unidades principales y sus sedes.</p><div class="page-actions">@can('locations.view')<a class="button button-secondary" href="{{ route('admin.locations.index') }}">Ver sedes</a>@endcan @can('organizations.create')<a class="button button-primary" href="{{ route('admin.organizations.create') }}">Crear organización</a>@endcan</div></div>
    <section class="panel admin-content-wide">
        <form method="GET" action="{{ route('admin.organizations.index') }}" class="filter-bar" aria-label="Filtrar organizaciones">
            <label class="filter-search" for="q">Buscar<input id="q" name="q" type="search" value="{{ $filters['q'] ?? '' }}" maxlength="120" placeholder="Nombre o nombre legal"></label>
            <label for="status">Estado<select id="status" name="status"><option value="">Todos</option><option value="active" @selected(($filters['status'] ?? '') === 'active')>Activas</option><option value="inactive" @selected(($filters['status'] ?? '') === 'inactive')>Inactivas</option></select></label>
            <div class="filter-actions"><button class="button button-primary button-small" type="submit">Aplicar</button><a class="button button-secondary button-small" href="{{ route('admin.organizations.index') }}">Limpiar</a></div>
        </form>
        @if($organizations->isEmpty())
            <x-admin.empty-state title="No hay organizaciones para mostrar" description="Crea una organización para registrar sus datos y sedes." />
        @else
            <div class="table-wrap"><table><thead><tr><th>Organización</th><th>Sedes</th><th>Creada</th><th>Estado</th><th><span class="sr-only">Acciones</span></th></tr></thead><tbody>
            @foreach($organizations as $organization)
                <tr><td><strong>{{ $organization->name }}</strong>@if($organization->legal_name)<small class="table-subtitle">{{ $organization->legal_name }}</small>@endif</td><td>@can('locations.view')<a href="{{ route('admin.locations.index', ['organization' => $organization->uuid]) }}">{{ $organization->locations_count }}</a>@else{{ $organization->locations_count }}@endcan</td><td><time datetime="{{ $organization->created_at->toIso8601String() }}">{{ $organization->created_at->format('d/m/Y') }}</time></td><td><span class="badge {{ $organization->is_active ? 'badge-success' : 'badge-muted' }}">{{ $organization->is_active ? 'Activa' : 'Inactiva' }}</span></td>
                    <td class="row-actions">@can('organizations.update')<a class="button button-secondary button-small" href="{{ route('admin.organizations.edit', $organization) }}">Editar</a>@endcan @can('organizations.disable')<form method="POST" action="{{ route('admin.organizations.status', $organization) }}">@csrf @method('PATCH')
                                    @if($organization->is_active)
                                        <x-admin.confirm-dialog title="Desactivar organización" variant="warning" confirm-label="Desactivar organización"
                                            :message="'¿Deseas desactivar la organización «'.$organization->name.'»?'"
                                            consequence="Sus sedes dejarán de estar operativamente activas. El estado individual de cada sede se conservará." />
                                    @endif
                                    <button class="button {{ $organization->is_active ? 'button-danger' : 'button-secondary' }} button-small" type="submit">{{ $organization->is_active ? 'Desactivar' : 'Activar' }}</button></form>@endcan</td></tr>
            @endforeach
            </tbody></table></div><div class="pagination-wrap">{{ $organizations->links() }}</div>
        @endif
    </section>
@endsection
