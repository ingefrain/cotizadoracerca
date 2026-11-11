@extends('layouts.admin')
@section('title', 'Información del documento')
@section('content')
<div class="admin-form-surface admin-form-surface-wide">
    <div class="page-intro"><h2>{{ $document->title }}</h2><div class="page-actions">
        <a class="button button-secondary" href="{{ route('admin.pdf.index') }}">Volver a documentos</a>
        @if(app(\App\Support\FormattedDocumentAccess::class)->allows(auth()->user(), 'update'))
            <a class="button button-primary" href="{{ route('admin.pdf.edit', $document) }}">Abrir/editar</a>
        @endif
    </div></div>
    <section class="panel stack-form">
        <dl class="detail-grid">
            <div><dt>Autor</dt><dd>{{ $document->author->name }}</dd></div>
            <div><dt>Creación</dt><dd>{{ $document->created_at->format('d/m/Y H:i') }}</dd></div>
            <div><dt>Última modificación</dt><dd>{{ $document->updated_at->format('d/m/Y H:i') }}</dd></div>
            <div><dt>Modificado por</dt><dd>{{ $document->updater?->name ?? 'Sin modificaciones registradas' }}</dd></div>
            <div><dt>Identificador</dt><dd>{{ $document->uuid }}</dd></div>
        </dl>
        <div class="field"><label for="document-notes">Notas internas</label><p class="field-hint">Estas notas no se incluyen en el PDF.</p><textarea id="document-notes" rows="3" readonly>{{ "\n".$document->notes }}</textarea></div>
        <div class="field"><label for="document-source">Markdown guardado</label><textarea id="document-source" rows="15" readonly>{{ "\n".$document->body_markdown }}</textarea></div>
    </section>
</div>
@endsection
