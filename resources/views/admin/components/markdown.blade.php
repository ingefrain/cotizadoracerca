@extends('admin.components.demo', ['demoTitle' => 'Markdown'])

@section('usage', 'Edita Markdown y consulta la vista previa segura en tiempo real.')

@section('example')
        @php
            $example = <<<'MD'
# Ejemplo

Este es un texto con **negrita** y *cursiva*.

- Elemento uno
- Elemento dos

> Una cita.

[Enlace seguro](https://example.com)

`código`

| Campo | Ejemplo |
| --- | --- |
| Estado | Disponible |

```text
Un bloque de código de ejemplo.
```
MD;
        @endphp
        <x-admin.markdown-editor name="example" label="Markdown de ejemplo" :value="$example" />
@endsection

@section('integration')
&lt;x-admin.markdown-editor name="description" label="Descripcion" /&gt;
@endsection
