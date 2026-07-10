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

/**
 * Demo dataset for staging and sales demos. Never run this against production.
 *
 * Student rows are built by hand rather than with model factories: factories call
 * fake(), and fakerphp/faker is a require-dev package absent from the production
 * image (`composer install --no-dev`). Re-running is a no-op once students exist.
 */
class DemoDataSeeder extends Seeder
{
    private const FIRST_NAMES = [
        'Maria', 'Jose', 'Ana', 'Juan', 'Rosa', 'Pedro', 'Liza', 'Mark', 'Grace', 'Paulo',
        'Angel', 'Nico', 'Bea', 'Rafael', 'Carmen', 'Diego', 'Ella', 'Miguel', 'Sofia', 'Ramon',
    ];

    private const LAST_NAMES = [
        'Santos', 'Reyes', 'Dela Cruz', 'Bautista', 'Ocampo', 'Garcia', 'Mendoza', 'Torres',
        'Aquino', 'Ramos', 'Castillo', 'Flores', 'Villanueva', 'Rivera', 'Gonzales',
        'Domingo', 'Salazar', 'Navarro', 'Padilla', 'Cruz',
    ];

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

        if (Student::query()->exists()) {
            return;
        }

        $reg = app(RegistrationService::class);
        $pay = app(PaymentService::class);
        $or = 1000;
        $n = 0;

        foreach (range(1, 12) as $grade) {
            foreach (range(0, 4) as $i) {
                $n++;
                $first = self::FIRST_NAMES[$n % count(self::FIRST_NAMES)];
                $last = self::LAST_NAMES[($n * 7) % count(self::LAST_NAMES)];

                $student = Student::create([
                    'student_no' => sprintf('DGS-%04d', $n),
                    'first_name' => $first,
                    'last_name' => $last,
                    'guardian_name' => self::FIRST_NAMES[($n * 3) % count(self::FIRST_NAMES)].' '.$last,
                    'guardian_contact' => '09'.str_pad((string) (170000000 + $n), 9, '0', STR_PAD_LEFT),
                    'status' => 'enrolled',
                ]);

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
