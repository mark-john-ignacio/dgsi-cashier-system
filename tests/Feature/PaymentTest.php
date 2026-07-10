<?php

namespace Tests\Feature;

use App\Exceptions\DuplicateOrNumber;
use App\Models\FeeStructure;
use App\Models\FeeType;
use App\Models\SchoolYear;
use App\Models\Student;
use App\Models\User;
use App\Services\PaymentService;
use App\Services\RegistrationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PaymentTest extends TestCase
{
    use RefreshDatabase;

    private $enrollment;

    private User $cashier;

    protected function setUp(): void
    {
        parent::setUp();
        $year = SchoolYear::factory()->create(['is_active' => true]);
        $structure = FeeStructure::create(['school_year_id' => $year->id, 'grade_level' => 'Grade 3']);
        $structure->items()->create(['fee_type_id' => FeeType::create(['name' => 'Tuition Fee'])->id, 'amount' => 25000]);
        $structure->items()->create(['fee_type_id' => FeeType::create(['name' => 'Books'])->id, 'amount' => 3500]);
        $this->enrollment = app(RegistrationService::class)->register(Student::factory()->create(), $year, 'Grade 3');
        $this->cashier = User::factory()->create(['role' => 'cashier']);
    }

    public function test_partial_payment_reduces_balance_and_allocates_oldest_first(): void
    {
        $payment = app(PaymentService::class)->record(
            $this->enrollment, 'OR-1001', '2026-08-01', 5000.00, 'cash', $this->cashier);

        $this->assertEqualsWithDelta(23500.00, $this->enrollment->fresh()->balance(), 0.001);
        $this->assertCount(1, $payment->allocations); // all 5000 to Tuition (oldest charge)
        $this->assertEqualsWithDelta(5000.00, (float) $payment->allocations->first()->amount, 0.001);
    }

    public function test_payment_spanning_multiple_charges_splits_allocation(): void
    {
        app(PaymentService::class)->record($this->enrollment, 'OR-1001', '2026-08-01', 26000.00, 'cash', $this->cashier);

        $payment = \App\Models\Payment::first();
        $this->assertCount(2, $payment->allocations); // 25000 tuition + 1000 books
        $this->assertEqualsWithDelta(2500.00, $this->enrollment->fresh()->balance(), 0.001);
    }

    public function test_duplicate_or_number_in_same_year_is_rejected_even_if_voided(): void
    {
        $svc = app(PaymentService::class);
        $p = $svc->record($this->enrollment, 'OR-1001', '2026-08-01', 1000.00, 'cash', $this->cashier);
        $p->forceFill(['voided_at' => now()])->save();

        $this->expectException(DuplicateOrNumber::class);
        $svc->record($this->enrollment, 'OR-1001', '2026-08-02', 500.00, 'cash', $this->cashier);
    }

    public function test_overpayment_records_unallocated_credit(): void
    {
        $payment = app(PaymentService::class)->record(
            $this->enrollment, 'OR-1001', '2026-08-01', 30000.00, 'cash', $this->cashier);

        $this->assertEqualsWithDelta(-1500.00, $this->enrollment->fresh()->balance(), 0.001);
        $this->assertEqualsWithDelta(1500.00, $payment->unallocatedAmount(), 0.001);
    }
}
