<form method="GET" action="{{ $url }}" class="filter-bar" aria-label="Filtrar listado">
    <label class="filter-search" for="q">Buscar<input id="q" name="q" type="search" value="{{ $filters['q'] ?? '' }}" maxlength="120" placeholder="{{ $placeholder }}"></label>
    <label for="status">Estado guardado
        <select id="status" name="status">
            <option value="">Todos</option>
            <option value="active" @selected(($filters['status'] ?? '') === 'active')>Activos</option>
            <option value="inactive" @selected(($filters['status'] ?? '') === 'inactive')>Inactivos</option>
        </select>
    </label>
    @isset($organizations)
        <label for="organization-filter">Organización
            <select id="organization-filter" name="organization">
                <option value="">Todas</option>
                @foreach($organizations as $organization)
                    <option value="{{ $organization->uuid }}" @selected(($filters['organization'] ?? '') === $organization->uuid)>{{ $organization->name }}</option>
                @endforeach
            </select>
        </label>
    @endisset
    <div class="filter-actions">
        <button class="button button-primary button-small" type="submit">Aplicar filtros</button>
        <a class="button button-secondary button-small" href="{{ $url }}">Limpiar</a>
    </div>
</form>
