@props(['name', 'value' => '', 'label' => 'Contenido', 'help' => 'Usa Markdown para dar formato al texto.', 'rows' => 10, 'required' => false, 'maxLength' => null, 'preview' => true, 'previewVisible' => true, 'errorKey' => null])

@php
    $id = 'markdown-'.\Illuminate\Support\Str::uuid();
    $errorKey ??= $name;
@endphp

<div {{ $attributes->class('markdown-editor') }} data-markdown-editor @if($preview) data-preview-url="{{ route('admin.markdown.preview') }}" @endif>
    <label for="{{ $id }}">{{ $label }}</label>
    <p id="{{ $id }}-help" class="field-hint">{{ $help }}</p>
    <div class="markdown-toolbar" role="group" aria-label="Formato Markdown" hidden>
        @foreach(['bold' => 'Negrita', 'italic' => 'Cursiva', 'heading' => 'Título', 'list' => 'Lista', 'numbered' => 'Lista numerada', 'link' => 'Enlace', 'quote' => 'Cita', 'code' => 'Código'] as $action => $caption)
            <button type="button" class="button button-secondary button-small" data-markdown-action="{{ $action }}" aria-label="{{ $caption }}">{{ $caption }}</button>
        @endforeach
        @if($preview)
            <button type="button" class="button button-secondary button-small markdown-preview-toggle" data-markdown-preview data-preview-visible="{{ $previewVisible ? 'true' : 'false' }}" aria-controls="{{ $id }}-preview" aria-pressed="false" aria-label="Mostrar vista previa" title="Mostrar vista previa">
                <svg viewBox="0 0 24 24" width="20" height="20" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true">
                    <path d="M2 12s3.5-6 10-6 10 6 10 6-3.5 6-10 6-10-6-10-6Z"/>
                    <circle cx="12" cy="12" r="2.5"/>
                    <path data-eye-slash d="m3 3 18 18"/>
                </svg>
            </button>
        @endif
    </div>
    <div class="markdown-panes">
    <textarea id="{{ $id }}" name="{{ $name }}" rows="{{ $rows }}" aria-describedby="{{ $id }}-help @error($errorKey) {{ $id }}-error @enderror" @required($required) @if($maxLength !== null) maxlength="{{ $maxLength }}" @endif @error($errorKey) aria-invalid="true" @enderror>{{ "\n".old($errorKey, $value) }}</textarea>
    @if($preview)
        <section id="{{ $id }}-preview" class="markdown-preview" aria-labelledby="{{ $id }}-preview-title" hidden>
            <h2 id="{{ $id }}-preview-title">Vista previa</h2>
            <p role="status" data-markdown-status hidden></p>
            <div class="markdown-content" data-markdown-output></div>
        </section>
    @endif
    </div>
    @error($errorKey)
        <p id="{{ $id }}-error" class="field-error">{{ $message }}</p>
    @enderror
</div>
