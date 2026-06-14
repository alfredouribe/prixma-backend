<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class OrientationSeeder extends Seeder
{
    public function run(): void
    {
        $orientations = [
            ['slug' => 'pansexual',  'label' => 'Pansexual'],
            ['slug' => 'bisexual',   'label' => 'Bisexual'],
            ['slug' => 'lesbiana',   'label' => 'Lesbiana'],
            ['slug' => 'gay',        'label' => 'Gay'],
            ['slug' => 'asexual',    'label' => 'Asexual'],
            ['slug' => 'queer',      'label' => 'Queer'],
        ];

        foreach ($orientations as $orientation) {
            DB::table('orientations')->insertOrIgnore([
                'id'    => Str::uuid(),
                'slug'  => $orientation['slug'],
                'label' => $orientation['label'],
            ]);
        }
    }
}
