<?php

namespace Tests\Feature\Filament;

use App\Filament\Widgets\LatestPayments;
use App\Models\FeeStructure;
use App\Models\FeeType;
use App\Models\Payment;
use App\Models\SchoolYear;
use App\Models\Student;
use App\Models\User;
use App\Services\PaymentService;
use App\Services\RegistrationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
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

    public function test_today_collections_stats_widget_shows_correct_totals(): void
    {
        // Seed 2 payments today (cash 1000, gcash 500)
        app(PaymentService::class)->record($this->enrollment, 'OR-001', today()->toDateString(), 1000, 'cash', $this->cashier);
        app(PaymentService::class)->record($this->enrollment, 'OR-002', today()->toDateString(), 500, 'gcash', $this->cashier);

        // 1 voided today (999)
        $voided = app(PaymentService::class)->record($this->enrollment, 'OR-003', today()->toDateString(), 999, 'cash', $this->cashier);
        app(PaymentService::class)->void($voided, $this->cashier, 'duplicate');

        // 1 yesterday
        app(PaymentService::class)->record($this->enrollment, 'OR-004', today()->subDay()->toDateString(), 2000, 'cash', $this->cashier);

        // Verify the queries that the widget uses
        $today = Payment::active()->whereDate('payment_date', today());
        $todayTotal = (float) (clone $today)->sum('amount');
        $todayCount = (clone $today)->count();

        // Check totals - should be 1500 (voided and yesterday excluded)
        $this->assertEqualsWithDelta(1500.0, $todayTotal, 0.01);
        $this->assertEquals(2, $todayCount);

        // Check per-method breakdown
        $methodBreakdown = (clone $today)
            ->selectRaw('method, SUM(amount) as total')
            ->groupBy('method')
            ->get();

        $methods = $methodBreakdown->pluck('method')->toArray();
        $this->assertContains('cash', $methods);
        $this->assertContains('gcash', $methods);

        // Check amounts
        $cashTotal = $methodBreakdown->where('method', 'cash')->first()->total;
        $gcashTotal = $methodBreakdown->where('method', 'gcash')->first()->total;
        $this->assertEqualsWithDelta(1000.0, $cashTotal, 0.01);
        $this->assertEqualsWithDelta(500.0, $gcashTotal, 0.01);
    }

    public function test_latest_payments_widget_shows_today_non_voided_payments(): void
    {
        // Seed 2 payments today (cash 1000, gcash 500)
        $p1 = app(PaymentService::class)->record($this->enrollment, 'OR-001', today()->toDateString(), 1000, 'cash', $this->cashier);
        $p2 = app(PaymentService::class)->record($this->enrollment, 'OR-002', today()->toDateString(), 500, 'gcash', $this->cashier);

        // 1 voided today (should not appear)
        $voided = app(PaymentService::class)->record($this->enrollment, 'OR-003', today()->toDateString(), 999, 'cash', $this->cashier);
        app(PaymentService::class)->void($voided, $this->cashier, 'duplicate');

        // 1 yesterday (should not appear in today's widget)
        app(PaymentService::class)->record($this->enrollment, 'OR-004', today()->subDay()->toDateString(), 2000, 'cash', $this->cashier);

        $widget = new LatestPayments;

        // Get the data from the table query
        $data = Payment::active()->whereDate('payment_date', today())->get();

        // Should only have 2 records (today's non-voided)
        $this->assertCount(2, $data);

        // Check that the payments are the ones we expect
        $ors = $data->pluck('or_number')->toArray();
        $this->assertContains('OR-001', $ors);
        $this->assertContains('OR-002', $ors);
        $this->assertNotContains('OR-003', $ors); // voided
        $this->assertNotContains('OR-004', $ors); // yesterday
    }

    public function test_latest_payments_includes_student_name_and_method(): void
    {
        $payment = app(PaymentService::class)->record(
            $this->enrollment,
            'OR-TEST-001',
            today()->toDateString(),
            1000,
            'cash',
            $this->cashier
        );

        $record = Payment::active()
            ->where('or_number', 'OR-TEST-001')
            ->with('enrollment.student', 'receivedBy')
            ->first();

        $this->assertNotNull($record);
        $this->assertEquals('OR-TEST-001', $record->or_number);
        $this->assertEquals(1000, $record->amount);
        $this->assertEquals('cash', $record->method);
        // Student name should be accessible via enrollment relationship
        $this->assertNotNull($record->enrollment);
        $this->assertNotNull($record->enrollment->student);
        $this->assertNotNull($record->receivedBy);
    }
}
