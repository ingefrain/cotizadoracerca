@extends('layouts.admin')

@section('title', 'Roles')

@section('content')
    <div class="page-intro"><p class="muted">Define qué puede hacer cada tipo de usuario.</p><div class="page-actions">@can('roles.create')<a class="button button-primary" href="{{ route('admin.roles.create') }}">Crear rol</a>@endcan</div></div>
    <section class="panel">
        <form method="GET" action="{{ route('admin.roles.index') }}" class="filter-bar" aria-label="Filtrar roles">
            <label class="filter-search" for="q">Buscar<input id="q" name="q" type="search" value="{{ $filters['q'] ?? '' }}" placeholder="Nombre o clave"></label>
            <label for="status">Estado<select id="status" name="status"><option value="">Todos</option><option value="active" @selected(($filters['status'] ?? '') === 'active')>Activos</option><option value="inactive" @selected(($filters['status'] ?? '') === 'inactive')>Inactivos</option></select></label>
            <div class="filter-actions"><button class="button button-primary button-small" type="submit">Aplicar</button><a class="button button-secondary button-small" href="{{ route('admin.roles.index') }}">Limpiar</a></div>
        </form>
        @if($roles->isEmpty())
            <x-admin.empty-state title="No hay roles para mostrar" description="Crea un rol para organizar capacidades." />
        @else
            <div class="table-wrap"><table><thead><tr><th>Rol</th><th>Clave estable</th><th>Usuarios</th><th>Estado</th><th><span class="sr-only">Acciones</span></th></tr></thead><tbody>
                @foreach($roles as $role)
                    <tr><td><strong>{{ $role->name }}</strong><small class="table-subtitle">{{ $role->description }}</small></td><td><code>{{ $role->key }}</code></td><td>{{ $role->users_count }}</td><td><span class="badge {{ $role->is_active ? 'badge-success' : 'badge-muted' }}">{{ $role->is_active ? 'Activo' : 'Inactivo' }}</span></td>
                        <td class="row-actions">
                            @can('roles.update')<a class="button button-secondary button-small" href="{{ route('admin.roles.edit', $role) }}">Editar</a>@endcan
                            @can('roles.update')
                                <form method="POST" action="{{ route('admin.roles.status', $role) }}">@csrf @method('PATCH')
                                    @if($role->is_active)
                                        <x-admin.confirm-dialog title="Desactivar rol" variant="warning" confirm-label="Desactivar rol"
                                            :message="'¿Deseas desactivar el rol «'.$role->name.'»?'"
                                            consequence="El rol dejará de otorgar permisos y se invalidarán las sesiones de los usuarios asignados. Sus asignaciones se conservarán." />
                                    @endif
                                    <button class="button {{ $role->is_active ? 'button-danger' : 'button-secondary' }} button-small" type="submit">{{ $role->is_active ? 'Desactivar' : 'Activar' }}</button></form>
                            @endcan
                        </td>
                    </tr>
                @endforeach
            </tbody></table></div>
            <div class="pagination-wrap">{{ $roles->links() }}</div>
        @endif
    </section>
@endsection
