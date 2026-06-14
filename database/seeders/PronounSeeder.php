<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class PronounSeeder extends Seeder
{
    public function run(): void
    {
        $pronouns = [
            ['slug' => 'ella-la',    'label' => 'ella / la'],
            ['slug' => 'el-lo',      'label' => 'él / lo'],
            ['slug' => 'elle-le',    'label' => 'elle / le'],
            ['slug' => 'elles-les',  'label' => 'elles / les'],
            ['slug' => 'they-them',  'label' => 'they / them'],
            ['slug' => 'ze-zir',     'label' => 'ze / zir'],
        ];

        foreach ($pronouns as $pronoun) {
            DB::table('pronouns')->insertOrIgnore([
                'id'    => Str::uuid(),
                'slug'  => $pronoun['slug'],
                'label' => $pronoun['label'],
            ]);
        }
    }
}
