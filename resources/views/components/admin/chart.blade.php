@props([
    'type' => 'bar', 'title', 'description' => null, 'labels' => [], 'datasets' => [],
    'size' => 'medium', 'legend' => null, 'beginAtZero' => true,
    'format' => 'decimal', 'currency' => null, 'valuePrefix' => '', 'valueSuffix' => '',
])

@php
    $chart = \App\Support\AdminChartData::normalize($type, $labels, $datasets, $format, $currency);
    $id = 'admin-chart-'.\Illuminate\Support\Str::uuid();
    $size = in_array($size, ['small', 'medium', 'large'], true) ? $size : 'medium';
    $valuePrefix = is_string($valuePrefix) ? mb_substr($valuePrefix, 0, 24) : '';
    $valueSuffix = is_string($valueSuffix) ? mb_substr($valueSuffix, 0, 24) : '';
    $circular = in_array($type, ['doughnut', 'pie'], true);
@endphp

<section {{ $attributes->class('admin-chart-card') }} aria-labelledby="{{ $id }}-title">
    <h3 id="{{ $id }}-title">{{ $title }}</h3>
    <p id="{{ $id }}-description" class="muted">{{ $description ?? 'Consulta los valores en la tabla de datos.' }}</p>

    @if($chart === null)
        <x-admin.alert variant="error">No fue posible mostrar la gráfica. Revisa sus datos.</x-admin.alert>
    @elseif($chart['empty'])
        <x-admin.empty-state title="No hay datos para mostrar." />
    @else
        <div data-admin-chart>
            <p class="muted" data-chart-fallback>Consulta la tabla para ver los datos de esta gráfica.</p>
            <div class="admin-chart admin-chart-{{ $size }}" data-chart-frame hidden>
                <canvas role="img" aria-label="{{ $title }}" aria-describedby="{{ $id }}-description"></canvas>
            </div>
            <script type="application/json" data-chart-config>{!! \Illuminate\Support\Js::encode([
                'type' => $type, 'labels' => $chart['labels'], 'datasets' => $chart['datasets'],
                'legend' => $legend === null ? ($circular || count($chart['datasets']) > 1) : (bool) $legend,
                'beginAtZero' => (bool) $beginAtZero,
                'format' => $format, 'currency' => $format === 'currency' ? $currency : null,
                'prefix' => $valuePrefix, 'suffix' => $valueSuffix,
            ], JSON_INVALID_UTF8_SUBSTITUTE) !!}</script>

            <details class="admin-chart-data" open>
                <summary>Ver datos</summary>
                <div class="table-wrap" role="region" aria-label="Datos de {{ $title }}" tabindex="0">
                    <table>
                        <caption class="sr-only">Datos de {{ $title }}</caption>
                        <thead><tr><th scope="col">Categoría</th>@foreach($chart['datasets'] as $series)<th scope="col">{{ $series['label'] }}</th>@endforeach</tr></thead>
                        <tbody>
                            @foreach($chart['labels'] as $index => $label)
                                <tr>
                                    <th scope="row">{{ $label }}</th>
                                    @foreach($chart['datasets'] as $series)
                                        <td>{{ $valuePrefix.\App\Support\AdminChartData::format($series['data'][$index], $format, $format === 'currency' ? $currency : null).$valueSuffix }}</td>
                                    @endforeach
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            </details>
        </div>
        @pushOnce('admin-scripts', 'admin-charts')
            @php
                try {
                    $chartAssets = app(\Illuminate\Foundation\Vite::class)(['resources/js/admin-charts.js']);
                } catch (\Illuminate\Foundation\ViteException) {
                    $chartAssets = null;
                }
            @endphp
            {{ $chartAssets }}
        @endPushOnce
    @endif
</section>
