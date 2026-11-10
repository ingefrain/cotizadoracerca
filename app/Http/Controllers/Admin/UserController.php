<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Role;
use App\Models\User;
use App\Support\AdminAuditLogger;
use App\Support\AdministrativeAccess;
use App\Support\AdministrativeState;
use App\Support\PasswordPolicy;
use App\Support\SessionInvalidator;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

class UserController extends Controller
{
    public function index(Request $request): View
    {
        $filters = $request->validate([
            'q' => ['nullable', 'string', 'max:120'],
            'status' => ['nullable', Rule::in(['active', 'inactive'])],
            'role' => ['nullable', 'integer', 'exists:roles,id'],
        ]);

        $users = User::query()->with(['roles', 'avatar'])->when($filters['q'] ?? null, function ($query, string $search): void {
            $query->where(fn ($query) => $query
                ->where('name', 'like', '%'.$search.'%')
                ->orWhere('email', 'like', '%'.$search.'%'));
        })->when(($filters['status'] ?? null) !== null, fn ($query) => $query->where(
            'is_active',
            $filters['status'] === 'active'
        ))->when($filters['role'] ?? null, fn ($query, int $roleId) => $query->whereHas(
            'roles',
            fn ($query) => $query->where('roles.id', $roleId)
        ))->orderBy('name')->paginate(15)->withQueryString();

        return view('admin.users.index', [
            'users' => $users,
            'roles' => Role::query()->orderBy('name')->get(),
            'filters' => $filters,
        ]);
    }

    public function create(): View
    {
        return view('admin.users.form', [
            'user' => new User(['is_active' => true]),
            'roles' => Role::query()->where('is_active', true)->orderBy('name')->get(),
            'selectedRoles' => [],
            'creating' => true,
        ]);
    }

    public function store(Request $request, AdministrativeAccess $access, AdminAuditLogger $audit): RedirectResponse
    {
        $hasRolePayload = $request->boolean('role_assignment_present') || $request->exists('role_ids');
        abort_if($hasRolePayload && ! $request->user()->can('roles.assign'), 403);
        if ($request->boolean('role_assignment_present')) {
            $request->merge(['role_ids' => $request->input('role_ids', [])]);
        }
        $request->merge(['email' => mb_strtolower(trim((string) $request->input('email')))]);
        $validated = $request->validate([
            'name' => ['required', 'string', 'max:120'],
            'email' => ['required', 'email', 'max:255', 'unique:users,email'],
            'password' => PasswordPolicy::rules(),
            'role_ids' => ['sometimes', 'array'],
            'role_ids.*' => ['integer', 'distinct', 'exists:roles,id'],
        ]);

        $roleIds = array_map('intval', $validated['role_ids'] ?? []);
        $user = DB::transaction(function () use ($request, $validated, $roleIds, $access, $audit): User {
            $user = User::query()->create([
                'name' => $validated['name'],
                'email' => mb_strtolower(trim($validated['email'])),
                'password' => $validated['password'],
                'is_active' => true,
            ]);

            if (array_key_exists('role_ids', $validated)) {
                $access->transaction(fn () => $access->syncUserRoles($request->user(), $user, $roleIds));
                $audit->record('user.roles_assigned', 'user', $user->id, null, ['roles' => $user->roles()->pluck('key')->all()]);
            }

            $audit->record('user.created', 'user', $user->id, null, $this->safeState($user));

            return $user;
        });

        return redirect()->route('admin.users.edit', $user)->with('status', 'Usuario creado correctamente.');
    }

    public function edit(User $user): View
    {
        return view('admin.users.form', [
            'user' => $user->load('roles'),
            'roles' => Role::query()->orderBy('name')->get(),
            'selectedRoles' => $user->roles->modelKeys(),
            'creating' => false,
        ]);
    }

    public function update(
        Request $request,
        User $user,
        AdministrativeAccess $access,
        SessionInvalidator $sessions,
        AdminAuditLogger $audit,
    ): RedirectResponse {
        $hasRolePayload = $request->boolean('role_assignment_present') || $request->exists('role_ids');
        abort_if($hasRolePayload && ! $request->user()->can('roles.assign'), 403);
        if ($request->boolean('role_assignment_present')) {
            $request->merge(['role_ids' => $request->input('role_ids', [])]);
        }
        $request->merge(['email' => mb_strtolower(trim((string) $request->input('email')))]);
        $validated = $request->validate([
            'name' => ['required', 'string', 'max:120'],
            'email' => ['required', 'email', 'max:255', Rule::unique('users', 'email')->ignore($user->id)],
            'password' => PasswordPolicy::rules('nullable'),
            'role_ids' => ['sometimes', 'array'],
            'role_ids.*' => ['integer', 'distinct', 'exists:roles,id'],
        ]);

        $mutate = function () use ($request, $user, $validated, $access, $sessions, $audit): void {
            $user->refresh();
            $before = $this->safeState($user);
            $attributes = [
                'name' => $validated['name'],
                'email' => mb_strtolower(trim($validated['email'])),
            ];
            if (filled($validated['password'] ?? null)) {
                $attributes['password'] = $validated['password'];
            }
            $user->fill($attributes)->save();

            if (array_key_exists('role_ids', $validated)) {
                $rolesChanged = $access->syncUserRoles(
                    $request->user(),
                    $user,
                    array_map('intval', $validated['role_ids'] ?? []),
                );
                if ($rolesChanged) {
                    $sessions->invalidate([$user->id]);
                    $audit->record('user.roles_assigned', 'user', $user->id, $before, [
                        'roles' => $user->roles()->pluck('key')->all(),
                    ]);
                }
            }

            if (filled($validated['password'] ?? null)) {
                $sessions->invalidate([$user->id]);
                $audit->record('user.password_reset', 'user', $user->id);
            }

            if ($before !== $this->safeState($user)) {
                $audit->record('user.updated', 'user', $user->id, $before, $this->safeState($user));
            }
        };

        if (array_key_exists('role_ids', $validated)) {
            $access->transaction($mutate);
        } else {
            DB::transaction($mutate);
        }

        return redirect()->route('admin.users.edit', $user)->with('status', 'Cambios guardados.');
    }

    public function toggleStatus(
        Request $request,
        User $user,
        AdministrativeAccess $access,
        AdministrativeState $state,
    ): RedirectResponse {
        $active = ! $user->is_active;
        $access->transaction(function () use ($request, $user, $active, $state): void {
            $state->changeUserStatus($request->user(), $user, $active);
        });

        return back()->with('status', $active ? 'Usuario activado.' : 'Usuario desactivado.');
    }

    private function safeState(User $user): array
    {
        return [
            'name' => $user->name,
            'email' => $user->email,
            'is_active' => $user->is_active,
            'roles' => $user->roles()->orderBy('key')->pluck('key')->all(),
        ];
    }
}
