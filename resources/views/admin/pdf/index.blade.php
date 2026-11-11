@extends('layouts.admin')
@section('title', 'Documentos PDF')
@section('content')
@php($access = app(\App\Support\FormattedDocumentAccess::class))
<div class="admin-content-wide">
    <div class="page-intro">
        <p class="muted">Conserva tus documentos Markdown y vuelve a abrirlos para editarlos o generar su PDF.</p>
        @if($access->allows(auth()->user(), 'create'))
            <div class="page-actions"><a class="button button-primary" href="{{ route('admin.pdf.create') }}">Nuevo documento</a></div>
        @endif
    </div>
    <section class="panel">
        @if($documents->isEmpty())
            <x-admin.empty-state title="Aún no hay documentos guardados" description="Crea un documento y guarda su primer borrador para encontrarlo aquí." />
        @else
            <div class="table-wrap"><table>
                <thead><tr><th>Título</th><th>Autor</th><th>Creación</th><th>Última modificación</th><th>Acciones</th></tr></thead>
                <tbody>@foreach($documents as $document)
                    <tr>
                        <td><strong>{{ $document->title }}</strong></td>
                        <td>{{ $document->author->name }}</td>
                        <td><time datetime="{{ $document->created_at->toIso8601String() }}">{{ $document->created_at->format('d/m/Y H:i') }}</time></td>
                        <td><time datetime="{{ $document->updated_at->toIso8601String() }}">{{ $document->updated_at->format('d/m/Y H:i') }}</time></td>
                        <td class="row-actions">
                            @if($access->allows(auth()->user(), 'update'))<a class="button button-secondary button-small" href="{{ route('admin.pdf.edit', $document) }}">Abrir/editar</a>@endif
                            <a class="button button-secondary button-small" href="{{ route('admin.pdf.show', $document) }}">Información</a>
                        </td>
                    </tr>
                @endforeach</tbody>
            </table></div>
            <div class="pagination-wrap">{{ $documents->links() }}</div>
        @endif
    </section>
</div>
@endsection
