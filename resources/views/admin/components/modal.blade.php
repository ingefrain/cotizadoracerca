@extends('admin.components.demo', ['demoTitle' => 'Modales'])

@section('usage', 'Prueba el foco inicial, el cierre con Escape y el retorno al botón que abrió el diálogo.')

@section('example')
    <button class="button button-primary" type="button" data-modal-open="component-demo-modal">Abrir modal</button>
    <dialog id="component-demo-modal" class="profile-modal" aria-labelledby="component-demo-modal-title" data-profile-modal>
        <div class="profile-modal-header">
            <h2 id="component-demo-modal-title">Modal de ejemplo</h2>
            <button class="modal-close" type="button" aria-label="Cerrar ventana" data-modal-close>×</button>
        </div>
        <p>Prueba el cierre con el teclado o con los botones.</p>
        <div class="profile-modal-actions">
            <button class="button button-secondary" type="button" data-modal-close data-modal-initial-focus>Cancelar</button>
        </div>
    </dialog>
@endsection

@section('integration')
&lt;button type="button" data-modal-open="demo"&gt;Abrir modal&lt;/button&gt;
&lt;dialog id="demo" data-profile-modal aria-label="Ejemplo"&gt;…&lt;/dialog&gt;
@endsection
