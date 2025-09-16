<?php

namespace Database\Factories;

use App\Models\Sphere;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\Sphere>
 */
class SphereFactory extends Factory
{
  protected $model = Sphere::class;

  /**
   * Define the model's default state.
   *
   * @return array<string, mixed>
   */
  public function definition(): array
  {
    $name = $this->faker->unique()->randomElement([
      'Church/Ministry',
      'Family/Community',
      'Government',
      'Education',
      'Business/Economics',
      'Media/Arts/Entertainment',
      'Every Nation Campus (ENC)',
      'Healthcare',
      'Technology',
      'Sports & Recreation'
    ]);

    return [
      'name' => $name,
      'slug' => Str::slug($name),
    ];
  }
}
