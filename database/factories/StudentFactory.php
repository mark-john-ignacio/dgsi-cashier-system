<?php

namespace Database\Factories;

use App\Models\Student;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Student>
 */
class StudentFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'student_no' => fake()->unique()->numerify('DGS-####'),
            'first_name' => fake()->firstName(),
            'last_name' => fake()->lastName(),
            'guardian_name' => fake()->name(),
            'guardian_contact' => fake()->numerify('09#########'),
            'status' => 'enrolled',
        ];
    }
}
