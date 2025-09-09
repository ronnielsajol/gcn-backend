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
            'Church/Ministry',
            'Family/Community',
            'Government',
            'Education',
            'Business/Economics',
            'Media/Arts/Entertainment',
            'Every Nation Campus (ENC)'
        ];

        foreach ($spheres as $sphere) {
            DB::table('spheres')->updateOrInsert(
                ['slug' => Str::slug($sphere, '-')],
                ['name' => $sphere]
            );
        }
    }
}
