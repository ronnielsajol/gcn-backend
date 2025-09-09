<?php

namespace Database\Seeders;

// use Illuminate\Database\Console\Seeds\WithoutModelEvents;

use App\Models\Event;
use App\Models\User;
use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    /**
     * Seed the application's database.
     */
    public function run(): void
    {
        $this->call(UserSeeder::class);
        $this->call(SpheresTableSeeder::class);

        $regularUsers = User::where('role', 'user')->get();

        if ($regularUsers->isNotEmpty()) {
            Event::factory(5)->afterCreating(function (Event $event) use ($regularUsers) {
                $attendees = $regularUsers->random(rand(5, 10));
                $event->users()->attach($attendees);
            })->create();
        }
    }
}
