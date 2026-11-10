import {
    ArcElement, BarController, BarElement, CategoryScale, Chart, DoughnutController,
    Legend, LinearScale, LineController, LineElement, PieController, PointElement, Tooltip,
} from 'chart.js';

Chart.register(
    ArcElement, BarController, BarElement, CategoryScale, DoughnutController, Legend,
    LinearScale, LineController, LineElement, PieController, PointElement, Tooltip,
);

const chartTypes = ['bar', 'line', 'doughnut', 'pie'];

function valueFormatter({ format, currency, prefix, suffix }) {
    const digits = format === 'integer' ? 0 : 2;
    const numbers = new Intl.NumberFormat('es-MX', {
        minimumFractionDigits: digits,
        maximumFractionDigits: digits,
    });

    return value => {
        const number = numbers.format(format === 'percent' ? value * 100 : value);
        if (format === 'percent') return prefix + number + ' %' + suffix;
        if (format === 'currency') return prefix + currency + ' ' + number + suffix;
        return prefix + number + suffix;
    };
}

function initChart(element) {
    const canvas = element.querySelector('canvas');
    const frame = element.querySelector('[data-chart-frame]');
    if (!canvas || !frame || Chart.getChart(canvas)) return;
    const fallback = element.querySelector('[data-chart-fallback]');

    try {
        const config = JSON.parse(element.querySelector('[data-chart-config]').textContent);
        const { type, labels, datasets } = config;
        if (!chartTypes.includes(type) || !Array.isArray(labels) || !labels.length
            || !labels.every(label => typeof label === 'string')
            || !Array.isArray(datasets) || !datasets.length
            || !datasets.every(series => typeof series?.label === 'string' && Array.isArray(series.data)
                && series.data.length === labels.length && series.data.every(Number.isFinite))) {
            throw new Error('Datos de gráfica inválidos.');
        }

        const circular = type === 'pie' || type === 'doughnut';
        const styles = getComputedStyle(element);
        const palette = Array.from({ length: 8 }, (_, i) => styles.getPropertyValue(`--chart-${i + 1}`).trim());
        const colorAt = index => palette[index % palette.length];
        const format = valueFormatter(config);
        const textColor = styles.getPropertyValue('--muted').trim();
        frame.hidden = false;

        new Chart(canvas, {
            type,
            data: {
                labels,
                datasets: datasets.map((series, index) => ({
                    label: series.label,
                    data: series.data,
                    backgroundColor: circular ? labels.map((_, index) => colorAt(index)) : colorAt(index),
                    borderColor: circular ? styles.getPropertyValue('--paper').trim() : colorAt(index),
                    borderWidth: 2,
                    ...(type === 'line' ? { tension: 0.2, pointRadius: 3, borderDash: index % 2 ? [5, 3] : [] } : {}),
                })),
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                color: textColor,
                animation: window.matchMedia('(prefers-reduced-motion: reduce)').matches ? false : { duration: 220 },
                plugins: {
                    legend: { display: config.legend === true, position: 'bottom', labels: { boxWidth: 12 } },
                    tooltip: {
                        callbacks: {
                            label: item => circular
                                ? `${item.label}: ${format(item.parsed)}`
                                : `${item.dataset.label}: ${format(item.parsed.y)}`,
                        },
                    },
                },
                ...(circular ? {} : { scales: { y: { beginAtZero: config.beginAtZero === true, ticks: { callback: format } } } }),
            },
        });

        fallback.hidden = true;
        element.querySelector('details').open = false;
    } catch {
        Chart.getChart(canvas)?.destroy();
        frame.hidden = true;
        fallback.textContent = 'No fue posible mostrar la gráfica. Consulta la tabla de datos.';
        fallback.hidden = false;
    }
}

document.querySelectorAll('[data-admin-chart]').forEach(initChart);

window.addEventListener('beforeprint', () => {
    document.querySelectorAll('.admin-chart-data:not([open])').forEach(details => {
        details.dataset.chartPrint = '';
        details.open = true;
    });
});
window.addEventListener('afterprint', () => {
    document.querySelectorAll('[data-chart-print]').forEach(details => {
        details.open = false;
        delete details.dataset.chartPrint;
    });
});
