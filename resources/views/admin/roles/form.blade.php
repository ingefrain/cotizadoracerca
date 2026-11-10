@extends('layouts.admin')

@section('title', $creating ? 'Crear rol' : 'Editar rol')

@section('content')
    <div class="page-intro"><div><p class="eyebrow">Roles</p><p class="muted">Las acciones disponibles se comprueban en el sistema.</p></div><a class="button button-secondary" href="{{ route('admin.roles.index') }}">Volver a roles</a></div>
    <section class="panel form-panel admin-form-surface admin-form-surface-wide">
        <form method="POST" action="{{ $creating ? route('admin.roles.store') : route('admin.roles.update', $role) }}" class="stack-form">
            @csrf
            @unless($creating) @method('PUT') @endunless
            <div class="field-grid">
                <div class="field"><label for="name">Nombre visible</label><input id="name" name="name" value="{{ old('name', $role->name) }}" maxlength="100" required><x-admin.field-error name="name" /></div>
                @if($creating)
                    <div class="field"><label for="key">Clave estable</label><input id="key" name="key" value="{{ old('key') }}" pattern="[A-Za-z0-9_-]+" maxlength="100" required><p class="field-hint">Identificador interno; no depende del nombre visible.</p><x-admin.field-error name="key" /></div>
                @else
                    <div class="field"><span class="field-label">Clave estable</span><code class="key-display">{{ $role->key }}</code><p class="field-hint">La clave no cambia después de crear el rol.</p></div>
                @endif
            </div>
            <div class="field"><label for="description">Descripción (opcional)</label><textarea id="description" name="description" rows="3" maxlength="500">{{ old('description', $role->description) }}</textarea><x-admin.field-error name="description" /></div>

            @can('permissions.assign')
                <fieldset class="choice-fieldset">
                    <legend>Permisos</legend>
                    <input type="hidden" name="permission_assignment_present" value="1">
                    <p class="field-hint">Solo puedes asignar capacidades que tú también tienes.</p>
                    <div class="permission-grid">
                        @foreach($permissions->groupBy(fn($permission) => str($permission->key)->before('.')->toString()) as $group => $groupPermissions)
                            <fieldset class="permission-group"><legend>{{ ucfirst($group) }}</legend>
                                @foreach($groupPermissions as $permission)
                                    <label class="permission-option"><input type="checkbox" name="permission_ids[]" value="{{ $permission->id }}" @checked(in_array($permission->id, old('permission_ids', $selectedPermissions)))><span><code>{{ $permission->key }}</code><small>{{ $permission->description }}</small></span></label>
                                @endforeach
                            </fieldset>
                        @endforeach
                    </div>
                    <x-admin.field-error name="permission_ids" />
                </fieldset>
            @else
                @unless($creating)
                    <div class="field"><span class="field-label">Permisos asignados</span><div class="badge-list">@forelse($role->permissions as $permission)<span class="badge badge-neutral"><code>{{ $permission->key }}</code></span>@empty<span class="muted">Este rol no tiene permisos.</span>@endforelse</div></div>
                @endunless
            @endcan

            <div class="form-actions"><button class="button button-primary" type="submit">{{ $creating ? 'Crear rol' : 'Guardar cambios' }}</button><a class="button button-secondary" href="{{ route('admin.roles.index') }}">Cancelar</a></div>
        </form>
    </section>
@endsection
