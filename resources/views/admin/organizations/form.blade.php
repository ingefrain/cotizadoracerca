@extends('layouts.admin')

@section('title', $creating ? 'Crear organización' : 'Editar organización')

@section('content')
    <div class="page-intro"><div><p class="eyebrow">Organizaciones</p><p class="muted">Los datos de contacto son opcionales. Las organizaciones inactivas conservan sus datos, documentos y sedes.</p></div><a class="button button-secondary" href="{{ route('admin.organizations.index') }}">Volver</a></div>
    @if(!$creating)<div class="page-actions">@can('locations.view')<a class="button button-secondary" href="{{ route('admin.locations.index', ['organization' => $organization->uuid]) }}">Ver sedes ({{ $organization->locations()->count() }})</a>@endcan</div>@endif
    <section class="panel form-panel admin-form-surface admin-form-surface-wide">
        @if(!$creating && $organization->logo)
            <div class="field"><span class="field-label">Logotipo actual</span><img src="{{ route('admin.organizations.logo', $organization) }}" alt="Logotipo de {{ $organization->name }}" style="max-width:180px;max-height:120px;object-fit:contain"><form method="POST" action="{{ route('admin.organizations.logo.delete', $organization) }}">@csrf @method('DELETE')<button class="button button-danger button-small" type="submit">Eliminar logotipo</button></form></div>
        @endif
        <form method="POST" action="{{ $creating ? route('admin.organizations.store') : route('admin.organizations.update', $organization) }}" enctype="multipart/form-data" class="stack-form">
            @csrf @unless($creating) @method('PUT') @endunless
            <div class="field-grid">
                <div class="field"><label for="name">Nombre</label><input id="name" name="name" value="{{ old('name', $organization->name) }}" maxlength="180" required><x-admin.field-error name="name" /></div>
                <div class="field"><label for="legal_name">Nombre legal (opcional)</label><input id="legal_name" name="legal_name" value="{{ old('legal_name', $organization->legal_name) }}" maxlength="180"><x-admin.field-error name="legal_name" /></div>
                <div class="field"><label for="slug">Slug</label><input id="slug" name="slug" value="{{ old('slug', $organization->slug) }}" maxlength="140" pattern="[A-Za-z0-9_-]+" required><p class="field-hint">Identificador legible único; la ruta pública usa UUID.</p><x-admin.field-error name="slug" /></div>
                <div class="field"><label for="email">Correo (opcional)</label><input id="email" type="email" name="email" value="{{ old('email', $organization->email) }}" maxlength="254"><x-admin.field-error name="email" /></div>
                <div class="field"><label for="phone">Teléfono (opcional)</label><input id="phone" name="phone" value="{{ old('phone', $organization->phone) }}" maxlength="50"><x-admin.field-error name="phone" /></div>
                <div class="field"><label for="website">Sitio web (opcional)</label><input id="website" type="url" name="website" value="{{ old('website', $organization->website) }}" maxlength="255"><x-admin.field-error name="website" /></div>
                <div class="field"><label for="tax_id">Identificador fiscal opcional</label><input id="tax_id" name="tax_id" value="{{ old('tax_id', $organization->tax_id) }}" maxlength="120"><x-admin.field-error name="tax_id" /></div>
            </div>
            <div class="field"><label for="notes">Notas internas (opcional)</label><textarea id="notes" name="notes" rows="3" maxlength="5000">{{ old('notes', $organization->notes) }}</textarea><x-admin.field-error name="notes" /></div>
            <x-admin.dropzone name="logo" label="Logotipo (opcional)" :accept="'.jpg,.jpeg,.png,.webp,image/jpeg,image/png,image/webp'" :mime-types="['image/jpeg', 'image/png', 'image/webp']" :max-size-kb="5120" types-label="JPEG, PNG o WebP" instructions="Arrastra una imagen aquí o selecciónala." />
            <p class="field-hint">Las imágenes admitidas se optimizan como WebP en el almacenamiento privado.</p>
            <div class="form-actions"><button class="button button-primary" type="submit">{{ $creating ? 'Crear organización' : 'Guardar cambios' }}</button><a class="button button-secondary" href="{{ route('admin.organizations.index') }}">Cancelar</a></div>
        </form>
    </section>
@endsection
