@extends('layouts.admin')
@section('title', 'Documentos PDF')
@push('admin-styles')
    <link rel="stylesheet" href="{{ asset('assets/base-admin/pdf-editor.css') }}?v={{ filemtime(public_path('assets/base-admin/pdf-editor.css')) }}">
@endpush
@section('content')
@php($access = app(\App\Support\FormattedDocumentAccess::class))
<div class="admin-content-wide">
    <div class="page-intro">
        <p class="muted">Tus documentos guardados son borradores editables. Ábrelos para continuar escribiendo o generar una vista previa PDF.</p>
        @if($access->allows(auth()->user(), 'create'))
            <div class="page-actions"><a class="button button-primary" href="{{ route('admin.pdf.create') }}">Nuevo documento</a></div>
        @endif
    </div>
    <section class="panel">
        @if($documents->isEmpty())
            <x-admin.empty-state title="Aún no hay documentos guardados" description="Crea un documento y guarda su primer borrador para encontrarlo aquí." />
        @else
            <div class="table-wrap"><table>
                <thead><tr><th scope="col">Título</th><th scope="col">Autor</th><th scope="col">Última modificación</th><th scope="col">Acciones</th></tr></thead>
                <tbody>@foreach($documents as $document)
                    <tr>
                        <td><strong class="document-list-title">{{ $document->title }}</strong><span class="badge badge-neutral">Borrador</span></td>
                        <td>{{ $document->author->name }}</td>
                        <td><time datetime="{{ $document->updated_at->toIso8601String() }}">{{ $document->updated_at->format('d/m/Y H:i') }}</time></td>
                        <td class="row-actions">
                            @if($access->allows(auth()->user(), 'update'))<a class="button button-primary button-small" aria-label="Abrir/editar: {{ $document->title }}" href="{{ route('admin.pdf.edit', $document) }}">Abrir/editar</a>@endif
                            <a class="button button-secondary button-small" aria-label="Información: {{ $document->title }}" href="{{ route('admin.pdf.show', $document) }}">Información</a>
                        </td>
                    </tr>
                @endforeach</tbody>
            </table></div>
            <div class="pagination-wrap">{{ $documents->links() }}</div>
        @endif
    </section>
</div>
@endsection
