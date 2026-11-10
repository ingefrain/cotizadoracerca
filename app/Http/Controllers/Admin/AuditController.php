<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\AdminAuditEvent;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

class AuditController extends Controller
{
    public function index(Request $request): View
    {
        $filters = $request->validate([
            'action' => ['nullable', 'string', 'max:100'],
            'entity' => ['nullable', Rule::in(['user', 'role', 'report'])],
        ]);
        $events = AdminAuditEvent::query()->with('actor')
            ->when($filters['action'] ?? null, fn ($query, string $action) => $query->where('action', $action))
            ->when($filters['entity'] ?? null, fn ($query, string $entity) => $query->where('entity_type', $entity))
            ->latest('created_at')->paginate(20)->withQueryString();

        return view('admin.audit.index', compact('events', 'filters'));
    }
}
