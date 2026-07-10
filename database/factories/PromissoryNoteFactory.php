<?php

namespace Database\Factories;

use App\Models\Enrollment;
use App\Models\PromissoryNote;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<PromissoryNote>
 */
class PromissoryNoteFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'enrollment_id' => Enrollment::factory(),
            'amount' => fake()->randomFloat(2, 500, 10000),
            'due_date' => fake()->dateTimeBetween('now', '+2 months'),
            'status' => 'pending',
        ];
    }
}
