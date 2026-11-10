@extends('layouts.admin')

@section('title', 'Documentos')

@section('content')
    <div class="page-intro admin-content-wide"><p class="muted">Los archivos se almacenan de forma privada. Aquí aparecen los documentos de tu cuenta y de las entidades que puedes consultar.</p></div>

    @can('documents.upload')
        <section class="panel admin-content-wide" aria-labelledby="document-upload-heading">
            <h2 id="document-upload-heading">Subir documento</h2>
            <form method="POST" action="{{ route('admin.documents.store') }}" enctype="multipart/form-data" class="stack-form">
                @csrf
                <x-admin.dropzone name="file" label="Seleccionar archivo" :accept="'.pdf,.jpg,.jpeg,.png,.txt,.csv,application/pdf,image/jpeg,image/png,text/plain,text/csv'" :mime-types="array_keys(config('documents.allowed_mime_types'))" :max-size-kb="config('documents.max_size_kb')" types-label="PDF, JPEG, PNG, TXT o CSV" instructions="Arrastra un archivo aquí o selecciónalo." required />
                <label for="document-category">Categoría<input id="document-category" name="category" value="{{ old('category') }}" required maxlength="100" autocomplete="off" placeholder="Ejemplo: contract"></label>
                <label for="document-title">Título opcional<input id="document-title" name="title" value="{{ old('title') }}" maxlength="200"></label>
                <label for="document-description">Descripción opcional<textarea id="document-description" name="description" maxlength="4000" rows="2">{{ old('description') }}</textarea></label>
                <div class="form-actions"><button class="button button-primary" type="submit">Guardar documento</button></div>
                <p class="muted">Tipos admitidos: PDF, JPEG, PNG, texto y CSV. El límite configurado es {{ number_format(config('documents.max_size_kb') / 1024, 0) }} MB.</p>
            </form>
        </section>
    @endcan

    <section class="panel admin-content-wide">
        <form method="GET" action="{{ route('admin.documents.index') }}" class="filter-bar" aria-label="Filtrar documentos">
            <label class="filter-search" for="q">Buscar<input id="q" name="q" type="search" value="{{ $filters['q'] ?? '' }}" maxlength="180" placeholder="Nombre, título o categoría"></label>
            <label for="category">Categoría<select id="category" name="category"><option value="">Todas</option>@foreach($categories as $category)<option value="{{ $category['key'] }}" @selected(($filters['category'] ?? '') === $category['key'])>{{ $category['label'] }}</option>@endforeach</select></label>
            <label for="type">Tipo<select id="type" name="type"><option value="">Todos</option><option value="image" @selected(($filters['type'] ?? '') === 'image')>Imagen</option><option value="pdf" @selected(($filters['type'] ?? '') === 'pdf')>PDF</option><option value="text" @selected(($filters['type'] ?? '') === 'text')>Texto</option><option value="other" @selected(($filters['type'] ?? '') === 'other')>Otro</option></select></label>
            <label for="date-from">Desde<input id="date-from" name="date_from" type="date" value="{{ $filters['date_from'] ?? '' }}"></label>
            <label for="date-to">Hasta<input id="date-to" name="date_to" type="date" value="{{ $filters['date_to'] ?? '' }}"></label>
            <div class="filter-actions"><button class="button button-primary button-small" type="submit">Aplicar</button><a class="button button-secondary button-small" href="{{ route('admin.documents.index') }}">Limpiar</a></div>
        </form>

        @if($documents->isEmpty())
            <x-admin.empty-state title="Sin documentos para mostrar" description="Los documentos permitidos aparecerán aquí después de subirlos." />
        @else
            <div class="table-wrap"><table>
                <thead><tr><th>Documento</th><th>Categoría</th><th>Tipo y tamaño</th><th>Entidad</th><th>Subido por</th><th>Fecha</th><th><span class="sr-only">Acciones</span></th></tr></thead>
                <tbody>
                @foreach($documents as $document)
                    <tr>
                        <td><strong>{{ $document->title ?: $document->safeOriginalName() }}</strong><span class="user-email">{{ $document->safeOriginalName() }}</span></td>
                        <td>{{ $document->category_label }}</td>
                        <td><span>{{ $document->mime_type }}</span><span class="user-email">{{ $document->humanSize() }}</span></td>
                        <td>{{ $document->entity_label }}</td>
                        <td>{{ $document->uploader?->name ?? 'Cuenta eliminada' }}</td>
                        <td><time datetime="{{ $document->created_at->toIso8601String() }}">{{ $document->created_at->format('d/m/Y H:i') }}</time></td>
                        <td class="row-actions">
                            @can('download', $document)<a class="button button-secondary button-small" href="{{ route('admin.documents.download', $document) }}">Descargar</a>@endcan
                            @can('update', $document)
                                <details><summary class="button button-secondary button-small">Editar</summary>
                                    <form method="POST" action="{{ route('admin.documents.update', $document) }}" enctype="multipart/form-data" class="stack-form">
                                        @csrf @method('PUT')
                                        <label for="category-{{ $document->uuid }}">Categoría<input id="category-{{ $document->uuid }}" name="category" value="{{ $document->category }}" required maxlength="100"></label>
                                        <label for="title-{{ $document->uuid }}">Título<input id="title-{{ $document->uuid }}" name="title" value="{{ $document->title }}" maxlength="200"></label>
                                        <label for="description-{{ $document->uuid }}">Descripción<textarea id="description-{{ $document->uuid }}" name="description" maxlength="4000" rows="2">{{ $document->description }}</textarea></label>
                                        <x-admin.dropzone name="file" label="Seleccionar reemplazo" :accept="'.pdf,.jpg,.jpeg,.png,.txt,.csv,application/pdf,image/jpeg,image/png,text/plain,text/csv'" :mime-types="array_keys(config('documents.allowed_mime_types'))" :max-size-kb="config('documents.max_size_kb')" types-label="PDF, JPEG, PNG, TXT o CSV" instructions="Arrastra un reemplazo o selecciónalo." :id="'replacement-'.$document->uuid" :error-id="'replacement-'.$document->uuid.'-error'" />
                                        <button class="button button-primary button-small" type="submit">Guardar cambios</button>
                                    </form>
                                </details>
                            @endcan
                            @can('delete', $document)
                                <form method="POST" action="{{ route('admin.documents.destroy', $document) }}">
                                    @csrf @method('DELETE')
                                    <x-admin.confirm-dialog title="Eliminar documento" variant="danger"
                                        :message="'¿Deseas marcar para eliminación «'.($document->title ?: $document->safeOriginalName()).'»?'"
                                        consequence="El documento dejará de estar disponible. El archivo se conservará durante el periodo de retención antes de su purga." />
                                    <button class="button button-danger button-small" type="submit">Eliminar</button>
                                </form>
                            @endcan
                        </td>
                    </tr>
                @endforeach
                </tbody>
            </table></div>
            <div class="pagination-wrap">{{ $documents->links() }}</div>
        @endif
    </section>
@endsection
