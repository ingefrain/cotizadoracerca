<!doctype html>
<html lang="es">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex, nofollow">
    @if(config('maintenance_page.refresh_seconds', 0) > 0)
        <meta http-equiv="refresh" content="{{ (int) config('maintenance_page.refresh_seconds') }}">
    @endif
    <title>Actualización en curso · {{ config('app.name', 'Aplicación') }}</title>
    <style>
        *{box-sizing:border-box}body{margin:0;min-height:100vh;display:grid;place-items:center;padding:24px;background:#f4f6f8;color:#1f2937;font-family:system-ui,-apple-system,"Segoe UI",sans-serif;line-height:1.6}main{width:100%;max-width:560px;text-align:center;padding:48px 32px;background:#fff;border:1px solid #e5e7eb;border-radius:20px;box-shadow:0 16px 48px #1118270d}.brand{display:block;max-width:min(220px,100%);max-height:80px;object-fit:contain;margin:0 auto 32px}.symbol{display:grid;place-items:center;width:60px;height:60px;margin:0 auto 22px;border-radius:18px;color:#475569;background:#eef2f6;font-size:28px}h1{font-size:clamp(1.55rem,5vw,2rem);line-height:1.25;letter-spacing:-.02em;margin:0 0 14px;font-weight:700}.message{font-size:1.0625rem;margin:0 auto 10px;max-width:420px}.secondary{margin:0;color:#64748b}@media(max-width:420px){body{padding:16px}main{padding:36px 22px;border-radius:16px}}
    </style>
</head>
<body>
<main aria-labelledby="maintenance-title">
    @if(config('maintenance_page.logo_url'))<img class="brand" src="{{ config('maintenance_page.logo_url') }}" alt="{{ config('app.name', 'Aplicación') }}">@else<div class="symbol" aria-hidden="true">↻</div>@endif
    <p class="muted">{{ config('app.name', 'Aplicación') }}</p>
    <h1 id="maintenance-title">{{ config('maintenance_page.message', 'Estamos realizando una actualización.') }}</h1>
    @if(config('maintenance_page.secondary_message'))<p class="secondary">{{ config('maintenance_page.secondary_message') }}</p>@endif
</main>
</body>
</html>
