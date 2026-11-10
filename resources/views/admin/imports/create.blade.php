@extends('layouts.admin')

@section('title', 'Importar · '.$definition['title'])

@section('content')
    <div class="page-intro">
        <div>
            <p class="muted">Usa un CSV UTF-8 separado por comas o punto y coma. Conserva todos los encabezados de la plantilla.</p>
            <p class="muted">Para <code>is_active</code> usa <strong>1</strong> para Activo y <strong>0</strong> para Inactivo.</p>
            @if($type === 'locations')
                <p class="muted">Usa dos letras para <code>country_code</code> (por ejemplo, MX) y punto decimal para las coordenadas.</p>
            @endif
        </div>
        <a href="{{ route('admin.imports.template', $type) }}">Descargar plantilla CSV</a>
    </div>
    <form class="card admin-form-surface" method="post" enctype="multipart/form-data" action="{{ route('admin.imports.store', $type) }}">
        @csrf
        <x-admin.dropzone name="csv" label="Seleccionar CSV" instructions="Arrastra un archivo CSV aquí o haz clic para seleccionarlo." accept=".csv,text/csv" types-label="CSV" :max-size-kb="config('imports.max_size_kb')" required />
        @error('csv')<p class="field-error" id="csv-error">{{ $message }}</p>@enderror
        <p class="muted">Máximo {{ config('imports.max_rows') }} filas. La validación solo crea una corrida de importación.</p>
        <div class="form-actions">
            <a class="button button-secondary" href="{{ route('admin.imports.index') }}">Volver</a>
            <button class="button" type="submit">Validar CSV</button>
        </div>
    </form>
@endsection
