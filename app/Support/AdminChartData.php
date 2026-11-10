<?php

namespace App\Support;

final class AdminChartData
{
    public static function normalize(mixed $type, mixed $labels, mixed $datasets, mixed $format, mixed $currency): ?array
    {
        if (! in_array($type, ['bar', 'line', 'doughnut', 'pie'], true)
            || ! in_array($format, ['integer', 'decimal', 'percent', 'currency'], true)
            || ($format === 'currency' && (! is_string($currency) || ! preg_match('/^[A-Z]{3}$/D', $currency)))
            || ! is_array($labels) || ! array_is_list($labels) || count($labels) > 120
            || ! is_array($datasets) || ! array_is_list($datasets) || count($datasets) > 8) {
            return null;
        }

        foreach ($labels as $label) {
            if (! self::validLabel($label)) {
                return null;
            }
        }

        $circular = in_array($type, ['doughnut', 'pie'], true);
        if ($circular && count($datasets) > 1) {
            return null;
        }

        $series = [];
        foreach ($datasets as $dataset) {
            if (! is_array($dataset) || ! self::validLabel($dataset['label'] ?? null)
                || ! is_array($dataset['data'] ?? null) || ! array_is_list($dataset['data'])
                || count($dataset['data']) !== count($labels)) {
                return null;
            }
            foreach ($dataset['data'] as $value) {
                if ((! is_int($value) && ! is_float($value)) || ! is_finite($value)
                    || abs($value) > 1e12 || ($circular && $value < 0)) {
                    return null;
                }
            }
            $series[] = ['label' => $dataset['label'], 'data' => $dataset['data']];
        }

        $empty = $labels === [] || $series === [] || ($circular && array_sum($series[0]['data']) <= 0);

        return ['labels' => $labels, 'datasets' => $series, 'empty' => $empty];
    }

    public static function format(int|float $value, string $format, ?string $currency): string
    {
        return match ($format) {
            'integer' => number_format($value),
            'percent' => number_format($value * 100, 2).' %',
            'currency' => $currency.' '.number_format($value, 2),
            default => number_format($value, 2),
        };
    }

    private static function validLabel(mixed $label): bool
    {
        return is_string($label) && trim($label) !== '' && mb_check_encoding($label, 'UTF-8') && mb_strlen($label) <= 160;
    }
}
