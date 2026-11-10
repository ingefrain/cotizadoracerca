@extends('layouts.admin')

@section('title', 'Notificaciones')

@section('content')
    <div class="page-intro"><p class="muted">Consulta entregas, reintentos y mensajes del sistema. El contenido se mantiene oculto.</p></div>
    <section class="panel">
        <form method="GET" action="{{ route('admin.notifications.index') }}" class="filter-bar" aria-label="Filtrar mensajes transaccionales">
            <label class="filter-search" for="q">Buscar por ID, correlación o destinatario<input id="q" name="q" type="search" value="{{ $filters['q'] ?? '' }}" maxlength="320"></label>
            <label for="status">Estado<select id="status" name="status"><option value="">Todos</option>
                @foreach(\App\Models\OutboxMessage::STATUSES as $status)<option value="{{ $status }}" @selected(($filters['status'] ?? '') === $status)>{{ ucfirst($status) }}</option>@endforeach
            </select></label>
            <label for="channel">Canal<select id="channel" name="channel"><option value="">Todos</option>@foreach($channels as $channel => $label)<option value="{{ $channel }}" @selected(($filters['channel'] ?? '') === $channel)>{{ $label }}</option>@endforeach</select></label>
            <div class="filter-actions"><button class="button button-primary button-small" type="submit">Aplicar</button><a class="button button-secondary button-small" href="{{ route('admin.notifications.index') }}">Limpiar</a></div>
        </form>
        @if($messages->isEmpty())
            <x-admin.empty-state title="Sin mensajes para mostrar" description="Los mensajes creados por la aplicación aparecerán aquí." />
        @else
            <div class="table-wrap"><table>
                <thead><tr><th>Creado</th><th>Identificador</th><th>Destinatario</th><th>Canal</th><th>Estado</th><th>Intentos</th><th></th></tr></thead>
                <tbody>
                @foreach($messages as $message)
                    <tr>
                        <td><time datetime="{{ $message->created_at->toIso8601String() }}">{{ $message->created_at->format('d/m/Y H:i') }}</time></td>
                        <td><code>{{ $message->uuid }}</code></td>
                        <td>{{ $message->masked_recipient }}</td>
                        <td>{{ ucfirst($message->channel) }}</td>
                        <td><span class="badge {{ $message->status === 'sent' ? 'badge-success' : ($message->status === 'failed' ? 'badge-danger' : 'badge-neutral') }}">{{ ucfirst($message->status) }}</span></td>
                        <td>{{ $message->attempts }}<span class="muted"> · ciclo {{ $message->retry_generation + 1 }}</span></td>
                        <td><a class="button button-secondary button-small" href="{{ route('admin.notifications.show', $message) }}">Detalle</a></td>
                    </tr>
                @endforeach
                </tbody>
            </table></div>
            <div class="pagination-wrap">{{ $messages->links() }}</div>
        @endif
    </section>
@endsection
