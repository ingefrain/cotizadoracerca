<?php

namespace Database\Factories;

use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

class FormattedDocumentFactory extends Factory
{
    public function definition(): array
    {
        return [
            'title' => 'Documento de prueba',
            'body_markdown' => "# Documento\n\nContenido ficticio.\n",
            'notes' => null,
            'created_by' => User::factory(),
        ];
    }
}
