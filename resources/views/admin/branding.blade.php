@extends('layouts.admin')

@section('title', 'Identidad visual')

@section('content')
    <div class="page-intro"><p class="muted">Personaliza cómo se identifica esta aplicación en sus interfaces.</p></div>
    <section class="panel form-panel admin-form-surface admin-form-surface-branding">
        <form method="POST" action="{{ route('admin.branding.update') }}" enctype="multipart/form-data" class="stack-form">
            @csrf @method('PUT')
            <div class="field"><label for="visible_name">Nombre visible</label><input id="visible_name" name="visible_name" value="{{ old('visible_name', $branding->visible_name) }}" maxlength="120"><p class="field-hint">Déjalo vacío para usar APP_NAME ({{ config('app.name') }}).</p><x-admin.field-error name="visible_name" /></div>
            <div class="field"><label for="logo">Logotipo</label>
                @if($branding->logo)
                    <div class="branding-preview"><img src="{{ route('branding.logo') }}?v={{ substr($branding->logo->checksum, 0, 16) }}" alt="Logotipo actual"></div>
                @else
                    <p class="muted">No hay un logotipo configurado.</p>
                @endif
                @php($logoRules = config('documents.category_rules')['branding.logo'])
                <x-admin.dropzone name="logo" label="Seleccionar logotipo" :accept="'.jpg,.jpeg,.png,.webp,image/jpeg,image/png,image/webp'" :mime-types="array_keys($logoRules['allowed_mime_types'])" :max-size-kb="$logoRules['max_size_kb']" types-label="JPEG, PNG o WebP" instructions="Arrastra el logotipo o selecciónalo." />
            </div>
            <button class="button button-primary" type="submit">Guardar cambios</button>
        </form>
        @if($branding->logo)
            <form method="POST" action="{{ route('admin.branding.logo.delete') }}" class="form-actions">@csrf @method('DELETE')
                <x-admin.confirm-dialog title="Eliminar logotipo" variant="danger"
                    message="¿Deseas retirar el logotipo actual?"
                    consequence="El panel dejará de mostrar este logotipo. El archivo se conservará durante el periodo de retención." />
                <button class="button button-danger" type="submit">Eliminar logotipo</button></form>
        @endif
    </section>
@endsection
