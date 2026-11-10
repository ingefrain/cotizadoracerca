<?php

namespace App\Imports;

use RuntimeException;

final class ImportException extends RuntimeException
{
    public function __construct(public readonly string $reason, string $message, public readonly ?int $row = null)
    {
        parent::__construct($message);
    }
}
