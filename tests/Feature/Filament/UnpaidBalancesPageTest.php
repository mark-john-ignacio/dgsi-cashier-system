<?php

namespace Tests\Feature\Filament;

use App\Filament\Pages\UnpaidBalances;
use App\Models\FeeStructure;
use App\Models\FeeType;
use App\Models\SchoolYear;
use App\Models\Student;
use App\Models\User;
use App\Services\PaymentService;
use App\Services\RegistrationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class UnpaidBalancesPageTest extends TestCase
{
    use RefreshDatabase;

    private User $cashier;

    private Student $paidStudent;

    private Student $partialStudent;

    private Student $grade4Student;

    protected function setUp(): void
    {
        parent::setUp();

        $year = SchoolYear::factory()->create(['is_active' => true]);

        $structure3 = FeeStructure::create(['school_year_id' => $year->id, 'grade_level' => 'Grade 3']);
        $structure3->items()->create(['fee_type_id' => FeeType::create(['name' => 'Tuition Fee'])->id, 'amount' => 10000]);

        $structure4 = FeeStructure::create(['school_year_id' => $year->id, 'grade_level' => 'Grade 4']);
        $structure4->items()->create(['fee_type_id' => FeeType::create(['name' => 'Misc Fee'])->id, 'amount' => 5000]);

        $this->cashier = User::factory()->create(['role' => 'cashier']);

        $registration = app(RegistrationService::class);
        $paymentService = app(PaymentService::class);

        // Fully paid enrollment — balance 0.00 — must NOT appear.
        $this->paidStudent = Student::factory()->create(['last_name' => 'Zpaid', 'first_name' => 'Fully']);
        $enrollmentPaid = $registration->register($this->paidStudent, $year, 'Grade 3');
        $paymentService->record($enrollmentPaid, 'OR-4001', now()->toDateString(), 10000.00, 'cash', $this->cashier);

        // Partial payment — balance 6000.00 — must appear.
        $this->partialStudent = Student::factory()->create(['last_name' => 'Apartial', 'first_name' => 'Some']);
        $enrollmentPartial = $registration->register($this->partialStudent, $year, 'Grade 3');
        $paymentService->record($enrollmentPartial, 'OR-4002', now()->toDateString(), 4000.00, 'cash', $this->cashier);

        // No payment at all, different grade — balance 5000.00 — must appear, used for grade filter test.
        $this->grade4Student = Student::factory()->create(['last_name' => 'Bgrade4', 'first_name' => 'Four']);
        $registration->register($this->grade4Student, $year, 'Grade 4');
    }

    public function test_page_lists_only_enrollments_with_positive_balance(): void
    {
        $this->actingAs($this->cashier);

        Livewire::test(UnpaidBalances::class)
            ->assertSee($this->partialStudent->full_name)
            ->assertSee($this->grade4Student->full_name)
            ->assertDontSee($this->paidStudent->full_name)
            ->assertSee(number_format(6000.00, 2))
            ->assertSee(number_format(5000.00, 2));
    }

    public function test_admin_can_also_view_the_page(): void
    {
        $this->actingAs(User::factory()->create(['role' => 'admin']));

        $this->get(UnpaidBalances::getUrl())->assertOk();
    }

    public function test_grade_level_filter_narrows_rows(): void
    {
        $this->actingAs($this->cashier);

        Livewire::test(UnpaidBalances::class, ['gradeLevel' => 'Grade 4'])
            ->assertSee($this->grade4Student->full_name)
            ->assertDontSee($this->partialStudent->full_name);
    }

    public function test_export_action_streams_csv_matching_report_controller_format(): void
    {
        $this->actingAs($this->cashier);

        $test = Livewire::test(UnpaidBalances::class)
            ->callAction('export');

        $test->assertFileDownloaded('unpaid-balances.csv');

        $content = base64_decode(data_get($test->effects, 'download.content'));
        $lines = preg_split('/\r\n|\n/', trim($content));

        $expectedHeaderLine = $this->csvLine(['Student No.', 'Student', 'Grade', 'Section', 'Assessed', 'Paid', 'Balance', 'Promissory (pending)']);
        $this->assertSame($expectedHeaderLine, $lines[0]);

        // Header + 2 unpaid rows (paid enrollment excluded) = 3 lines.
        $this->assertCount(3, $lines);

        $body = implode("\n", array_slice($lines, 1));
        $this->assertStringContainsString($this->partialStudent->student_no, $body);
        $this->assertStringContainsString($this->grade4Student->student_no, $body);
        $this->assertStringNotContainsString($this->paidStudent->student_no, $body);
    }

    private function csvLine(array $fields): string
    {
        $stream = fopen('php://memory', 'w+');
        fputcsv($stream, $fields);
        rewind($stream);
        $line = fgets($stream);
        fclose($stream);

        return rtrim($line, "\r\n");
    }
}
