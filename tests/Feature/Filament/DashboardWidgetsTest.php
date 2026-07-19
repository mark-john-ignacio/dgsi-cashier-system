<?php

namespace Tests\Feature\Filament;

use App\Filament\Widgets\LatestPayments;
use App\Filament\Widgets\TodayCollectionsStats;
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

class DashboardWidgetsTest extends TestCase
{
    use RefreshDatabase;

    private $enrollment;

    private User $cashier;

    protected function setUp(): void
    {
        parent::setUp();
        $year = SchoolYear::factory()->create(['is_active' => true]);
        $s = FeeStructure::create(['school_year_id' => $year->id, 'grade_level' => 'Grade 3']);
        $s->items()->create(['fee_type_id' => FeeType::create(['name' => 'Tuition Fee'])->id, 'amount' => 28500]);
        $this->enrollment = app(RegistrationService::class)->register(Student::factory()->create(), $year, 'Grade 3');
        $this->cashier = User::factory()->create(['role' => 'cashier']);
    }

    /**
     * Seeds: 2 live payments today (cash 1000, gcash 500), 1 voided today (999), 1 yesterday (2000).
     */
    private function seedPayments(): array
    {
        $svc = app(PaymentService::class);

        $cash = $svc->record($this->enrollment, 'OR-001', today()->toDateString(), 1000, 'cash', $this->cashier);
        $gcash = $svc->record($this->enrollment, 'OR-002', today()->toDateString(), 500, 'gcash', $this->cashier);

        $voided = $svc->record($this->enrollment, 'OR-003', today()->toDateString(), 999, 'cash', $this->cashier);
        $svc->void($voided, $this->cashier, 'duplicate');

        $yesterday = $svc->record($this->enrollment, 'OR-004', today()->subDay()->toDateString(), 2000, 'cash', $this->cashier);

        return [$cash, $gcash, $voided, $yesterday];
    }

    public function test_stats_widget_renders_total_count_and_per_method_split(): void
    {
        $this->seedPayments();

        Livewire::actingAs($this->cashier)
            ->test(TodayCollectionsStats::class)
            ->assertSee('Total today')
            ->assertSee('₱1,500.00')
            ->assertSee('Payments count')
            ->assertSee('cash 1,000.00')
            ->assertSee('gcash 500.00')
            // Wrong totals if voided (2,499.00) or yesterday (3,500.00) leaked in
            ->assertDontSee('2,499.00')
            ->assertDontSee('3,500.00');
    }

    public function test_stats_widget_count_excludes_voided_and_yesterday_payments(): void
    {
        $this->seedPayments();

        $html = Livewire::actingAs($this->cashier)
            ->test(TodayCollectionsStats::class)
            ->html();

        // The "Payments count" stat value must be exactly 2 (not 3 with voided, 4 with yesterday)
        $this->assertMatchesRegularExpression('/Payments count.*?>\s*2\s*</s', $html);
    }

    public function test_table_widget_lists_todays_live_payments_only(): void
    {
        [$cash, $gcash, $voided, $yesterday] = $this->seedPayments();

        Livewire::actingAs($this->cashier)
            ->test(LatestPayments::class)
            ->assertCanSeeTableRecords([$cash, $gcash])
            ->assertCanNotSeeTableRecords([$voided, $yesterday])
            ->assertSee('OR-001')
            ->assertSee('OR-002')
            ->assertSee($this->enrollment->student->name)
            ->assertSee('₱1,000.00')
            ->assertSee('₱500.00')
            ->assertSee($this->cashier->name);
    }

    public function test_table_widget_shows_latest_ten_only(): void
    {
        $svc = app(PaymentService::class);
        $payments = [];
        foreach (range(1, 11) as $i) {
            $payments[] = $svc->record($this->enrollment, "OR-1{$i}", today()->toDateString(), 10, 'cash', $this->cashier);
        }

        Livewire::actingAs($this->cashier)
            ->test(LatestPayments::class)
            ->assertCanSeeTableRecords(array_slice($payments, 1))
            ->assertCanNotSeeTableRecords([$payments[0]]);
    }
}
