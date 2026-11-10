@props(['title', 'description' => null])
<div {{ $attributes->class(['empty-state']) }}>
    <span class="empty-mark" aria-hidden="true">—</span>
    <h2>{{ $title }}</h2>
    @if($description)<p>{{ $description }}</p>@endif
    {{ $slot }}
</div>
