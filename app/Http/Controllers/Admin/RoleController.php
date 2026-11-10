<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Permission;
use App\Models\Role;
use App\Support\AdminAuditLogger;
use App\Support\AdministrativeAccess;
use App\Support\AdministrativeState;
use App\Support\SessionInvalidator;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

class RoleController extends Controller
{
    public function index(Request $request): View
    {
        $filters = $request->validate([
            'q' => ['nullable', 'string', 'max:100'],
            'status' => ['nullable', Rule::in(['active', 'inactive'])],
        ]);
        $roles = Role::query()->withCount('users')
            ->when($filters['q'] ?? null, fn ($query, string $search) => $query->where(
                fn ($query) => $query->where('name', 'like', '%'.$search.'%')->orWhere('key', 'like', '%'.$search.'%')
            ))
            ->when(($filters['status'] ?? null) !== null, fn ($query) => $query->where(
                'is_active',
                $filters['status'] === 'active'
            ))
            ->orderBy('name')->paginate(15)->withQueryString();

        return view('admin.roles.index', compact('roles', 'filters'));
    }

    public function create(): View
    {
        return view('admin.roles.form', [
            'role' => new Role(['is_active' => true]),
            'permissions' => Permission::query()->orderBy('key')->get(),
            'selectedPermissions' => [],
            'creating' => true,
        ]);
    }

    public function store(
        Request $request,
        AdministrativeAccess $access,
        AdminAuditLogger $audit,
    ): RedirectResponse {
        $hasPermissionPayload = $request->boolean('permission_assignment_present') || $request->exists('permission_ids');
        abort_if($hasPermissionPayload && ! $request->user()->can('permissions.assign'), 403);
        if ($request->boolean('permission_assignment_present')) {
            $request->merge(['permission_ids' => $request->input('permission_ids', [])]);
        }
        $request->merge(['key' => mb_strtolower(trim((string) $request->input('key')))]);
        $validated = $request->validate([
            'name' => ['required', 'string', 'max:100'],
            'key' => ['required', 'alpha_dash:ascii', 'max:100', 'unique:roles,key'],
            'description' => ['nullable', 'string', 'max:500'],
            'permission_ids' => ['sometimes', 'array'],
            'permission_ids.*' => ['integer', 'distinct', 'exists:permissions,id'],
        ]);

        $role = DB::transaction(function () use ($request, $validated, $access, $audit, $hasPermissionPayload): Role {
            $create = function () use ($request, $validated, $access, $audit): Role {
                $role = Role::query()->create([
                    'name' => $validated['name'],
                    'key' => $validated['key'],
                    'description' => $validated['description'] ?? null,
                    'is_active' => true,
                ]);
                if (array_key_exists('permission_ids', $validated)) {
                    $access->syncRolePermissions($request->user(), $role, array_map('intval', $validated['permission_ids'] ?? []));
                    $audit->record('role.permissions_changed', 'role', $role->id, null, [
                        'permissions' => $role->permissions()->pluck('key')->all(),
                    ]);
                }
                $audit->record('role.created', 'role', $role->id, null, $this->safeState($role));

                return $role;
            };

            return $hasPermissionPayload ? $access->transaction($create) : $create();
        });

        return redirect()->route('admin.roles.edit', $role)->with('status', 'Rol creado.');
    }

    public function edit(Role $role): View
    {
        return view('admin.roles.form', [
            'role' => $role->load('permissions'),
            'permissions' => Permission::query()->orderBy('key')->get(),
            'selectedPermissions' => $role->permissions->modelKeys(),
            'creating' => false,
        ]);
    }

    public function update(
        Request $request,
        Role $role,
        AdministrativeAccess $access,
        SessionInvalidator $sessions,
        AdminAuditLogger $audit,
    ): RedirectResponse {
        $hasPermissionPayload = $request->boolean('permission_assignment_present') || $request->exists('permission_ids');
        abort_if($hasPermissionPayload && ! $request->user()->can('permissions.assign'), 403);
        if ($request->boolean('permission_assignment_present')) {
            $request->merge(['permission_ids' => $request->input('permission_ids', [])]);
        }
        $validated = $request->validate([
            'name' => ['required', 'string', 'max:100'],
            'description' => ['nullable', 'string', 'max:500'],
            'permission_ids' => ['sometimes', 'array'],
            'permission_ids.*' => ['integer', 'distinct', 'exists:permissions,id'],
        ]);
        $mutate = function () use ($request, $role, $validated, $access, $sessions, $audit): void {
            $role->refresh();
            $before = $this->safeState($role);
            $beforePermissions = $role->permissions()->orderBy('key')->pluck('key')->all();
            $role->update([
                'name' => $validated['name'],
                'description' => $validated['description'] ?? null,
            ]);

            if (array_key_exists('permission_ids', $validated)) {
                $changed = $access->syncRolePermissions(
                    $request->user(),
                    $role,
                    array_map('intval', $validated['permission_ids'] ?? []),
                );
                if ($changed) {
                    $sessions->invalidate($role->users()->pluck('users.id')->all());
                    $audit->record('role.permissions_changed', 'role', $role->id, [
                        'permissions' => $beforePermissions,
                    ], ['permissions' => $role->permissions()->orderBy('key')->pluck('key')->all()]);
                }
            }

            if ($before['name'] !== $role->name || $before['description'] !== $role->description) {
                $audit->record('role.updated', 'role', $role->id, [
                    'name' => $before['name'],
                    'description' => $before['description'],
                ], ['name' => $role->name, 'description' => $role->description]);
            }
        };

        if ($hasPermissionPayload) {
            $access->transaction($mutate);
        } else {
            DB::transaction($mutate);
        }

        return redirect()->route('admin.roles.edit', $role)->with('status', 'Cambios guardados.');
    }

    public function toggleStatus(
        Request $request,
        Role $role,
        AdministrativeAccess $access,
        AdministrativeState $state,
    ): RedirectResponse {
        $active = ! $role->is_active;
        $access->transaction(fn () => $state->changeRoleStatus($request->user(), $role, $active));

        return back()->with('status', $active ? 'Rol activado.' : 'Rol desactivado.');
    }

    public function updatePermissions(
        Request $request,
        Role $role,
        AdministrativeAccess $access,
        SessionInvalidator $sessions,
        AdminAuditLogger $audit,
    ): RedirectResponse {
        $validated = $request->validate([
            'permission_ids' => ['sometimes', 'array'],
            'permission_ids.*' => ['integer', 'distinct', 'exists:permissions,id'],
        ]);
        $permissionIds = array_map('intval', $validated['permission_ids'] ?? []);

        $access->transaction(function () use ($request, $role, $access, $sessions, $audit, $permissionIds): void {
            $role->refresh();
            $before = $role->permissions()->orderBy('key')->pluck('key')->all();
            $changed = $access->syncRolePermissions($request->user(), $role, $permissionIds);
            if (! $changed) {
                return;
            }
            $sessions->invalidate($role->users()->pluck('users.id')->all());
            $audit->record('role.permissions_changed', 'role', $role->id, ['permissions' => $before], [
                'permissions' => $role->permissions()->orderBy('key')->pluck('key')->all(),
            ]);
        });

        return back()->with('status', 'Permisos del rol actualizados.');
    }

    private function safeState(Role $role): array
    {
        return [
            'name' => $role->name,
            'key' => $role->key,
            'description' => $role->description,
            'is_active' => $role->is_active,
            'permissions' => $role->permissions()->orderBy('key')->pluck('key')->all(),
        ];
    }
}
