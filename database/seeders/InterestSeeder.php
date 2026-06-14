<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class InterestSeeder extends Seeder
{
    public function run(): void
    {
        $interests = [
            // culture
            ['slug' => 'musica',       'label' => 'Música',       'category' => 'culture'],
            ['slug' => 'cine',         'label' => 'Cine',         'category' => 'culture'],
            ['slug' => 'arte-visual',  'label' => 'Arte visual',  'category' => 'culture'],
            ['slug' => 'teatro',       'label' => 'Teatro',       'category' => 'culture'],
            ['slug' => 'literatura',   'label' => 'Literatura',   'category' => 'culture'],
            ['slug' => 'fotografia',   'label' => 'Fotografía',   'category' => 'culture'],
            // activism
            ['slug' => 'activismo-lgbtq',  'label' => 'Activismo LGBTQ+',  'category' => 'activism'],
            ['slug' => 'feminismo',        'label' => 'Feminismo',          'category' => 'activism'],
            ['slug' => 'derechos-humanos', 'label' => 'Derechos humanos',   'category' => 'activism'],
            ['slug' => 'voluntariado',     'label' => 'Voluntariado',       'category' => 'activism'],
            // lifestyle
            ['slug' => 'viajes',       'label' => 'Viajes',       'category' => 'lifestyle'],
            ['slug' => 'gastronomia',  'label' => 'Gastronomía',  'category' => 'lifestyle'],
            ['slug' => 'fitness',      'label' => 'Fitness',      'category' => 'lifestyle'],
            ['slug' => 'gaming',       'label' => 'Gaming',       'category' => 'lifestyle'],
            ['slug' => 'yoga',         'label' => 'Yoga',         'category' => 'lifestyle'],
            ['slug' => 'cafes',        'label' => 'Cafés',        'category' => 'lifestyle'],
            // tech
            ['slug' => 'tecnologia',     'label' => 'Tecnología',     'category' => 'tech'],
            ['slug' => 'ciencia',        'label' => 'Ciencia',        'category' => 'tech'],
            ['slug' => 'diseno',         'label' => 'Diseño',         'category' => 'tech'],
            ['slug' => 'emprendimiento', 'label' => 'Emprendimiento', 'category' => 'tech'],
        ];

        foreach ($interests as $interest) {
            DB::table('interests')->insertOrIgnore([
                'id'       => Str::uuid(),
                'slug'     => $interest['slug'],
                'label'    => $interest['label'],
                'category' => $interest['category'],
            ]);
        }
    }
}
