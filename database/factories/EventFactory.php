<?php

namespace Database\Factories;

use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\Event>
 */
class EventFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $startTime = $this->faker->dateTimeBetween('+1 week', '+1 month');
        $endTime =  (clone $startTime)->modify('+' . $this->faker->numberBetween(1, 5) . ' hours');


        return [
            'name' => $this->faker->sentence(3),
            'description' => $this->faker->paragraphs(2, true),
            'location' => $this->faker->city() . ', ' . $this->faker->state(),
            'start_time' => $startTime,
            'end_time' => $endTime,
            'status' => $this->faker->randomElement(['upcoming', 'ongoing', 'completed', 'cancelled']),
            'created_by' => function () {
                $admin = User::whereIn('role', ['admin', 'super_admin'])->inRandomOrder()->first();

                return $admin->id ?? User::factory()->create(['role' => 'admin'])->id;
            },
        ];
    }
}
