<?php

namespace App\Imports;

use App\Models\Catalog;
use App\Models\CatalogItem;
use App\Models\Location;
use App\Models\Organization;
use App\Models\User;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

final class ImportDefinition
{
    public const TYPES = [
        'catalog_items' => ['title' => 'Elementos de catálogo', 'permission' => 'catalog_items.create', 'model' => CatalogItem::class,
            'headers' => ['catalog_slug', 'code', 'name', 'slug', 'description_markdown', 'sort_order', 'is_active']],
        'organizations' => ['title' => 'Organizaciones', 'permission' => 'organizations.create', 'model' => Organization::class,
            'headers' => ['name', 'legal_name', 'slug', 'email', 'phone', 'website', 'tax_id', 'notes', 'is_active']],
        'locations' => ['title' => 'Sedes', 'permission' => 'locations.create', 'model' => Location::class,
            'headers' => ['organization_slug', 'name', 'code', 'email', 'phone', 'address_line_1', 'address_line_2', 'city', 'state', 'postal_code', 'country_code', 'latitude', 'longitude', 'is_active']],
    ];

    private array $seen = [];

    private array $parents = [];

    public function __construct(private readonly string $type, private readonly User $user, private readonly bool $lockParents = false) {}

    public function validate(array $row): array
    {
        $data = array_map(fn ($value) => trim((string) $value) === '' ? null : trim($value), $row);
        if (isset($data['description_markdown'])) {
            $data['description_markdown'] = $row['description_markdown'];
        }

        $rules = ['name' => ['required', 'string', 'max:180'], 'is_active' => ['nullable', Rule::in(['0', '1', 'true', 'false'])]];
        $rules += match ($this->type) {
            'catalog_items' => [
                'catalog_slug' => ['required', 'alpha_dash:ascii', 'max:140'],
                'code' => ['nullable', 'string', 'max:100'],
                'description_markdown' => ['nullable', 'string', 'max:16000'],
                'sort_order' => ['nullable', 'integer', 'between:-2147483648,2147483647'],
            ],
            'organizations' => [
                'legal_name' => ['nullable', 'string', 'max:180'], 'email' => ['nullable', 'email', 'max:254'],
                'phone' => ['nullable', 'string', 'max:50'], 'website' => ['nullable', 'url:http,https', 'max:255'],
                'tax_id' => ['nullable', 'string', 'max:120'], 'notes' => ['nullable', 'string', 'max:5000'],
            ],
            'locations' => [
                'organization_slug' => ['required', 'alpha_dash:ascii', 'max:140'],
                'code' => ['nullable', 'string', 'max:100'], 'email' => ['nullable', 'email', 'max:254'],
                'phone' => ['nullable', 'string', 'max:50'], 'address_line_1' => ['nullable', 'string', 'max:180'],
                'address_line_2' => ['nullable', 'string', 'max:180'], 'city' => ['nullable', 'string', 'max:120'],
                'state' => ['nullable', 'string', 'max:120'], 'postal_code' => ['nullable', 'string', 'max:30'],
                'country_code' => ['nullable', 'alpha:ascii', 'size:2'],
                'latitude' => ['nullable', 'numeric', 'between:-90,90'], 'longitude' => ['nullable', 'numeric', 'between:-180,180'],
            ],
        };
        if ($this->type !== 'locations') {
            $data['slug'] = $data['slug'] !== null
                ? Str::lower($data['slug'])
                : Str::limit(Str::slug(Str::limit($data['name'] ?? '', 180, '')), 140, '');
            $rules['slug'] = ['required', 'alpha_dash:ascii', 'max:140'];
        }

        $errors = [];
        $validator = Validator::make($data, $rules);
        if ($validator->fails()) {
            foreach ($validator->failed() as $field => $failures) {
                $code = strtolower((string) array_key_first($failures));
                $message = match ($code) {
                    'required' => 'Este campo es obligatorio.',
                    'max' => 'El campo excede su longitud máxima.',
                    'between' => 'El valor está fuera del rango permitido.',
                    'in' => 'Usa 1, 0, true o false.',
                    default => 'El valor no tiene el formato requerido.',
                };
                $errors[$field] = [$code, $message];
            }
        }

        $scope = [];
        if ($this->type !== 'organizations') {
            $parentField = $this->type === 'catalog_items' ? 'catalog_slug' : 'organization_slug';
            if (! isset($errors[$parentField])) {
                $parent = $this->parent($data[$parentField]);
                if ($parent === null) {
                    $errors[$parentField] = ['parent_unavailable', 'La referencia no existe, está inactiva o no permite crear registros.'];
                } else {
                    $scope[$this->type === 'catalog_items' ? 'catalog_id' : 'organization_id'] = $parent;
                }
            }
            unset($data[$parentField]);
        }

        $model = self::TYPES[$this->type]['model'];
        if ($this->type === 'organizations' || $scope !== []) {
            foreach (['slug', 'code'] as $field) {
                if (! isset($data[$field]) || isset($errors[$field])) {
                    continue;
                }
                $key = json_encode([$scope, $field, Str::lower(Str::ascii($data[$field]))], JSON_THROW_ON_ERROR);
                if (isset($this->seen[$key])) {
                    $errors[$field] = ['duplicate_csv', 'El valor se repite en el archivo.'];
                } elseif ($model::query()->where($scope)->where($field, $data[$field])->exists()) {
                    $errors[$field] = ['duplicate_db', 'El valor ya existe en el ámbito indicado.'];
                }
                $this->seen[$key] = true;
            }
        }

        $data['is_active'] = in_array($data['is_active'] ?? '1', ['1', 'true'], true);
        if ($this->type === 'catalog_items') {
            $data['sort_order'] ??= 0;
        }
        if (isset($data['country_code'])) {
            $data['country_code'] = strtoupper($data['country_code']);
        }

        return [$data + $scope, $errors];
    }

    private function parent(string $slug): ?int
    {
        if (array_key_exists($slug, $this->parents)) {
            return $this->parents[$slug];
        }
        if ($this->type === 'locations') {
            $org = Organization::query()->where('slug', $slug)->when($this->lockParents, fn ($query) => $query->lockForUpdate())->first();

            return $this->parents[$slug] = $org?->is_active ? $org->id : null;
        }
        $catalog = Catalog::query()->where('slug', $slug)->when($this->lockParents, fn ($query) => $query->lockForUpdate())->first();
        if ($catalog?->organization_id !== null) {
            $catalog->setRelation('organization', Organization::query()
                ->when($this->lockParents, fn ($query) => $query->lockForUpdate())->find($catalog->organization_id));
        }

        return $this->parents[$slug] = $catalog?->isOperationallyActive()
            && Gate::forUser($this->user)->allows('create', [CatalogItem::class, $catalog]) ? $catalog->id : null;
    }
}
