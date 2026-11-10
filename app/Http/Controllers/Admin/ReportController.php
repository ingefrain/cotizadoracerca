<?php

namespace App\Http\Controllers\Admin;

use App\Reports\CsvValue;
use App\Support\AdminAuditLogger;
use App\Support\ImpersonationManager;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\StreamedResponse;

final class ReportController
{
    public function __construct(private readonly ImpersonationManager $impersonation) {}

    public function index(Request $request): View
    {
        abort_unless($this->allows($request, 'reports.view'), 403);
        $cards = collect(config('admin_reports.reports'))
            ->filter(fn (array $card): bool => $this->allows($request, $card['permission']));

        return view('admin.reports.index', compact('cards'));
    }

    public function show(Request $request, string $key): View
    {
        $definition = $this->definition($request, $key, 'reports.view');
        $report = app($definition['report']);
        $fields = $report->filters();
        $validator = $this->validator($request, $fields);
        $filters = $validator->fails() ? [] : $validator->validated();
        $filterErrors = $validator->errors();
        $results = $filterErrors->isEmpty()
            ? $report->query($filters)->orderBy('id')->paginate(25)->appends($filters)
            : null;
        $columns = $report->columns();
        $rows = $results?->getCollection()->map($report->row(...));
        $canExport = $filterErrors->isEmpty() && $this->allows($request, 'reports.export');
        $maxRows = $this->maxRows();

        return view('admin.reports.show', compact(
            'definition', 'fields', 'filters', 'filterErrors', 'results', 'columns', 'rows', 'canExport', 'maxRows',
        ));
    }

    public function export(Request $request, string $key, AdminAuditLogger $audit): StreamedResponse
    {
        $definition = $this->definition($request, $key, 'reports.export');
        $report = app($definition['report']);
        $validator = $this->validator($request, $report->filters());
        abort_if($validator->fails(), 422, 'Revisa los filtros del reporte.');
        $filters = $validator->validated();
        $query = $report->query($filters);
        $limit = $this->maxRows();
        $auditFilters = $filters;
        unset($auditFilters['q']);
        $audit->record("report.{$key}.exported", 'report', $key, context: [
            'filters' => $auditFilters,
            'search_applied' => isset($filters['q']),
            'max_rows' => $limit,
        ]);

        return response()->streamDownload(function () use ($report, $query, $limit): void {
            $stream = fopen('php://output', 'wb');
            try {
                fwrite($stream, "\xEF\xBB\xBF");
                fputcsv($stream, $report->columns(), ',', '"', '', "\r\n");
                foreach ($query->lazyById(min(250, $limit))->take($limit) as $record) {
                    fputcsv($stream, array_map(CsvValue::format(...), $report->row($record)), ',', '"', '', "\r\n");
                }
            } finally {
                fclose($stream);
            }
        }, $definition['filename'].'-'.now()->toDateString().'.csv', [
            'Content-Type' => 'text/csv; charset=UTF-8',
            'Cache-Control' => 'private, no-store',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }

    private function definition(Request $request, string $key, string $permission): array
    {
        $definition = config('admin_reports.reports')[$key] ?? null;
        abort_if($definition === null, 404);
        abort_unless($this->allows($request, $permission) && $this->allows($request, $definition['permission']), 403);

        return $definition;
    }

    private function allows(Request $request, string $permission): bool
    {
        // Both identities must be authorized during impersonation.
        return $request->user()->hasPermissionTo($permission)
            && (! $this->impersonation->active() || $this->impersonation->actor()?->hasPermissionTo($permission));
    }

    private function validator(Request $request, array $fields): \Illuminate\Validation\Validator
    {
        $rules = [];
        $labels = [];
        foreach ($fields as $key => $field) {
            $rules[$key] = ['sometimes', 'nullable', ...$field['rules']];
            $labels[$key] = $field['label'];
        }
        if (! $request->filled('from')) {
            $rules['to'] = ['sometimes', 'nullable', 'date_format:Y-m-d'];
        }

        return Validator::make($request->query(), $rules, [
            'date_format' => 'Selecciona una fecha válida en :attribute.',
            'after_or_equal' => 'La fecha final debe ser igual o posterior a la inicial.',
            'in' => 'Selecciona una opción válida en :attribute.',
            'exists' => 'Selecciona un rol válido.',
            'string' => 'Introduce un valor de texto válido en :attribute.',
            'max' => 'El campo :attribute admite hasta :max caracteres.',
        ], $labels);
    }

    private function maxRows(): int
    {
        return max(1, (int) config('admin_reports.export_max_rows'));
    }
}
