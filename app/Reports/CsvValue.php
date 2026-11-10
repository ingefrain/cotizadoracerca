<?php

namespace App\Reports;

use DateTimeInterface;

final class CsvValue
{
    public static function format(string|int|DateTimeInterface|null $value): string|int
    {
        if ($value instanceof DateTimeInterface) {
            return $value->format('Y-m-d H:i:s');
        }

        if (is_string($value) && preg_match('/^(?:[\t\r\n]|[\p{Z}\p{C}]*[=+\-@＝＋－＠])/u', $value) === 1) {
            return "'".$value;
        }

        return $value ?? '';
    }
}
