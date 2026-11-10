@props(['roles', 'selectedRoles'])

    @php($selectedRoleIds = array_map('intval', old('role_ids', $selectedRoles)))

<fieldset class="choice-fieldset role-assignment" data-role-selector>
    <legend>Roles</legend>
    <input type="hidden" name="role_assignment_present" value="1">
    <p class="field-hint">Selecciona uno o más roles para este usuario.</p>
    <label class="role-native-label" for="role_ids">Roles asignados</label>
    <select id="role_ids" name="role_ids[]" multiple size="{{ min(max($roles->count(), 3), 6) }}" aria-describedby="role-help role_ids-error role_ids-item-error">
        @foreach($roles as $role)
            @if($role->is_active || in_array($role->id, $selectedRoles, true))
                <option value="{{ $role->id }}" @selected(in_array($role->id, $selectedRoleIds, true))>{{ $role->name }}{{ $role->is_active ? '' : ' (inactivo)' }}</option>
            @endif
        @endforeach
    </select>
    <div class="role-enhanced" data-role-enhanced hidden>
        <div class="role-chips" data-role-chips aria-live="polite"></div>
        <button class="role-picker-toggle" type="button" aria-expanded="false" aria-controls="role-dropdown-panel" data-role-toggle>Elegir roles</button>
        <div class="role-dropdown-panel" id="role-dropdown-panel" data-role-dropdown hidden>
            <label class="sr-only" for="role-search">Buscar roles</label>
            <input id="role-search" type="search" placeholder="Buscar roles…" autocomplete="off" data-role-search>
            <div class="role-options" data-role-options role="group" aria-label="Roles disponibles"></div>
        </div>
    </div>
    <p class="field-hint" id="role-help">{{ $roles->isEmpty() ? 'No hay roles disponibles.' : 'Puedes seleccionar varios roles.' }}</p>
    <x-admin.field-error name="role_ids" />
    <x-admin.field-error name="role_ids.*" id="role_ids-item-error" />
</fieldset>
