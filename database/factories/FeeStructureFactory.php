<?php

namespace Database\Factories;

use App\Models\FeeStructure;
use App\Models\SchoolYear;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<FeeStructure>
 */
class FeeStructureFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'school_year_id' => SchoolYear::factory(),
            'grade_level' => 'Grade '.fake()->numberBetween(1, 12),
        ];
    }
}
