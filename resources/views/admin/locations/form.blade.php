@extends('layouts.admin')

@section('title', $creating ? 'Crear sede' : 'Editar sede')

@section('content')
    <div class="page-intro"><div><p class="eyebrow">Sedes</p><p class="muted">Cada sede pertenece a una organización. Dirección y coordenadas son opcionales.</p></div><a class="button button-secondary" href="{{ route('admin.locations.index') }}">Volver</a></div>
    <section class="panel form-panel admin-form-surface admin-form-surface-wide">
        <form method="POST" action="{{ $creating ? route('admin.locations.store') : route('admin.locations.update', $location) }}" class="stack-form">
            @csrf @unless($creating) @method('PUT') @endunless
            <div class="field-grid">
                <div class="field"><label for="organization_id">Organización</label><select id="organization_id" name="organization_id" required><option value="">Selecciona una organización</option>@foreach($organizations as $organization)<option value="{{ $organization->id }}" @selected((string)old('organization_id', $location->organization_id) === (string)$organization->id)>{{ $organization->name }}{{ $organization->is_active ? '' : ' (inactiva)' }}</option>@endforeach</select><x-admin.field-error name="organization_id" /></div>
                <div class="field"><label for="name">Nombre de sede</label><input id="name" name="name" value="{{ old('name', $location->name) }}" maxlength="180" required><x-admin.field-error name="name" /></div>
                <div class="field"><label for="code">Código (opcional)</label><input id="code" name="code" value="{{ old('code', $location->code) }}" maxlength="100"><x-admin.field-error name="code" /></div>
                <div class="field"><label for="email">Correo (opcional)</label><input id="email" type="email" name="email" value="{{ old('email', $location->email) }}" maxlength="254"><x-admin.field-error name="email" /></div>
                <div class="field"><label for="phone">Teléfono (opcional)</label><input id="phone" name="phone" value="{{ old('phone', $location->phone) }}" maxlength="50"><x-admin.field-error name="phone" /></div>
                <div class="field"><label for="address_line_1">Dirección línea 1</label><input id="address_line_1" name="address_line_1" value="{{ old('address_line_1', $location->address_line_1) }}" maxlength="180"></div>
                <div class="field"><label for="address_line_2">Dirección línea 2</label><input id="address_line_2" name="address_line_2" value="{{ old('address_line_2', $location->address_line_2) }}" maxlength="180"></div>
                <div class="field"><label for="city">Ciudad</label><input id="city" name="city" value="{{ old('city', $location->city) }}" maxlength="120"></div>
                <div class="field"><label for="state">Estado / región</label><input id="state" name="state" value="{{ old('state', $location->state) }}" maxlength="120"></div>
                <div class="field"><label for="postal_code">Código postal</label><input id="postal_code" name="postal_code" value="{{ old('postal_code', $location->postal_code) }}" maxlength="30"></div>
                <div class="field"><label for="country_code">País (ISO 3166-1 alpha-2)</label><input id="country_code" name="country_code" value="{{ old('country_code', $location->country_code) }}" maxlength="2" pattern="[A-Za-z]{2}"></div>
                <div class="field"><label for="latitude">Latitud (opcional)</label><input id="latitude" type="number" step="any" min="-90" max="90" name="latitude" value="{{ old('latitude', $location->latitude) }}"></div>
                <div class="field"><label for="longitude">Longitud (opcional)</label><input id="longitude" type="number" step="any" min="-180" max="180" name="longitude" value="{{ old('longitude', $location->longitude) }}"></div>
            </div>
            <div class="form-actions"><button class="button button-primary" type="submit">{{ $creating ? 'Crear sede' : 'Guardar cambios' }}</button><a class="button button-secondary" href="{{ route('admin.locations.index') }}">Cancelar</a></div>
        </form>
    </section>
@endsection
