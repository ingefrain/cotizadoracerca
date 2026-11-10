@extends('admin.components.demo', ['demoTitle' => 'Confirmaciones y avisos'])

@section('usage', 'Abre los ejemplos con el teclado o con el ratón. Ninguna acción de esta demo guarda cambios.')

@section('example')
    <div class="confirm-demo-actions">
        <button class="button button-primary" type="button" data-modal-open="demo-confirm-save">Guardar cambios</button>
        <button class="button button-secondary" type="button" data-modal-open="demo-confirm-warning">Pausar avisos</button>
        <button class="button button-danger" type="button" data-modal-open="demo-confirm-delete">Eliminar documento</button>
        <button class="button button-danger" type="button" data-modal-open="demo-confirm-purge">Eliminación reforzada</button>
        <button class="button button-secondary" type="button" data-modal-open="demo-confirm-info">Ver información</button>
    </div>

    <x-admin.confirm-dialog standalone id="demo-confirm-save" title="Guardar cambios"
        message="¿Deseas guardar los cambios de este ejemplo?" confirm-label="Guardar cambios" cancel-label="Seguir editando" />
    <x-admin.confirm-dialog standalone id="demo-confirm-warning" title="Pausar avisos" variant="warning"
        message="Los avisos de este ejemplo se suspenderán temporalmente."
        consequence="Puedes reanudar los avisos cuando los necesites." confirm-label="Pausar avisos" />
    <x-admin.confirm-dialog standalone id="demo-confirm-delete" title="Eliminar documento" variant="danger"
        message="El documento dejará de estar disponible." consequence="El archivo permanecerá durante el periodo de retención." />
    <x-admin.confirm-dialog standalone id="demo-confirm-purge" title="Eliminar archivo definitivamente" variant="danger-confirm"
        message="En una purga real, el archivo se borraría de forma permanente."
        consequence="La purga es irreversible. Este ejemplo solo muestra el diálogo."
        confirm-label="Eliminar definitivamente"
        phrase="ELIMINAR" />
    <x-admin.confirm-dialog standalone id="demo-confirm-info" title="Información de la demo" variant="info"
        message="Puedes probar estos controles sin modificar registros." />

    <x-admin.alert variant="success">Los cambios de ejemplo se guardaron correctamente.</x-admin.alert>
    <x-admin.alert variant="info">Los ejemplos no envían datos al servidor.</x-admin.alert>
    <x-admin.alert variant="warning">Revisa las consecuencias antes de desactivar un registro.</x-admin.alert>
    <x-admin.alert variant="error">No se pudieron guardar los cambios de ejemplo.</x-admin.alert>
@endsection

@section('integration')
&lt;form method="POST" action="…"&gt;
    &#64;csrf &#64;method('PATCH')
    &lt;x-admin.confirm-dialog title="Desactivar usuario" variant="warning"
        message="El usuario dejará de poder iniciar sesión."
        consequence="Sus datos y su historial se conservarán."
        confirm-label="Desactivar usuario" /&gt;
    &lt;button type="submit"&gt;Desactivar&lt;/button&gt;
&lt;/form&gt;
&lt;!-- Para una purga permanente, usa variant="danger-confirm" y phrase="ELIMINAR". --&gt;
&lt;x-admin.alert variant="success"&gt;Cambios guardados.&lt;/x-admin.alert&gt;
@endsection
