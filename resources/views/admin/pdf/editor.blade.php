@extends('layouts.admin')
@section('title', 'Documentos PDF')

@push('admin-styles')
    <link rel="stylesheet" href="{{ asset('assets/base-admin/pdf-editor.css') }}?v={{ filemtime(public_path('assets/base-admin/pdf-editor.css')) }}">
@endpush

@section('content')
<div class="admin-content-wide pdf-editor" data-pdf-editor data-preview-url="{{ route('admin.pdf.preview') }}" data-max-bytes="{{ config('pdf.preview_max_bytes') }}">
    <p class="muted">Escribe o pega tu documento y actualiza la vista previa para revisar sus páginas.</p>
    <p class="field-hint">Borrador temporal: el contenido no se guarda al salir. Descarga el PDF y conserva tu Markdown por separado.</p>
    <noscript><x-admin.alert variant="warning">Activa JavaScript para generar y visualizar el PDF.</x-admin.alert></noscript>

    <div class="pdf-mobile-switch" role="group" aria-label="Área de trabajo">
        <button class="button button-secondary" type="button" data-pdf-panel="editor" aria-controls="pdf-source-panel" aria-pressed="true">Editar Markdown</button>
        <button class="button button-secondary" type="button" data-pdf-panel="preview" aria-controls="pdf-preview-panel" aria-pressed="false">Ver PDF</button>
    </div>
    <x-admin.alert variant="error" data-pdf-error hidden><span data-pdf-error-message></span></x-admin.alert>
    <x-admin.alert variant="warning" data-pdf-stale hidden>
        <p>El PDF corresponde a una versión anterior del contenido. Actualiza la vista previa para incluir tus cambios.</p>
        <button class="button button-secondary button-small" type="button" data-pdf-refresh>Actualizar ahora</button>
    </x-admin.alert>

    <div class="pdf-workbench">
        <form id="pdf-source-panel" class="pdf-source-panel" data-pdf-form>
            @csrf
            <x-admin.markdown-editor name="markdown" label="Contenido Markdown" :preview="false" :rows="24"
                help="Hasta 100 KiB de texto. Para comenzar otra página, escribe :::page-break en una línea independiente." />
            <div class="pdf-editor-actions">
                <span class="field-hint" data-pdf-size>0 / 100 KiB</span>
                <button class="button button-primary" type="submit" data-pdf-generate>Actualizar vista previa</button>
            </div>
        </form>
        <section id="pdf-preview-panel" class="pdf-preview-panel" aria-labelledby="pdf-preview-heading">
            <div class="pdf-preview-heading">
                <div><h2 id="pdf-preview-heading">Vista previa PDF</h2><p class="field-hint" data-pdf-status role="status" aria-live="polite">Aún no has generado una vista previa.</p></div>
                <button class="button button-secondary button-small" type="button" data-pdf-download disabled>Descargar PDF</button>
            </div>
            <div class="pdf-preview-surface" data-pdf-surface aria-busy="false">
                <div class="pdf-preview-empty" data-pdf-empty>
                    <strong>Tu documento aparecerá aquí</strong>
                    <p>Pega tu Markdown y pulsa «Actualizar vista previa».</p>
                </div>
                <iframe title="Vista previa del documento PDF" data-pdf-frame hidden></iframe>
            </div>
            <p class="field-hint">Usa los controles del visor para recorrer páginas, ampliar o imprimir. Si tu navegador no muestra el PDF, puedes descargarlo.</p>
        </section>
    </div>
</div>
@endsection

@push('admin-scripts')
    <script src="{{ asset('assets/base-admin/pdf-editor.js') }}?v={{ filemtime(public_path('assets/base-admin/pdf-editor.js')) }}" defer></script>
@endpush
