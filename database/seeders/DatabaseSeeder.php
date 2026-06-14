<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    public function run(): void
    {
        $this->call([
            GenderIdentitySeeder::class,
            OrientationSeeder::class,
            PronounSeeder::class,
            InterestSeeder::class,
        ]);
    }
}
