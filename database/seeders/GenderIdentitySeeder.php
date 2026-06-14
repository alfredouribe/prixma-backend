<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class GenderIdentitySeeder extends Seeder
{
    public function run(): void
    {
        $identities = [
            ['slug' => 'no-binarie',    'label' => 'No binarie'],
            ['slug' => 'mujer-trans',   'label' => 'Mujer trans'],
            ['slug' => 'hombre-trans',  'label' => 'Hombre trans'],
            ['slug' => 'mujer-cis',     'label' => 'Mujer cis'],
            ['slug' => 'hombre-cis',    'label' => 'Hombre cis'],
            ['slug' => 'genero-fluido', 'label' => 'Género fluido'],
        ];

        foreach ($identities as $identity) {
            DB::table('gender_identities')->insertOrIgnore([
                'id'    => Str::uuid(),
                'slug'  => $identity['slug'],
                'label' => $identity['label'],
            ]);
        }
    }
}
