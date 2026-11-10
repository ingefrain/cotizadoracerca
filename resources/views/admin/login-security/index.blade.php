@extends('layouts.admin')

@section('title', 'Seguridad de acceso')

@section('content')
    <div class="page-intro">
        <p class="muted">Intentos de acceso de las últimas 24 horas y su historial reciente.</p>
        <a class="button button-secondary" href="{{ route('admin.system-audit.index') }}">Volver a Auditoría del sistema</a>
    </div>

    <section class="metric-grid admin-metrics-grid" aria-label="Métricas de seguridad de acceso">
        <article class="metric-card"><span>Accesos exitosos</span><strong>{{ number_format($metrics['successes']) }}</strong><small>Últimas 24 horas</small></article>
        <article class="metric-card"><span>Intentos fallidos</span><strong>{{ number_format($metrics['failures']) }}</strong><small>Últimas 24 horas</small></article>
        <article class="metric-card"><span>Limitados</span><strong>{{ number_format($metrics['rate_limited']) }}</strong><small>Por límite de solicitudes</small></article>
        <article class="metric-card"><span>Cuentas con 5+ fallos</span><strong>{{ number_format($metrics['repeated_accounts']) }}</strong><small>Últimas 24 horas</small></article>
    </section>

    <section class="panel">
        <form method="GET" action="{{ route('admin.login-security.index') }}" class="filter-bar" aria-label="Filtrar intentos de acceso">
            <label class="filter-search" for="search">Usuario o correo<input id="search" name="search" type="search" value="{{ $filters['search'] }}" maxlength="100"></label>
            <label for="ip">IP<input id="ip" name="ip" type="search" value="{{ $filters['ip'] }}" maxlength="45"></label>
            <label for="result">Resultado<select id="result" name="result">
                <option value="">Todos</option>
                @foreach(\App\Models\LoginAttempt::RESULTS as $value)
                    <option value="{{ $value }}" @selected($filters['result'] === $value)>
                        {{ ['success' => 'Acceso correcto', 'invalid_credentials' => 'Credenciales inválidas', 'inactive_account' => 'Cuenta inactiva', 'rate_limited' => 'Limitado por frecuencia'][$value] }}
                    </option>
                @endforeach
            </select></label>
            <div class="filter-actions"><button class="button button-primary button-small" type="submit">Aplicar</button><a class="button button-secondary button-small" href="{{ route('admin.login-security.index') }}">Limpiar</a></div>
        </form>

        @if($attempts->isEmpty())
            <x-admin.empty-state title="Sin intentos para mostrar" description="Los intentos de acceso aparecerán aquí cuando se utilice el formulario de inicio de sesión." />
        @else
            <div class="table-wrap"><table>
                <thead><tr><th>Fecha y hora</th><th>Usuario / correo intentado</th><th>Resultado</th><th>IP</th><th>Última actividad autenticada</th></tr></thead>
                <tbody>
                @foreach($attempts as $attempt)
                    @php($resultLabel = ['success' => 'Acceso correcto', 'invalid_credentials' => 'Credenciales inválidas', 'inactive_account' => 'Cuenta inactiva', 'rate_limited' => 'Limitado por frecuencia'][$attempt->result] ?? 'Otro')
                    <tr>
                        <td><time datetime="{{ $attempt->created_at->toIso8601String() }}">{{ $attempt->created_at->timezone(config('app.timezone'))->format('d/m/Y H:i:s') }}</time></td>
                        <td>
                            {{ $attempt->user?->name ?? $attempt->email ?? 'Sin identificador' }}
                            @if($attempt->email)<small class="muted">Intento: {{ $attempt->email }}</small>@endif
                            @if($attempt->user && $attempt->user->email !== $attempt->email)<small class="muted">Cuenta actual: {{ $attempt->user->email }}</small>@endif
                        </td>
                        <td><span class="badge {{ $attempt->result === 'success' ? 'badge-success' : ($attempt->result === 'rate_limited' ? 'badge-neutral' : 'badge-danger') }}">{{ $resultLabel }}</span></td>
                        <td>{{ $attempt->ip_address ?? '—' }}</td>
                        <td>{{ $attempt->user?->last_activity_at?->timezone(config('app.timezone'))->format('d/m/Y H:i:s') ?? '—' }}</td>
                    </tr>
                @endforeach
                </tbody>
            </table></div>
            <div class="pagination-wrap">{{ $attempts->links() }}</div>
        @endif
    </section>
@endsection
