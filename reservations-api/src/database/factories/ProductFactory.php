<?php

namespace Database\Factories;

use App\Models\Product;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Product>
 */
class ProductFactory extends Factory
{
    protected $model = Product::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'name' => ucfirst(fake()->unique()->words(2, true)),
            'stock' => fake()->numberBetween(0, 100),
        ];
    }

    public function withStock(int $stock): static
    {
        return $this->state(fn () => ['stock' => $stock]);
    }
}
