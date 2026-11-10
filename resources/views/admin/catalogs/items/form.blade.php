@extends('layouts.admin')

@php($editable = !$item->exists || auth()->user()->can('update', $item))
@section('title', !$item->exists ? 'Agregar elemento' : ($editable ? 'Editar elemento' : 'Ver elemento'))

@section('content')
<div class="admin-form-surface admin-form-surface-wide">
    <div class="page-intro">
        <div>
            <p class="eyebrow">{{ $catalog->name }}</p>
            @if($item->exists)
                <p>{{ $item->name }} @cannot('disable', $item) · {{ $item->isOperationallyActive() ? 'Activo' : ($item->is_active ? 'Inactivo por catálogo' : 'Inactivo') }} @endcannot</p>
            @endif
        </div>
        <a class="button button-secondary" href="{{ route('admin.catalogs.items.index', $catalog) }}">Volver a elementos</a>
    </div>
    <section class="panel form-panel">
        @if($item->exists)
            @can('disable', $item)
                <div class="admin-status-section">
                    <h2>Estado</h2>
                    <x-admin.status-toggle :active="$item->is_active" :action="route('admin.catalogs.items.status', [$catalog, $item])" :label="'Estado de '.$item->name" :blocked-note="!$catalog->isOperationallyActive() ? 'Inactivo por catálogo' : null" />
                    <p class="field-hint">El estado se guarda de inmediato. Usa Guardar cambios para los demás campos.</p>
                </div>
            @endcan
        @endif
        @if($item->exists && $item->image)
            <div class="field">
                <span class="field-label">Imagen principal</span>
                <img class="catalog-image-preview" src="{{ route('admin.catalogs.items.image', [$catalog, $item]) }}" alt="Imagen de {{ $item->name }}">
                @if($editable)
                    <form method="POST" action="{{ route('admin.catalogs.items.image.delete', [$catalog, $item]) }}">
                        @csrf
                        @method('DELETE')
                        <x-admin.confirm-dialog title="Retirar imagen" variant="danger" message="La imagen dejará de mostrarse en el elemento." consequence="El archivo se conservará durante el periodo de retención." />
                        <button class="button button-danger button-small" type="submit">Retirar imagen</button>
                    </form>
                @endif
            </div>
        @endif
        @if($editable)
            <form method="POST" action="{{ $item->exists ? route('admin.catalogs.items.update', [$catalog, $item]) : route('admin.catalogs.items.store', $catalog) }}" enctype="multipart/form-data" class="stack-form">
                @csrf
                @if($item->exists) @method('PUT') @endif
                <div class="field-grid">
                    @include('admin.catalogs.fields', ['record' => $item])
                    <div class="field">
                        <label for="code">Código (opcional)</label>
                        <input id="code" name="code" value="{{ old('code', $item->code) }}" maxlength="100">
                        <x-admin.field-error name="code" />
                    </div>
                </div>
                <x-admin.markdown-editor name="description_markdown" label="Descripción" :value="$item->description_markdown" :max-length="16000" />
                <x-admin.dropzone name="image" :label="$item->image ? 'Reemplazar imagen principal' : 'Imagen principal (opcional)'"
                    accept=".jpg,.jpeg,.png,.webp,image/jpeg,image/png,image/webp"
                    :mime-types="['image/jpeg', 'image/png', 'image/webp']"
                    :max-size-kb="config('documents.category_rules')['catalog_item.image']['max_size_kb']"
                    types-label="JPEG, PNG o WebP" instructions="Arrastra una imagen o selecciónala." />
                <details>
                    <summary>Metadata secundaria (opcional)</summary>
                    <div class="field">
                        <label for="metadata">Objeto JSON</label>
                        <textarea id="metadata" name="metadata" rows="4" maxlength="2048">{{ old('metadata', $item->metadata === null ? '' : json_encode($item->metadata, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT)) }}</textarea>
                        <p class="field-hint">Hasta 20 claves con valores simples. Usa campos del proyecto para datos que tengan reglas de negocio.</p>
                        <x-admin.field-error name="metadata" />
                    </div>
                </details>
                <div class="form-actions"><button class="button button-primary" type="submit">{{ $item->exists ? 'Guardar cambios' : 'Crear elemento' }}</button></div>
            </form>
        @endif
        @if($item->exists && filled($item->description_markdown))
            <h2>Descripción guardada</h2>
            <x-markdown-content :value="$item->description_markdown" />
        @endif
    </section>
</div>
@endsection
