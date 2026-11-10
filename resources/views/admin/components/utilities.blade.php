@extends('admin.components.demo', ['demoTitle' => 'Utilidades'])

@section('usage', 'Controles y feedback reutilizables para interacciones administrativas. Los ejemplos se ejecutan en esta página.')

@section('example')
    <h3>Toggle de estado</h3>
    <div class="field-grid">
        <div class="field"><strong>Activo</strong><x-admin.status-toggle :active="true" label="Estado del ejemplo activo" demo="success" /></div>
        <div class="field"><strong>Inactivo</strong><x-admin.status-toggle :active="false" label="Estado del ejemplo inactivo" demo="success" /></div>
        <div class="field"><strong>Guardando</strong><x-admin.status-toggle :active="true" label="Estado del ejemplo en proceso" demo="loading" /></div>
        <div class="field"><strong>Deshabilitado</strong><x-admin.status-toggle :active="false" :disabled="true" label="Estado del ejemplo deshabilitado" demo="success" /></div>
        <div class="field"><strong>Error simulado</strong><x-admin.status-toggle :active="true" label="Estado del ejemplo con error" demo="error" /></div>
    </div>
    <h3>Notificaciones</h3>
    <div class="page-actions">
        <button type="button" class="button button-secondary" data-notify-trigger="success" data-notify-text="Cambios guardados.">Éxito</button>
        <button type="button" class="button button-secondary" data-notify-trigger="info" data-notify-text="Operación completada.">Información</button>
        <button type="button" class="button button-secondary" data-notify-trigger="warning" data-notify-text="La operación terminó. Algunos elementos requieren revisión antes de continuar con el siguiente paso.">Advertencia</button>
        <button type="button" class="button button-secondary" data-notify-trigger="error" data-notify-text="No fue posible completar la operación.">Error</button>
    </div>
@endsection

@section('integration')
&lt;x-admin.status-toggle :active="$record-&gt;is_active" :action="$statusUrl" :label="'Estado de '.$record-&gt;name" /&gt;
AdminNotify.show({ type: 'success', message: 'Cambios guardados.' });
@endsection
