<?php

namespace Database\Seeders;

use App\Models\FeeType;
use Illuminate\Database\Seeder;

class FeeTypeSeeder extends Seeder
{
    public function run(): void
    {
        foreach (['Tuition Fee', 'Registration Fee', 'Books', 'Uniform', 'Miscellaneous'] as $name) {
            FeeType::firstOrCreate(['name' => $name]);
        }
    }
}
