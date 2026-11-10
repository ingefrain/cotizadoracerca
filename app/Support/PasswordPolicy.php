<?php

namespace App\Support;

use Illuminate\Validation\Rules\Password;

class PasswordPolicy
{
    /** @return array<int, mixed> */
    public static function rules(string $presence = 'required'): array
    {
        $presenceRule = match ($presence) {
            'required' => 'required',
            'nullable' => 'nullable',
            default => throw new \InvalidArgumentException('Presence must be required or nullable.'),
        };
        $minimum = max(1, (int) config('password_policy.minimum_length', 8));
        $maximum = max($minimum, (int) config('password_policy.maximum_length', 72));

        $rule = Password::min($minimum);
        if (config('password_policy.require_mixed_case', true)) {
            $rule->mixedCase();
        }
        if (config('password_policy.require_number', true)) {
            $rule->numbers();
        }

        return [$presenceRule, 'string', 'max:'.$maximum, 'confirmed', $rule];
    }

    public static function minimumLength(): int
    {
        return max(1, (int) config('password_policy.minimum_length', 8));
    }

    public static function maximumLength(): int
    {
        return max(self::minimumLength(), (int) config('password_policy.maximum_length', 72));
    }

    public static function summary(): string
    {
        $requirements = ['Mínimo '.self::minimumLength().' caracteres'];
        if (config('password_policy.require_mixed_case', true)) {
            $requirements[] = 'mayúscula y minúscula';
        }
        if (config('password_policy.require_number', true)) {
            $requirements[] = 'un número';
        }

        return implode(', ', $requirements).'. Máximo '.self::maximumLength().' caracteres.';
    }
}
