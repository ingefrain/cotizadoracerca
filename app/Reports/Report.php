<?php

namespace App\Reports;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

abstract class Report
{
    abstract public function columns(): array;

    abstract public function specificFilters(): array;

    abstract public function row(Model $record): array;

    abstract protected function filteredQuery(array $filters): Builder;

    public function filters(): array
    {
        return [
            'q' => ['label' => 'Buscar', 'type' => 'search', 'rules' => ['string', 'max:120']],
            'status' => ['label' => 'Estado', 'options' => ['active' => 'Activos', 'inactive' => 'Inactivos'], 'rules' => ['in:active,inactive']],
            ...$this->specificFilters(),
            'from' => ['label' => 'Creación desde', 'type' => 'date', 'rules' => ['date_format:Y-m-d']],
            'to' => ['label' => 'Creación hasta', 'type' => 'date', 'rules' => ['date_format:Y-m-d', 'after_or_equal:from']],
        ];
    }

    public function query(array $filters): Builder
    {
        $query = $this->filteredQuery($filters);
        if (isset($filters['status'])) {
            $query->where('is_active', $filters['status'] === 'active');
        }
        if (isset($filters['from'])) {
            $query->where('created_at', '>=', $filters['from'].' 00:00:00');
        }
        if (isset($filters['to'])) {
            $query->where('created_at', '<', Carbon::parse($filters['to'])->addDay()->startOfDay());
        }

        return $query;
    }
}
