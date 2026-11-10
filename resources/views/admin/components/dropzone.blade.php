@extends('admin.components.demo', ['demoTitle' => 'Dropzone'])

@section('usage', 'Prueba la selección, el arrastre y la validación de archivos. La selección es temporal en el navegador.')

@section('example')
    <x-admin.dropzone name="demo_file" accept="image/png,image/jpeg,application/pdf,text/plain" :mime-types="['image/png', 'image/jpeg', 'application/pdf', 'text/plain']" :max-size-kb="1024" />
@endsection

@section('integration')
&lt;x-admin.dropzone name="file" :mime-types="['image/png']" :max-size-kb="1024" /&gt;
@endsection
