@extends('layouts.admin')
@section('title', 'Información del documento')
@push('admin-styles')
    <link rel="stylesheet" href="{{ asset('assets/base-admin/pdf-editor.css') }}?v={{ filemtime(public_path('assets/base-admin/pdf-editor.css')) }}">
@endpush
@section('content')
<div class="admin-form-surface admin-form-surface-wide">
    <div class="page-actions">
        <a class="button button-secondary" href="{{ route('admin.pdf.index') }}">Volver a documentos</a>
    </div>
    <section class="document-context" aria-label="Resumen del documento">
        <div class="document-context-heading"><h2>{{ $document->title }}</h2><span class="badge badge-neutral">Borrador</span></div>
        <p>Este documento es un borrador editable. La vista previa PDF se genera desde el editor y es temporal.</p>
    </section>
    <div class="page-actions document-detail-actions">
        @if(app(\App\Support\FormattedDocumentAccess::class)->allows(auth()->user(), 'update'))
            <a class="button button-primary" href="{{ route('admin.pdf.edit', $document) }}">Abrir/editar</a>
        @endif
    </div>
    <section class="panel stack-form">
        <h2 class="document-section-heading">Información general</h2>
        <dl class="detail-grid">
            <div><dt>Autor</dt><dd>{{ $document->author->name }}</dd></div>
            <div><dt>Creación</dt><dd>{{ $document->created_at->format('d/m/Y H:i') }}</dd></div>
            <div><dt>Última modificación</dt><dd>{{ $document->updated_at->format('d/m/Y H:i') }}</dd></div>
            <div><dt>Modificado por</dt><dd>{{ $document->updater?->name ?? 'Sin modificaciones registradas' }}</dd></div>
            <div><dt>Identificador</dt><dd>{{ $document->uuid }}</dd></div>
        </dl>
    </section>
    <section class="panel document-detail-notes">
        <h2 class="document-section-heading">Notas internas</h2>
        <p class="field-hint">Solo para uso interno. No se incluyen en el PDF.</p>
        <p class="document-readonly-text">{{ filled($document->notes) ? $document->notes : 'Sin notas internas.' }}</p>
    </section>
    <details class="document-notes document-source">
        <summary>Consultar Markdown guardado <small>Contenido de consulta, sin controles de edición.</small></summary>
        <pre class="document-source-text" tabindex="0" aria-label="Markdown guardado, solo consulta">{{ $document->body_markdown }}</pre>
    </details>
    <p class="field-hint document-future">Las versiones definitivas y su historial estarán disponibles en una fase posterior.</p>
</div>
@endsection
