<form method="post" action="{{ route('admin.imports.destroy', $run) }}" data-confirm-form>
    @csrf
    @method('DELETE')
    <button class="button button-danger button-small" type="submit" aria-label="Eliminar {{ $run->original_name }}">{{ $deleteLabel }}</button>
    <x-admin.confirm-dialog title="Eliminar importación" variant="danger"
        message="Se eliminará esta corrida y su archivo temporal."
        consequence="No se ha aplicado ningún cambio de negocio desde esta importación."
        confirm-label="Eliminar importación" />
</form>
