<div class="field">
    <label for="name">Nombre</label>
    <input id="name" name="name" value="{{ old('name', $record->name) }}" maxlength="180" required>
    <x-admin.field-error name="name" />
</div>
<div class="field">
    <label for="slug">Slug</label>
    <input id="slug" name="slug" value="{{ old('slug', $record->slug) }}" maxlength="140" aria-describedby="slug-hint{{ $errors->has('slug') ? ' slug-error' : '' }}">
    <small class="field-hint" id="slug-hint">Si lo dejas vacío se genera a partir del nombre.</small>
    <x-admin.field-error name="slug" />
</div>
<div class="field">
    <label for="sort_order">Orden</label>
    <input id="sort_order" name="sort_order" type="number" step="1" min="-2147483648" max="2147483647" value="{{ old('sort_order', $record->sort_order) }}">
    <x-admin.field-error name="sort_order" />
</div>
@if(!$record->exists)
    <div class="field">
        <label for="is_active">Estado</label>
        <select id="is_active" name="is_active">
            <option value="1" @selected((string) old('is_active', '1') === '1')>Activo</option>
            <option value="0" @selected((string) old('is_active', '1') === '0')>Inactivo</option>
        </select>
        <x-admin.field-error name="is_active" />
    </div>
@endif
