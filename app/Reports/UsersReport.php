<?php

namespace App\Reports;

use App\Models\Role;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

final class UsersReport extends Report
{
    public function columns(): array
    {
        return ['Nombre', 'Correo', 'Estado', 'Roles', 'Último login', 'Última actividad', 'Fecha de creación'];
    }

    public function specificFilters(): array
    {
        return [
            'role' => ['label' => 'Rol', 'options' => fn () => Role::query()->orderBy('name')->pluck('name', 'key'), 'rules' => ['string', 'exists:roles,key']],
        ];
    }

    protected function filteredQuery(array $filters): Builder
    {
        return User::query()
            ->select(['id', 'name', 'email', 'is_active', 'last_login_at', 'last_activity_at', 'created_at'])
            ->with(['roles' => fn ($roles) => $roles->select('roles.id', 'roles.name')->orderBy('roles.name')->orderBy('roles.id')])
            ->when(isset($filters['q']), function (Builder $query) use ($filters): void {
                $query->where(fn (Builder $search) => $search
                    ->whereLike('name', '%'.$filters['q'].'%')
                    ->orWhereLike('email', '%'.$filters['q'].'%'));
            })
            ->when(isset($filters['role']), fn (Builder $query) => $query
                ->whereHas('roles', fn (Builder $roles) => $roles->where('roles.key', $filters['role'])));
    }

    public function row(Model $record): array
    {
        return [
            $record->name, $record->email, $record->is_active ? 'Activo' : 'Inactivo',
            $record->roles->pluck('name')->implode(' | '), $record->last_login_at,
            $record->last_activity_at, $record->created_at,
        ];
    }
}
