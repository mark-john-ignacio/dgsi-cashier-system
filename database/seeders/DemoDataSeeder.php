<?php

namespace Database\Seeders;

use App\Models\FeeStructure;
use App\Models\FeeType;
use App\Models\SchoolYear;
use App\Models\Student;
use App\Models\User;
use App\Services\PaymentService;
use App\Services\RegistrationService;
use Illuminate\Database\Seeder;

class DemoDataSeeder extends Seeder
{
    public function run(): void
    {
        $this->call([AdminUserSeeder::class, FeeTypeSeeder::class]);

        $year = SchoolYear::firstOrCreate(['name' => '2026-2027']);
        $year->activate();

        $cashier = User::firstOrCreate(
            ['email' => 'cashier@dgsi.local'],
            ['name' => 'Demo Cashier', 'password' => bcrypt('password'), 'role' => 'cashier']);

        $tuition = FeeType::where('name', 'Tuition Fee')->first();
        $misc = FeeType::where('name', 'Miscellaneous')->first();

        foreach (range(1, 12) as $grade) {
            $structure = FeeStructure::firstOrCreate(
                ['school_year_id' => $year->id, 'grade_level' => "Grade {$grade}"]);
            if ($structure->items()->count() === 0) {
                $structure->items()->create(['fee_type_id' => $tuition->id, 'amount' => 20000 + $grade * 1000]);
                $structure->items()->create(['fee_type_id' => $misc->id, 'amount' => 3000]);
            }
        }

        $reg = app(RegistrationService::class);
        $pay = app(PaymentService::class);
        $or = 1000;

        foreach (range(1, 12) as $grade) {
            foreach (Student::factory()->count(5)->create() as $i => $student) {
                $enrollment = $reg->register($student, $year, "Grade {$grade}");
                match ($i % 4) {
                    0 => $pay->record($enrollment, 'OR-'.$or++, now()->toDateString(),
                        $enrollment->totalAssessed(), 'cash', $cashier), // fully paid
                    1 => $pay->record($enrollment, 'OR-'.$or++, now()->subDays(7)->toDateString(),
                        5000, 'gcash', $cashier), // partial
                    2 => $enrollment->promissoryNotes()->create([
                        'amount' => 4000, 'due_date' => now()->addMonth(),
                        'notes' => 'Promised after payday', 'status' => 'pending',
                    ]),
                    default => null, // unpaid
                };
            }
        }
    }
}
