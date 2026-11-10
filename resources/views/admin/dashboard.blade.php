@extends('layouts.admin')

@section('title', 'Resumen')

@section('content')
    <div class="page-intro">
        <p class="muted">Estado general de la administración.</p>
    </div>
    <section class="metric-grid" aria-label="Resumen administrativo">
        <article class="metric-card"><span>Usuarios activos</span><strong>{{ number_format($activeUsers) }}</strong><small>Con acceso vigente</small></article>
        <article class="metric-card"><span>Usuarios inactivos</span><strong>{{ number_format($inactiveUsers) }}</strong><small>Sin acceso al panel</small></article>
        <article class="metric-card"><span>Roles activos</span><strong>{{ number_format($activeRoles) }}</strong><small>Disponibles para asignación</small></article>
    </section>
@endsection
