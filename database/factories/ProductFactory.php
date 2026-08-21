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

    public function definition(): array
    {
        return [
            'name' => $this->faker->words(3, true),
            'description' => $this->faker->sentence(),
            'price' => $this->faker->numberBetween(500, 20000),
            'sku' => strtoupper($this->faker->unique()->bothify('SKU-####-???')),
            'stock_quantity' => 100,
            'is_active' => true,
            'requires_prescription' => false,
            'is_controlled_substance' => false,
            // Comfortably in date so shelf-life rules do not fire unless a test
            // explicitly asks for them.
            'expiry_date' => now()->addYears(2)->toDateString(),
        ];
    }

    /** A prescription-only medicine. */
    public function prescriptionOnly(): static
    {
        return $this->state(fn () => ['requires_prescription' => true]);
    }

    public function expired(): static
    {
        return $this->state(fn () => ['expiry_date' => now()->subDay()->toDateString()]);
    }

    public function outOfStock(): static
    {
        return $this->state(fn () => ['stock_quantity' => 0]);
    }
}
