<?php

namespace Tests\Feature\Filament;

use App\Filament\Pages\DailyCollections;
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

class DailyCollectionsPageTest extends TestCase
{
    use RefreshDatabase;

    private const TARGET_DATE = '2026-07-15';

    private const OTHER_DATE = '2026-07-16';

    private User $cashier1;

    private User $cashier2;

    protected function setUp(): void
    {
        parent::setUp();

        $year = SchoolYear::factory()->create(['is_active' => true]);
        $structure = FeeStructure::create(['school_year_id' => $year->id, 'grade_level' => 'Grade 3']);
        $structure->items()->create(['fee_type_id' => FeeType::create(['name' => 'Tuition Fee'])->id, 'amount' => 50000]);

        $this->cashier1 = User::factory()->create(['role' => 'cashier', 'name' => 'Cashier One']);
        $this->cashier2 = User::factory()->create(['role' => 'cashier', 'name' => 'Cashier Two']);

        $registration = app(RegistrationService::class);
        $paymentService = app(PaymentService::class);

        // Three active payments on the target date, spread across two cashiers and two methods.
        $enrollmentA = $registration->register(Student::factory()->create(), $year, 'Grade 3');
        $paymentService->record($enrollmentA, 'OR-2001', self::TARGET_DATE, 1000.00, 'cash', $this->cashier1);

        $enrollmentB = $registration->register(Student::factory()->create(), $year, 'Grade 3');
        $paymentService->record($enrollmentB, 'OR-2002', self::TARGET_DATE, 500.00, 'gcash', $this->cashier2);

        $enrollmentC = $registration->register(Student::factory()->create(), $year, 'Grade 3');
        $paymentService->record($enrollmentC, 'OR-2003', self::TARGET_DATE, 300.00, 'cash', $this->cashier1);

        // One voided payment on the target date — must be excluded from totals.
        $enrollmentD = $registration->register(Student::factory()->create(), $year, 'Grade 3');
        $voided = $paymentService->record($enrollmentD, 'OR-2004', self::TARGET_DATE, 9999.00, 'cash', $this->cashier1);
        $paymentService->void($voided, $this->cashier1, 'Test void');

        // One payment on a different date — must not appear in target-date totals or rows.
        $enrollmentE = $registration->register(Student::factory()->create(), $year, 'Grade 3');
        $paymentService->record($enrollmentE, 'OR-2005', self::OTHER_DATE, 777.00, 'bank', $this->cashier2);
    }

    public function test_page_shows_rows_and_totals_for_selected_date(): void
    {
        $this->actingAs(User::factory()->create(['role' => 'cashier']));

        Livewire::test(DailyCollections::class, ['date' => self::TARGET_DATE])
            ->assertSee('OR-2001')
            ->assertSee('OR-2002')
            ->assertSee('OR-2003')
            ->assertDontSee('OR-2005')
            // Grand total excludes the voided 9999.00 payment: 1000 + 500 + 300 = 1800.
            ->assertSee(number_format(1800.00, 2))
            // Per-cashier subtotals: Cashier One = 1000 + 300 = 1300; Cashier Two = 500.
            ->assertSee('Cashier One')
            ->assertSee(number_format(1300.00, 2))
            ->assertSee('Cashier Two')
            ->assertSee(number_format(500.00, 2))
            // Per-method subtotals: cash = 1000 + 300 = 1300; gcash = 500.
            ->assertSee('CASH')
            ->assertSee('GCASH');
    }

    public function test_admin_can_also_view_the_page(): void
    {
        $this->actingAs(User::factory()->create(['role' => 'admin']));

        $this->get(DailyCollections::getUrl(['date' => self::TARGET_DATE]))->assertOk();
    }

    public function test_export_action_streams_csv_matching_report_controller_format(): void
    {
        $this->actingAs(User::factory()->create(['role' => 'cashier']));

        $test = Livewire::test(DailyCollections::class, ['date' => self::TARGET_DATE])
            ->callAction('export');

        $test->assertFileDownloaded('daily-collections-'.self::TARGET_DATE.'.csv');

        $content = base64_decode(data_get($test->effects, 'download.content'));
        $lines = preg_split('/\r\n|\n/', trim($content));

        $expectedHeaderLine = $this->csvLine(['OR No.', 'Student', 'Method', 'Amount', 'Status', 'Cashier']);
        $this->assertSame($expectedHeaderLine, $lines[0]);

        // Header + 3 active rows + 1 voided row on the target date = 5 lines (other-date payment excluded).
        $this->assertCount(5, $lines);

        $body = implode("\n", array_slice($lines, 1));
        $this->assertStringContainsString('OR-2001', $body);
        $this->assertStringContainsString('OR-2002', $body);
        $this->assertStringContainsString('OR-2003', $body);
        $this->assertStringContainsString('OR-2004', $body);
        $this->assertStringContainsString('VOIDED', $body);
        $this->assertStringNotContainsString('OR-2005', $body);
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
