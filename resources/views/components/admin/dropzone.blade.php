@props([
    'name',
    'label' => 'Archivo',
    'accept' => '',
    'mimeTypes' => [],
    'maxSizeKb' => 5120,
    'required' => false,
    'instructions' => 'Arrastra un archivo aquí o haz clic para seleccionarlo.',
    'typesLabel' => null,
    'id' => null,
    'errorId' => null,
])
@php
    $controlId = $id ?: 'upload-'.str_replace(['[', ']'], '-', $name);
    $maximumBytes = max(1, (int) $maxSizeKb) * 1024;
    $maximumMb = rtrim(rtrim(number_format($maximumBytes / 1048576, 2, '.', ''), '0'), '.');
    $formatLabels = ['image/jpeg' => 'JPEG', 'image/png' => 'PNG', 'image/webp' => 'WebP', 'application/pdf' => 'PDF', 'text/plain' => 'TXT', 'text/csv' => 'CSV'];
    $formats = collect($mimeTypes)->map(fn ($mime) => $formatLabels[$mime] ?? $mime)->implode(', ');
@endphp
<div {{ $attributes->class(['file-dropzone-field']) }} data-file-dropzone data-allowed-types="{{ implode(',', $mimeTypes) }}" data-max-bytes="{{ $maximumBytes }}" data-size-label="{{ $maximumMb }} MB">
    <label class="file-dropzone" data-dropzone for="{{ $controlId }}">
        <span class="file-dropzone-icon" aria-hidden="true">↑</span>
        <strong>{{ $instructions }}</strong>
        <small>{{ $typesLabel ?: ($formats ?: 'Cualquier tipo permitido') }} · máximo {{ $maximumMb }} MB</small>
        <span class="button button-secondary button-small" aria-hidden="true">{{ $label }}</span>
        <input class="file-dropzone-input" id="{{ $controlId }}" name="{{ $name }}" type="file" accept="{{ $accept }}" aria-describedby="{{ $controlId }}-upload-error {{ $errorId ?: $name.'-error' }}" @required($required)>
    </label>
    <div class="file-dropzone-selection" data-selection hidden>
        <img data-preview hidden alt="Vista previa del archivo seleccionado">
        <p class="muted" data-file-status aria-live="polite">Sin archivo seleccionado.</p>
        <button class="button button-secondary button-small" type="button" data-clear-file>Quitar selección</button>
    </div>
    <p class="field-error" id="{{ $controlId }}-upload-error" data-upload-error role="alert" hidden></p>
    <x-admin.field-error :name="$name" :id="$errorId" />
</div>
