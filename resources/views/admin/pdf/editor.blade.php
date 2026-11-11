@extends('layouts.admin')
@section('title', 'Editor de documento')

@push('admin-styles')
    <link rel="stylesheet" href="{{ asset('assets/base-admin/pdf-editor.css') }}?v={{ filemtime(public_path('assets/base-admin/pdf-editor.css')) }}">
@endpush

@section('content')
@php($canPreview = app(\App\Support\PdfPreviewAccess::class)->allows(auth()->user()))
@php($initialDraft = ['title' => $document->title ?? '', 'body_markdown' => $document->body_markdown ?? '', 'notes' => $document->notes ?? ''])
<div class="admin-content-wide pdf-editor" data-pdf-editor data-draft-editor
    data-preview-url="{{ route('admin.pdf.preview') }}" data-preview-allowed="{{ $canPreview ? 'true' : 'false' }}"
    data-max-bytes="{{ config('pdf.preview_max_bytes') }}"
    data-save-url="{{ $document->exists ? route('admin.pdf.update', $document) : route('admin.pdf.store') }}"
    data-saved="{{ $document->exists ? 'true' : 'false' }}">
    <script type="application/json" data-draft-initial>@json($initialDraft)</script>
    <div class="page-actions"><a class="button button-secondary button-small" href="{{ route('admin.pdf.index') }}">Volver a documentos</a></div>
    <p class="muted">Escribe o pega tu documento y actualiza la vista previa para revisar sus páginas.</p>
    <p class="field-hint">Guardar conserva el título, Markdown y notas. Actualizar vista previa genera el PDF del contenido actual, aunque todavía no lo hayas guardado.</p>
    <section class="panel draft-metadata" aria-label="Datos del borrador">
        <div class="field">
            <label for="draft-title">Título</label>
            <input id="draft-title" name="title" value="{{ $document->title }}" maxlength="200" required aria-describedby="draft-title-help draft-title-error">
            <p id="draft-title-help" class="field-hint">Identifica el documento en el listado. Hasta 200 caracteres.</p>
            <p id="draft-title-error" class="field-hint" data-draft-field-error="title" hidden></p>
        </div>
        <div class="draft-save-actions">
            <span class="field-hint" data-draft-status role="status" aria-live="polite">{{ $document->exists ? 'Sin cambios pendientes.' : 'Documento nuevo, aún sin guardar.' }}</span>
            <button class="button button-primary" type="button" data-draft-save>Guardar borrador</button>
        </div>
    </section>
    <x-admin.alert variant="error" data-draft-error hidden><span data-draft-error-message></span></x-admin.alert>
    @unless($canPreview)<x-admin.alert variant="info">Tu cuenta puede trabajar con borradores, pero no tiene permiso para generar vistas previas PDF.</x-admin.alert>@endunless
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
            <x-admin.markdown-editor name="markdown" label="Contenido Markdown" :value="$document->body_markdown ?? ''" :preview="false" :rows="24"
                help="Hasta 100 KiB de texto. Para comenzar otra página, escribe :::page-break en una línea independiente." />
            <p id="draft-markdown-error" class="field-hint" data-draft-field-error="body_markdown" hidden></p>
            <div class="field">
                <label for="draft-notes">Notas internas (opcional)</label>
                <textarea id="draft-notes" name="notes" rows="3" maxlength="5000" aria-describedby="draft-notes-help draft-notes-error">{{ "\n".$document->notes }}</textarea>
                <p id="draft-notes-help" class="field-hint">Solo para consulta interna. No se incluyen en el PDF. Hasta 5000 caracteres.</p>
                <p id="draft-notes-error" class="field-hint" data-draft-field-error="notes" hidden></p>
            </div>
            <div class="pdf-editor-actions">
                <span class="field-hint" data-pdf-size>0 / 100 KiB</span>
                <button class="button button-secondary" type="submit" data-pdf-generate @disabled(! $canPreview)>Actualizar vista previa</button>
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
    <script src="{{ asset('assets/base-admin/draft-editor.js') }}?v={{ filemtime(public_path('assets/base-admin/draft-editor.js')) }}" defer></script>
    <script src="{{ asset('assets/base-admin/pdf-editor.js') }}?v={{ filemtime(public_path('assets/base-admin/pdf-editor.js')) }}" defer></script>
@endpush
