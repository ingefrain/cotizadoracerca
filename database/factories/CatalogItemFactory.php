<?php

namespace Database\Factories;

use App\Models\Catalog;
use App\Models\CatalogItem;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<CatalogItem> */
class CatalogItemFactory extends Factory
{
    public function definition(): array
    {
        return [
            'catalog_id' => Catalog::factory(),
            'name' => fake()->words(3, true),
            'slug' => fake()->unique()->slug(),
            'is_active' => true,
        ];
    }

    public function inactive(): static
    {
        return $this->state(['is_active' => false]);
    }
}
