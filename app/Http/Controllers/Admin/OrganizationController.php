<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Organization;
use App\Services\Documents\DocumentService;
use App\Support\AdminAuditLogger;
use App\Support\ImpersonationManager;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

class OrganizationController extends Controller
{
    public function index(Request $request): View
    {
        $filters = $request->validate(['q' => ['nullable', 'string', 'max:120'], 'status' => ['nullable', Rule::in(['active', 'inactive'])]]);
        $organizations = Organization::query()->withCount('locations')->with('logo')
            ->when($filters['q'] ?? null, fn ($query, string $q) => $query->where(fn ($sub) => $sub->where('name', 'like', "%{$q}%")->orWhere('legal_name', 'like', "%{$q}%")->orWhere('slug', 'like', "%{$q}%")))
            ->when(isset($filters['status']), fn ($query) => $query->where('is_active', $filters['status'] === 'active'))
            ->orderBy('name')->paginate(15)->withQueryString();

        return view('admin.organizations.index', compact('organizations', 'filters'));
    }

    public function create(): View
    {
        return view('admin.organizations.form', ['organization' => new Organization(['is_active' => true]), 'creating' => true]);
    }

    public function store(Request $request, DocumentService $documents, ImpersonationManager $impersonation, AdminAuditLogger $audit): RedirectResponse
    {
        $validated = $this->validated($request);
        $organization = DB::transaction(function () use ($validated, $request, $documents, $impersonation, $audit): Organization {
            $organization = Organization::query()->create($this->attributes($validated));
            $audit->record('organization.created', 'organization', $organization->uuid, null, $this->safeState($organization));
            if ($request->hasFile('logo')) {
                $documents->upload($request->file('logo'), $organization, $impersonation->actor() ?? $request->user(), 'organization.logo', ['title' => 'Logotipo de organización']);
            }

            return $organization;
        });

        return redirect()->route('admin.organizations.edit', $organization)->with('status', 'Organización creada.');
    }

    public function edit(Organization $organization): View
    {
        return view('admin.organizations.form', ['organization' => $organization->load('logo'), 'creating' => false]);
    }

    public function update(Request $request, Organization $organization, DocumentService $documents, ImpersonationManager $impersonation, AdminAuditLogger $audit): RedirectResponse
    {
        $validated = $this->validated($request, $organization);
        DB::transaction(function () use ($request, $organization, $validated, $documents, $impersonation, $audit): void {
            $before = $this->safeState($organization);
            $organization->update($this->attributes($validated));
            $after = $this->safeState($organization);
            if ($before !== $after) {
                $audit->record('organization.updated', 'organization', $organization->uuid, $before, $after);
            }
            if ($request->hasFile('logo')) {
                $logo = $organization->logo;
                if ($logo) {
                    $documents->update($logo, [], $impersonation->actor() ?? $request->user(), $request->file('logo'));
                } else {
                    $documents->upload($request->file('logo'), $organization, $impersonation->actor() ?? $request->user(), 'organization.logo', ['title' => 'Logotipo de organización']);
                }
            }
        });

        return back()->with('status', 'Organización actualizada.');
    }

    public function toggleStatus(Organization $organization, AdminAuditLogger $audit): RedirectResponse
    {
        $before = $organization->is_active;
        $organization->is_active = ! $before;
        $organization->save();
        $audit->record($organization->is_active ? 'organization.enabled' : 'organization.disabled', 'organization', $organization->uuid, ['is_active' => $before], ['is_active' => $organization->is_active]);

        return back()->with('status', $organization->is_active ? 'Organización activada.' : 'Organización desactivada.');
    }

    public function deleteLogo(Request $request, Organization $organization, DocumentService $documents): RedirectResponse
    {
        abort_unless($logo = $organization->logo, 404);
        $documents->delete($logo, app(ImpersonationManager::class)->actor() ?? $request->user());

        return back()->with('status', 'Logotipo eliminado.');
    }

    private function validated(Request $request, ?Organization $organization = null): array
    {
        $rawName = $request->input('name');
        $name = is_string($rawName) ? trim($rawName) : '';
        $rawSlug = $request->input('slug');
        $candidate = (is_string($rawSlug) ? trim($rawSlug) : '') ?: Str::slug($name);
        $candidate = Str::limit($candidate ?: 'organizacion', 140, '');
        $base = $candidate;
        $suffix = 2;
        while (Organization::query()->where('slug', $candidate)->when($organization, fn ($q) => $q->where('id', '<>', $organization->getKey()))->exists()) {
            $candidate = Str::limit($base, 130, '').'-'.$suffix++;
        }
        $request->merge(['slug' => $candidate]);

        return $request->validate([
            'name' => ['required', 'string', 'max:180'], 'legal_name' => ['nullable', 'string', 'max:180'],
            'slug' => ['required', 'alpha_dash:ascii', 'max:140', Rule::unique('organizations', 'slug')->ignore($organization?->id)],
            'email' => ['nullable', 'email', 'max:254'], 'phone' => ['nullable', 'string', 'max:50'],
            'website' => ['nullable', 'url', 'max:255'], 'tax_id' => ['nullable', 'string', 'max:120'], 'notes' => ['nullable', 'string', 'max:5000'],
            'logo' => ['nullable', 'file', 'image', 'mimes:jpeg,jpg,png,webp', 'max:5120'],
        ]);
    }

    private function attributes(array $data): array
    {
        foreach (['name', 'legal_name', 'slug', 'email', 'phone', 'website', 'tax_id', 'notes'] as $field) {
            $data[$field] = isset($data[$field]) && trim($data[$field]) !== '' ? trim($data[$field]) : null;
        }
        unset($data['logo']);

        return $data;
    }

    private function safeState(Organization $organization): array
    {
        return ['name' => $organization->name, 'legal_name' => $organization->legal_name, 'slug' => $organization->slug, 'is_active' => $organization->is_active];
    }
}
