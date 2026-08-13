<?php

namespace Database\Factories;

use App\Models\Service;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<Service>
 */
class ServiceFactory extends Factory
{
    protected $model = Service::class;

    /**
     * service_category_id stays null (the column is nullable) so a test needing a
     * service does not implicitly create a category it never asserts on.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $name = Str::title(fake()->unique()->words(2, true)).' Transport';
        $basePriceCents = fake()->numberBetween(150, 400) * 100;
        $transitMin = fake()->numberBetween(1, 5);

        return [
            'service_category_id' => null,
            'name' => $name,
            'slug' => Str::slug($name),
            'short_description' => fake()->sentence(14),
            'description' => '<p>'.fake()->paragraph(6).'</p>',
            'icon' => 'car-outline',
            'hero_image_path' => null,
            'base_price_cents' => $basePriceCents,
            'price_per_mile_cents' => fake()->numberBetween(45, 130),
            'min_price_cents' => $basePriceCents * 2,
            'currency' => 'USD',
            'transit_days_min' => $transitMin,
            'transit_days_max' => $transitMin + fake()->numberBetween(1, 5),
            'is_featured' => false,
            'is_active' => true,
            'sort_order' => fake()->numberBetween(0, 100),
        ];
    }

    public function featured(): static
    {
        return $this->state(fn (array $attributes) => [
            'is_featured' => true,
        ]);
    }

    public function inactive(): static
    {
        return $this->state(fn (array $attributes) => [
            'is_active' => false,
        ]);
    }

    /**
     * rating_avg / rating_count are not fillable on the model, but factories run
     * unguarded -- so this is the sanctioned way for a test to stage an aggregate
     * without going through the ReviewObserver rebuild.
     */
    public function rated(float $average, int $count): static
    {
        return $this->state(fn (array $attributes) => [
            'rating_avg' => $average,
            'rating_count' => $count,
        ]);
    }
}
