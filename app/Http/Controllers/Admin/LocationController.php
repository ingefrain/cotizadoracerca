<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Location;
use App\Models\Organization;
use App\Support\AdminAuditLogger;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

class LocationController extends Controller
{
    public function index(Request $request): View
    {
        $filters = $request->validate(['q' => ['nullable', 'string', 'max:120'], 'status' => ['nullable', Rule::in(['active', 'inactive'])], 'organization' => ['nullable', 'uuid', 'exists:organizations,uuid']]);
        $locations = Location::query()->with('organization')
            ->when($filters['q'] ?? null, fn ($query, string $q) => $query->where(fn ($sub) => $sub->where('locations.name', 'like', "%{$q}%")->orWhere('code', 'like', "%{$q}%")->orWhere('city', 'like', "%{$q}%")->orWhereHas('organization', fn ($org) => $org->where('name', 'like', "%{$q}%"))))
            ->when(isset($filters['status']), fn ($query) => $query->where('is_active', $filters['status'] === 'active'))
            ->when($filters['organization'] ?? null, fn ($query, string $uuid) => $query->whereHas('organization', fn ($org) => $org->where('uuid', $uuid)))
            ->orderBy('name')->paginate(15)->withQueryString();

        return view('admin.locations.index', compact('locations', 'filters'));
    }

    public function create(Request $request): View
    {
        $location = new Location(['is_active' => true]);
        if ($uuid = $request->query('organization')) {
            $location->organization()->associate(Organization::query()->where('uuid', $uuid)->firstOrFail());
        }

        return view('admin.locations.form', ['location' => $location, 'organizations' => Organization::query()->where('is_active', true)->orderBy('name')->get(), 'creating' => true]);
    }

    public function store(Request $request, AdminAuditLogger $audit): RedirectResponse
    {
        $data = $this->validated($request);
        $organization = Organization::query()->findOrFail($data['organization_id']);
        abort_unless($organization->is_active, 422, 'No es posible crear sedes en una organización inactiva.');
        $location = DB::transaction(function () use ($data, $audit): Location {
            $location = Location::query()->create($data);
            $audit->record('location.created', 'location', $location->uuid, null, $this->safeState($location));

            return $location;
        });

        return redirect()->route('admin.locations.edit', $location)->with('status', 'Sede creada.');
    }

    public function edit(Location $location): View
    {
        $organizations = Organization::query()->where('is_active', true)->orWhereKey($location->organization_id)->orderBy('name')->get();

        return view('admin.locations.form', compact('location', 'organizations') + ['creating' => false]);
    }

    public function update(Request $request, Location $location, AdminAuditLogger $audit): RedirectResponse
    {
        $data = $this->validated($request, $location);
        $organization = Organization::query()->findOrFail($data['organization_id']);
        abort_unless($organization->is_active || $organization->is($location->organization), 422, 'Solo se puede cambiar a una organización activa.');
        DB::transaction(function () use ($location, $data, $audit): void {
            $before = $this->safeState($location);
            $location->update($data);
            $after = $this->safeState($location);
            if ($before !== $after) {
                $audit->record('location.updated', 'location', $location->uuid, $before, $after);
            }
        });

        return back()->with('status', 'Sede actualizada.');
    }

    public function toggleStatus(Location $location, AdminAuditLogger $audit): RedirectResponse
    {
        $before = $location->is_active;
        $location->is_active = ! $before;
        $location->save();
        $audit->record($location->is_active ? 'location.enabled' : 'location.disabled', 'location', $location->uuid, ['is_active' => $before], ['is_active' => $location->is_active]);

        return back()->with('status', $location->is_active ? 'Sede activada.' : 'Sede desactivada.');
    }

    private function validated(Request $request, ?Location $location = null): array
    {
        $data = $request->validate([
            'organization_id' => ['required', 'integer', 'exists:organizations,id'], 'name' => ['required', 'string', 'max:180'],
            'code' => ['nullable', 'string', 'max:100', Rule::unique('locations', 'code')->where('organization_id', $request->input('organization_id'))->ignore($location?->id)],
            'email' => ['nullable', 'email', 'max:254'], 'phone' => ['nullable', 'string', 'max:50'],
            'address_line_1' => ['nullable', 'string', 'max:180'], 'address_line_2' => ['nullable', 'string', 'max:180'],
            'city' => ['nullable', 'string', 'max:120'], 'state' => ['nullable', 'string', 'max:120'], 'postal_code' => ['nullable', 'string', 'max:30'],
            'country_code' => ['nullable', 'alpha:ascii', 'size:2'], 'latitude' => ['nullable', 'numeric', 'between:-90,90'], 'longitude' => ['nullable', 'numeric', 'between:-180,180'],
        ]);
        foreach ($data as $key => $value) {
            if (is_string($value)) {
                $data[$key] = trim($value) === '' ? null : trim($value);
            }
        }
        if (isset($data['country_code'])) {
            $data['country_code'] = strtoupper($data['country_code']);
        }

        return $data;
    }

    private function safeState(Location $location): array
    {
        return ['organization_uuid' => $location->organization?->uuid, 'name' => $location->name, 'code' => $location->code, 'is_active' => $location->is_active];
    }
}
