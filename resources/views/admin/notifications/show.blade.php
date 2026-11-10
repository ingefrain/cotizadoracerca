@extends('layouts.admin')

@section('title', 'Detalle de notificación')

@section('content')
    <div class="page-intro"><p class="muted">El destinatario se muestra parcialmente y el contenido queda oculto.</p><a class="button button-secondary" href="{{ route('admin.notifications.index') }}">Volver</a></div>
    <section class="panel">
        <dl class="detail-grid">
            <div><dt>Identificador</dt><dd><code>{{ $outboxMessage->uuid }}</code></dd></div>
            <div><dt>Estado</dt><dd>{{ ucfirst($outboxMessage->status) }}</dd></div>
            <div><dt>Categoría</dt><dd>{{ $outboxMessage->category }}</dd></div>
            <div><dt>Canal</dt><dd>{{ ucfirst($outboxMessage->channel) }}</dd></div>
            <div><dt>Destinatario</dt><dd>{{ $maskedRecipient }}</dd></div>
            <div><dt>Plantilla</dt><dd>{{ $outboxMessage->template }}</dd></div>
            <div><dt>Intentos en ciclo</dt><dd>{{ $outboxMessage->attempts }} · ciclo {{ $outboxMessage->retry_generation + 1 }}</dd></div>
            <div><dt>Disponible desde</dt><dd>{{ $outboxMessage->available_at?->format('d/m/Y H:i:s') ?? '—' }}</dd></div>
            <div><dt>Enviado</dt><dd>{{ $outboxMessage->sent_at?->format('d/m/Y H:i:s') ?? '—' }}</dd></div>
            <div><dt>Correlación</dt><dd>{{ $outboxMessage->correlation_id ?? '—' }}</dd></div>
            <div class="detail-wide"><dt>Último error</dt><dd>{{ $outboxMessage->last_error_message ?? '—' }}@if($outboxMessage->last_error_type)<span class="muted"> ({{ $outboxMessage->last_error_type }})</span>@endif</dd></div>
            <div class="detail-wide"><dt>Contenido de la notificación</dt><dd class="muted">Se omite por seguridad.</dd></div>
        </dl>
        @can('notifications.retry')
            @if($outboxMessage->status === 'failed')
                <form method="POST" action="{{ route('admin.notifications.retry', $outboxMessage) }}" class="form-actions">
                    @csrf
                    <button class="button button-primary" type="submit">Reintentar mensaje</button>
                </form>
            @endif
        @endcan
    </section>
    <section class="panel">
        <div class="panel-heading"><div><h3>Historial de intentos</h3><p class="muted">Se guardan resultados y errores sanitizados, no excepciones de transporte.</p></div></div>
        @if($outboxMessage->attemptsHistory->isEmpty())
            <x-admin.empty-state title="Sin intentos" description="El procesamiento aún no ha comenzado." />
        @else
            <div class="table-wrap"><table><thead><tr><th>Intento</th><th>Inicio</th><th>Fin</th><th>Resultado</th><th>Error</th></tr></thead><tbody>
                @foreach($outboxMessage->attemptsHistory as $attempt)
                    <tr><td>{{ $attempt->attempt_number }}</td><td>{{ $attempt->started_at->format('d/m/Y H:i:s') }}</td><td>{{ $attempt->finished_at?->format('d/m/Y H:i:s') ?? 'En curso' }}</td><td>{{ $attempt->result ?? 'processing' }}</td><td>{{ $attempt->error_message ?? '—' }}@if($attempt->error_type)<span class="muted"> ({{ $attempt->error_type }})</span>@endif</td></tr>
                @endforeach
            </tbody></table></div>
        @endif
    </section>
@endsection
