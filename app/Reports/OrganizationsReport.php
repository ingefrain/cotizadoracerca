<?php

namespace App\Reports;

use App\Models\Organization;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

final class OrganizationsReport extends Report
{
    public function columns(): array
    {
        return ['Nombre', 'Nombre legal', 'Estado', 'Sedes', 'Correo', 'Teléfono', 'Fecha de creación'];
    }

    public function specificFilters(): array
    {
        return [
            'locations' => ['label' => 'Sedes', 'options' => ['with' => 'Con sedes', 'without' => 'Sin sedes'], 'rules' => ['in:with,without']],
        ];
    }

    protected function filteredQuery(array $filters): Builder
    {
        return Organization::query()
            ->select(['id', 'name', 'legal_name', 'is_active', 'email', 'phone', 'created_at'])
            ->withCount('locations')
            ->when(isset($filters['q']), function (Builder $query) use ($filters): void {
                $query->where(fn (Builder $search) => $search
                    ->whereLike('name', '%'.$filters['q'].'%')
                    ->orWhereLike('legal_name', '%'.$filters['q'].'%'));
            })
            ->when(($filters['locations'] ?? null) === 'with', fn (Builder $query) => $query->has('locations'))
            ->when(($filters['locations'] ?? null) === 'without', fn (Builder $query) => $query->doesntHave('locations'));
    }

    public function row(Model $record): array
    {
        return [
            $record->name, $record->legal_name, $record->is_active ? 'Activa' : 'Inactiva',
            $record->locations_count, $record->email, $record->phone, $record->created_at,
        ];
    }
}
