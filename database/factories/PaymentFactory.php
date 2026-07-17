<?php

namespace Database\Factories;

use App\Models\Enrollment;
use App\Models\Payment;
use App\Models\SchoolYear;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Payment>
 */
class PaymentFactory extends Factory
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
            'school_year_id' => SchoolYear::factory(),
            'or_number' => fake()->unique()->numerify('OR-####'),
            'payment_date' => now()->toDateString(),
            'amount' => 100,
            'method' => 'cash',
            'received_by' => User::factory(),
        ];
    }
}
