@extends('layouts.admin')

@php($editable = !$catalog->exists || auth()->user()->can('update', $catalog))
@section('title', !$catalog->exists ? 'Crear catálogo' : ($editable ? 'Editar catálogo' : 'Ver catálogo'))

@section('content')
<div class="admin-form-surface admin-form-surface-wide">
    <div class="page-intro">
        <div>
            <p class="eyebrow">Catálogos</p>
            @if($catalog->exists)
                <p>{{ $catalog->name }} · {{ $catalog->organization?->name ?? 'Global' }} @cannot('disable', $catalog) · {{ $catalog->isOperationallyActive() ? 'Activo' : ($catalog->is_active ? 'Inactivo por organización' : 'Inactivo') }} @endcannot</p>
            @endif
        </div>
        <div class="page-actions">
            <a class="button button-secondary" href="{{ route('admin.catalogs.index') }}">Volver</a>
            @if($catalog->exists)
                @can('viewAny', [\App\Models\CatalogItem::class, $catalog])
                    <a class="button button-secondary" href="{{ route('admin.catalogs.items.index', $catalog) }}">Ver elementos</a>
                @endcan
                @can('create', [\App\Models\CatalogItem::class, $catalog])
                    <a class="button button-primary" href="{{ route('admin.catalogs.items.create', $catalog) }}">Agregar elemento</a>
                @endcan
            @endif
        </div>
    </div>
    <section class="panel form-panel">
        @if($catalog->exists)
            @can('disable', $catalog)
                <div class="admin-status-section">
                    <h2>Estado</h2>
                    <x-admin.status-toggle :active="$catalog->is_active" :action="route('admin.catalogs.status', $catalog)" :label="'Estado de '.$catalog->name" :blocked-note="$catalog->organization_id !== null && !$catalog->organization?->is_active ? 'Inactivo por organización' : null" />
                    <p class="field-hint">El estado se guarda de inmediato. Usa Guardar cambios para los demás campos.</p>
                </div>
            @endcan
        @endif
        @if($editable)
            <form method="POST" action="{{ $catalog->exists ? route('admin.catalogs.update', $catalog) : route('admin.catalogs.store') }}" class="stack-form">
                @csrf
                @if($catalog->exists) @method('PUT') @endif
                <div class="field-grid">
                    @include('admin.catalogs.fields', ['record' => $catalog])
                    @isset($organizations)
                        <div class="field">
                            <label for="organization">Organización (opcional)</label>
                            <select id="organization" name="organization">
                                <option value="">Global</option>
                                @foreach($organizations as $organization)
                                    <option value="{{ $organization->uuid }}" @selected(old('organization', $catalog->organization?->uuid) === $organization->uuid)>{{ $organization->name }}{{ $organization->is_active ? '' : ' · Inactiva' }}</option>
                                @endforeach
                            </select>
                            <x-admin.field-error name="organization" />
                        </div>
                    @endisset
                </div>
                <x-admin.markdown-editor name="description_markdown" label="Descripción" :value="$catalog->description_markdown" :max-length="16000" />
                <div class="form-actions"><button class="button button-primary" type="submit">{{ $catalog->exists ? 'Guardar cambios' : 'Crear catálogo' }}</button></div>
            </form>
        @endif
        @if($catalog->exists && filled($catalog->description_markdown))
            <h2>Descripción guardada</h2>
            <x-markdown-content :value="$catalog->description_markdown" />
        @endif
    </section>
</div>
@endsection
