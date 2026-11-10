@extends('admin.components.demo', ['demoTitle' => 'Selector de roles'])

@section('usage', 'Busca y selecciona varias opciones ficticias. El selector comparte el patrón del formulario de usuarios.')

@section('example')
    @php
        $roles = collect(['Administrador', 'Operador', 'Profesor', 'Coordinador', 'Soporte'])
            ->map(fn ($name, $index) => (object) ['id' => $index + 1, 'name' => $name, 'is_active' => true]);
    @endphp
    <x-admin.role-selector :roles="$roles" :selected-roles="[]" />
@endsection

@section('integration')
&lt;x-admin.role-selector :roles="$roles" :selected-roles="$selectedRoles" /&gt;
El componente conserva nombres y textos de roles. La autorización se aplica en el módulo que lo usa.
@endsection
