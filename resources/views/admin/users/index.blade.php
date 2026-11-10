@extends('layouts.admin')

@section('title', 'Usuarios')

@section('content')
    <div class="page-intro">
        <p class="muted">Administra las personas con acceso al sistema.</p>
        <div class="page-actions">
            @can('roles.view')<a class="button button-secondary" href="{{ route('admin.roles.index') }}">Roles</a>@endcan
            @can('users.create')<a class="button button-primary" href="{{ route('admin.users.create') }}">Crear usuario</a>@endcan
        </div>
    </div>
    <section class="panel users-panel">
        <form method="GET" action="{{ route('admin.users.index') }}" class="filter-bar" aria-label="Filtrar usuarios">
            <label class="filter-search" for="q">Buscar<input id="q" name="q" type="search" value="{{ $filters['q'] ?? '' }}" placeholder="Nombre o correo"></label>
            <label for="status">Estado<select id="status" name="status"><option value="">Todos</option><option value="active" @selected(($filters['status'] ?? '') === 'active')>Activos</option><option value="inactive" @selected(($filters['status'] ?? '') === 'inactive')>Inactivos</option></select></label>
            <label for="role">Rol<select id="role" name="role"><option value="">Todos</option>@foreach($roles as $role)<option value="{{ $role->id }}" @selected((string)($filters['role'] ?? '') === (string)$role->id)>{{ $role->name }}{{ $role->is_active ? '' : ' · inactivo' }}</option>@endforeach</select></label>
            <div class="filter-actions"><button class="button button-primary button-small" type="submit">Aplicar</button><a class="button button-secondary button-small" href="{{ route('admin.users.index') }}">Limpiar</a></div>
        </form>
        @if($users->isEmpty())
            <x-admin.empty-state title="No hay usuarios para mostrar" description="Prueba otros filtros o crea una cuenta." />
        @else
            <div class="table-wrap"><table class="users-table">
                <thead><tr><th>Actividad</th><th>Usuario</th><th>Rol</th><th>Estado</th><th><span class="sr-only">Acciones</span></th></tr></thead>
                <tbody>
                @foreach($users as $user)
                    @php
                        $activityMinutes = $user->last_activity_at?->diffInMinutes(now());
                        $activityTone = $activityMinutes === null ? 'neutral' : ($activityMinutes <= 5 ? 'strong' : ($activityMinutes <= 60 ? 'medium' : 'faint'));
                        $activityText = $activityMinutes === null
                            ? 'Sin actividad registrada'
                            : 'Hace '.$user->last_activity_at->locale('es')->diffForHumans(now(), true);
                    @endphp
                    <tr>
                        <td data-label="Actividad">
                            <span class="activity-indicator activity-{{ $activityTone }}" aria-hidden="true"></span>
                            <span class="activity-copy" title="{{ $user->last_activity_at ? 'Última actividad: '.$user->last_activity_at->format('d/m/Y H:i') : 'El usuario aún no ha registrado actividad.' }}">
                                <span>{{ $activityText }}</span>
                                @if($user->last_activity_at)<time datetime="{{ $user->last_activity_at->toIso8601String() }}">{{ $user->last_activity_at->format('d/m/Y H:i') }}</time>@endif
                            </span>
                            <span class="sr-only">{{ $user->last_activity_at ? 'Última actividad '.$user->last_activity_at->format('d/m/Y H:i') : 'Sin actividad registrada' }}</span>
                        </td>
                        <td data-label="Usuario"><div class="user-cell"><x-admin.user-avatar :user="$user" size="small" /><div><strong>{{ $user->name }}</strong><span class="user-email">{{ $user->email }}</span></div></div></td>
                        <td data-label="Rol"><div class="badge-list">@forelse($user->roles as $role)<span class="badge badge-neutral">{{ $role->name }}</span>@empty<span class="muted">Sin rol</span>@endforelse</div></td>
                        <td data-label="Estado"><span class="badge {{ $user->is_active ? 'badge-success' : 'badge-muted' }}">{{ $user->is_active ? 'Activo' : 'Inactivo' }}</span></td>
                        <td class="row-actions" data-label="Acciones">
                            @can('users.impersonate')
                                @if(!app(\App\Support\ImpersonationManager::class)->active() && (int) $user->id !== (int) auth()->id())
                                    <form method="POST" action="{{ route('admin.users.impersonate', $user) }}"">
                                        @csrf
                                        <x-admin.confirm-dialog
                                            title="Ver como usuario" variant="info" confirm-label="Iniciar impersonación"
                                            :message="$user->name.' ('.$user->email.') · '.($user->is_active ? 'Activo' : 'Inactivo').' · '.($user->roles->pluck('name')->implode(', ') ?: 'Sin rol')"
                                            consequence="Navegarás temporalmente como este usuario. Podrás regresar a tu sesión administrativa en cualquier momento. La acción quedará registrada en auditoría."
                                        />
                                        <button class="button button-secondary button-small" type="submit" aria-label="Impersonar a {{ $user->name }}">Ver como usuario</button>
                                    </form>
                                @endif
                            @endcan
                            @can('users.update')<a class="button button-secondary button-small" href="{{ route('admin.users.edit', $user) }}">Editar</a>@endcan
                            @can('users.disable')
                                <form method="POST" action="{{ route('admin.users.status', $user) }}">
                                    @csrf @method('PATCH')
                                    @if($user->is_active)
                                        <x-admin.confirm-dialog title="Desactivar usuario" variant="warning" confirm-label="Desactivar usuario"
                                            :message="'¿Deseas desactivar a '.$user->name.'?'"
                                            consequence="El usuario dejará de poder iniciar sesión y sus sesiones activas se invalidarán. Sus datos se conservarán y podrás volver a activarlo." />
                                    @endif
                                    <button class="button {{ $user->is_active ? 'button-danger' : 'button-secondary' }} button-small" type="submit">{{ $user->is_active ? 'Desactivar' : 'Activar' }}</button>
                                </form>
                            @endcan
                        </td>
                    </tr>
                @endforeach
                </tbody>
            </table></div>
            <div class="pagination-wrap">{{ $users->links() }}</div>
        @endif
    </section>
@endsection
