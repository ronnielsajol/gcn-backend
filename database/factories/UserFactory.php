<?php

namespace Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\User>
 */
class UserFactory extends Factory
{
    /**
     * The current password being used by the factory.
     */
    protected static ?string $password;

    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'first_name' => fake()->firstName(),
            'last_name' => fake()->lastName(),
            'middle_initial' => fake()->optional()->randomLetter(),
            'email' => fake()->unique()->safeEmail(),
            'contact_number' => fake()->numerify('+639#########'),
            'mobile_number' => fake()->numerify('+639#########'),
            'role' => 'user',
            'email_verified_at' => now(),
            'is_active' => true,
            'title' => fake()->optional()->randomElement(['Mr.', 'Ms.', 'Mrs.', 'Dr.', 'Rev.']),
            'home_address' => fake()->address(),
            'church_name' => fake()->optional()->company() . ' Church',
            'church_address' => fake()->optional()->address(),
            'working_or_student' => fake()->randomElement(['working', 'student']),
            'vocation_work_sphere' => fake()->optional()->jobTitle(),
            'mode_of_payment' => fake()->optional()->randomElement(['gcash', 'bank', 'cash', 'other']),
            'proof_of_payment_path' => fake()->optional()->filePath(),
            'proof_of_payment_url' => fake()->optional()->url(),
            'notes' => fake()->optional()->sentence(),
            'reference_number' => fake()->optional()->numerify('REF-#####'),
            'reconciled' => fake()->boolean(20), // 20% chance of being true
            'finance_checked' => fake()->boolean(30),
            'email_confirmed' => fake()->boolean(70),
            'attendance' => fake()->boolean(40),
            'id_issued' => fake()->boolean(50),
            'book_given' => fake()->boolean(45),
            'remember_token' => Str::random(10),
        ];
    }

    /**
     * Indicate that the model's email address should be unverified.
     */
    public function unverified(): static
    {
        return $this->state(fn(array $attributes) => [
            'email_verified_at' => null,
        ]);
    }
}
