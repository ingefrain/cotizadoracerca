@extends('admin.components.demo', ['demoTitle' => 'Gráficas'])

@section('usage', 'Datos demostrativos. Cada gráfica incluye una tabla con sus valores.')

@section('example')
    <div class="admin-chart-grid">
        <x-admin.chart type="bar" title="Usuarios por estado" description="Comparación de altas y bajas por periodo."
            :labels="['Enero', 'Febrero', 'Marzo']"
            :datasets="[['label' => 'Altas', 'data' => [12, 18, 15]], ['label' => 'Bajas', 'data' => [4, 6, 3]]]"
            format="integer" />
        <x-admin.chart type="line" title="Actividad mensual" description="Sesiones registradas durante cuatro meses."
            :labels="['Enero', 'Febrero', 'Marzo', 'Abril']"
            :datasets="[['label' => 'Sesiones', 'data' => [17, 24, 19, 28]]]" format="integer" />
        <x-admin.chart type="doughnut" title="Distribución por categoría" description="Proporción de documentos por categoría."
            :labels="['Guías', 'Fichas', 'Otros']"
            :datasets="[['label' => 'Proporción', 'data' => [0.5, 0.3, 0.2]]]" format="percent" />
        <x-admin.chart type="pie" title="Solicitudes por canal" description="Reparto de solicitudes entre web y atención directa."
            :labels="['Web', 'Atención directa']"
            :datasets="[['label' => 'Solicitudes', 'data' => [36, 18]]]" format="integer" />
    </div>
@endsection

@section('integration')
&lt;x-admin.chart type="bar" title="Usuarios por estado" :labels="$labels" :datasets="$datasets" format="integer" /&gt;
@endsection
