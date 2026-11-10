<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\Blade;
use Tests\TestCase;

class AdminChartTest extends TestCase
{
    public function test_chart_uses_the_same_safe_data_for_the_canvas_and_table(): void
    {
        $label = 'Categoría </script><script>alert(1)</script>';
        $html = Blade::render(<<<'BLADE'
            <x-admin.chart type="bar" title="Resumen" description="Valores de ejemplo"
                :labels="$labels" :datasets="$datasets" format="currency" currency="MXN" value-suffix=" netos" />
            BLADE, [
            'labels' => [$label],
            'datasets' => [
                ['label' => 'Altas', 'data' => [12500], 'options' => ['ignore' => true]],
                ['label' => 'Bajas', 'data' => [12.5]],
            ],
        ]);

        $this->assertStringContainsString('role="img" aria-label="Resumen" aria-describedby=', $html);
        $this->assertStringContainsString('Valores de ejemplo', $html);
        $this->assertStringContainsString('<summary>Ver datos</summary>', $html);
        $this->assertStringContainsString('<th scope="row">'.e($label).'</th>', $html);
        $this->assertStringContainsString('MXN 12,500.00 netos', $html);
        $this->assertStringNotContainsString($label, $html);
        preg_match('/<script type="application\/json" data-chart-config>(.*?)<\/script>/s', $html, $match);
        $payload = json_decode($match[1], true, flags: JSON_THROW_ON_ERROR);
        $this->assertSame([$label], $payload['labels']);
        $this->assertTrue($payload['legend']);
        $this->assertSame('currency', $payload['format']);
        $this->assertSame('MXN', $payload['currency']);
        $this->assertSame(['label' => 'Altas', 'data' => [12500]], $payload['datasets'][0]);
        $this->assertSame(['label' => 'Bajas', 'data' => [12.5]], $payload['datasets'][1]);
    }

    public function test_empty_and_invalid_charts_keep_useful_states_without_canvas(): void
    {
        $empty = Blade::render('<x-admin.chart title="Vacía" />');
        $this->assertStringContainsString('No hay datos para mostrar.', $empty);
        $this->assertStringNotContainsString('<canvas', $empty);

        foreach ([
            ['radar', [1], 'integer', null],
            ['line', ['1'], 'integer', null],
            ['bar', [INF], 'integer', null],
            ['pie', [-1], 'integer', null],
            ['line', [1, 2], 'integer', null],
            ['bar', [1], 'currency', null],
        ] as [$type, $values, $format, $currency]) {
            $html = Blade::render(<<<'BLADE'
                <x-admin.chart :type="$type" title="Inválida" :format="$format" :currency="$currency"
                    :labels="['A']" :datasets="[['label' => 'Serie', 'data' => $values]]" />
                BLADE, compact('type', 'values', 'format', 'currency'));
            $this->assertStringContainsString('No fue posible mostrar la gráfica.', $html);
            $this->assertStringNotContainsString('<canvas', $html);
        }
    }
}
