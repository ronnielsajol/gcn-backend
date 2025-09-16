<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class SpheresTableSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        $spheres = [
            'Church/ministry',
            'Family/community',
            'Government/law',
            'Education/sports',
            'Business/economics',
            'Media/arts/entertainment',
            'Medicine/science/technology',
        ];

        foreach ($spheres as $sphere) {
            DB::table('spheres')->updateOrInsert(
                ['slug' => Str::slug($sphere, '-')],
                ['name' => $sphere]
            );
        }
    }
}
