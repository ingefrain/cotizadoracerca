<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Imports\ImportAccess;
use App\Imports\ImportDefinition;
use App\Imports\ImportException;
use App\Imports\ImportService;
use App\Models\ImportRun;
use Illuminate\Http\Request;

final class ImportController extends Controller
{
    public function __construct(private readonly ImportAccess $access, private readonly ImportService $imports) {}

    public function index(Request $request)
    {
        $user = $request->user();
        abort_unless($this->access->allows($user, 'imports.view'), 403);
        $types = array_filter(ImportDefinition::TYPES, fn ($definition) => $this->access->allows($user, $definition['permission']));
        $canCreate = $this->access->allows($user, 'imports.create');
        $runs = ImportRun::query()->where('created_by', $this->access->actor($user)->id)
            ->where('effective_user_id', $user->id)->whereIn('type', array_keys($types))->with('creator')->latest('id')->paginate(15);

        return view('admin.imports.index', compact('runs', 'types', 'canCreate'));
    }

    public function create(Request $request, string $type)
    {
        $definition = $this->authorizeType($request, $type, 'imports.create');

        return view('admin.imports.create', compact('type', 'definition'));
    }

    public function template(Request $request, string $type)
    {
        $definition = $this->authorizeType($request, $type, 'imports.create');

        return response()->streamDownload(function () use ($definition): void {
            echo "\xEF\xBB\xBF".implode(',', $definition['headers'])."\r\n";
        }, $type.'-plantilla.csv', ['Content-Type' => 'text/csv; charset=UTF-8', 'Cache-Control' => 'private, no-store']);
    }

    public function store(Request $request, string $type)
    {
        $this->authorizeType($request, $type, 'imports.create');
        $request->validate(['csv' => ['required', 'file', 'extensions:csv', 'max:'.max(1, (int) config('imports.max_size_kb'))]]);
        $user = $request->user();
        $run = $this->imports->upload($request->file('csv'), $type, $user, $this->access->actor($user));

        return redirect()->route('admin.imports.show', $run);
    }

    public function show(Request $request, ImportRun $run)
    {
        $definition = $this->authorizeRun($request, $run);
        $sample = $this->imports->preview($run);
        $rowErrors = $run->errors()->orderBy('row_number')->orderBy('id')->paginate(25);
        $canApply = $run->status === 'ready' && $run->expires_at->isFuture()
            && $this->access->allows($request->user(), 'imports.apply');
        $canDelete = $run->canBeDeleted() && $this->access->allows($request->user(), 'imports.create');

        return response()->view('admin.imports.show', compact('run', 'definition', 'sample', 'rowErrors', 'canApply', 'canDelete'))
            ->header('Cache-Control', 'private, no-store');
    }

    public function destroy(Request $request, ImportRun $run)
    {
        $this->authorizeRun($request, $run, 'imports.create');
        try {
            $this->imports->delete($run);
        } catch (ImportException $exception) {
            return redirect()->route('admin.imports.show', $run)->with('notify', ['type' => 'error', 'message' => $exception->getMessage()]);
        }

        return redirect()->route('admin.imports.index')->with('notify', ['type' => 'success', 'message' => 'Importación eliminada.']);
    }

    public function apply(Request $request, ImportRun $run)
    {
        $this->authorizeRun($request, $run, 'imports.apply');
        $run = $this->imports->apply($run, $request->user());
        $completed = $run->status === 'completed';

        return redirect()->route('admin.imports.show', $run)->with('notify', [
            'type' => $completed ? 'success' : 'error',
            'message' => $completed ? "Importación completada: {$run->applied_rows} registros creados." : 'No se aplicaron cambios. Revisa el resultado de la importación.',
        ]);
    }

    private function authorizeRun(Request $request, ImportRun $run, string ...$permissions): array
    {
        $user = $request->user();
        $definition = $this->authorizeType($request, $run->type, ...$permissions);
        abort_unless((int) $run->created_by === $this->access->actor($user)->id && (int) $run->effective_user_id === $user->id, 404);

        return $definition;
    }

    private function authorizeType(Request $request, string $type, string ...$permissions): array
    {
        $definition = ImportDefinition::TYPES[$type] ?? abort(404);
        abort_unless($this->access->allows($request->user(), 'imports.view', $definition['permission'], ...$permissions), 403);

        return $definition;
    }
}
