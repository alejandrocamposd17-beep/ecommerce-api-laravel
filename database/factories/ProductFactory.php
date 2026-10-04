<?php

namespace Database\Factories;

use App\Models\Product;
use Illuminate\Database\Eloquent\Factories\Factory;

class ProductFactory extends Factory
{
    protected $model = Product::class;

    public function definition(): array
    {
        return [
            'name' => ucfirst($this->faker->words(3, true)),
            'description' => $this->faker->sentence(12),
            'price' => $this->faker->randomFloat(2, 5, 1500),
            'stock' => $this->faker->numberBetween(0, 100),
            'category' => $this->faker->randomElement(['Computadoras', 'Accesorios', 'Audio', 'Celulares']),
            'image_url' => 'https://picsum.photos/seed/'.$this->faker->uuid.'/600/400',
            'is_active' => true,
        ];
    }
}
