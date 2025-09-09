<?php

namespace Database\Seeders;

use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

class UserSeeder extends Seeder
{
    public function run()
    {
        User::create([
            'first_name' => 'Super',
            'last_name' => 'Admin',
            'email' => 'superadmin@test.com',
            'password' => Hash::make('12301230'),
            'role' => 'super_admin',
            'address' => '123 Admin Street',
            'gender' => 'male',
            'religion' => 'Christian',
            'contact_number' => '+1234567890',
        ]);

        User::create([
            'first_name' => 'Admin',
            'last_name' => 'User',
            'email' => 'admin@test.com',
            'password' => Hash::make('12301230'),
            'role' => 'admin',
            'address' => '456 Admin Avenue',
            'gender' => 'female',
            'religion' => 'Catholic',
            'contact_number' => '+0987654321',
        ]);
    }
}
