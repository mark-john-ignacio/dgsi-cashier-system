<?php

namespace Tests\Feature;

use App\Exceptions\DuplicateOrNumber;
use App\Models\FeeStructure;
use App\Models\FeeType;
use App\Models\Payment;
use App\Models\SchoolYear;
use App\Models\Student;
use App\Models\User;
use App\Services\PaymentService;
use App\Services\RegistrationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
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

        $payment = Payment::first();
        $this->assertCount(2, $payment->allocations); // 25000 tuition + 1000 books
        $this->assertEqualsWithDelta(2500.00, $this->enrollment->fresh()->balance(), 0.001);
    }

    public function test_or_collision_that_beats_the_precheck_still_raises_duplicate_or_number(): void
    {
        // Simulate losing the race: a concurrent cashier inserts the same OR
        // number in the window between the exists() pre-check and our insert.
        // The creating hook fires exactly in that window.
        $inserted = false;
        Payment::creating(function () use (&$inserted) {
            if (! $inserted) {
                $inserted = true;
                DB::table('payments')->insert([
                    'enrollment_id' => $this->enrollment->id,
                    'school_year_id' => $this->enrollment->school_year_id,
                    'or_number' => 'OR-2001',
                    'payment_date' => '2026-08-01',
                    'amount' => 100.00,
                    'method' => 'cash',
                    'received_by' => $this->cashier->id,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            }
        });

        $this->expectException(DuplicateOrNumber::class);
        app(PaymentService::class)->record(
            $this->enrollment, 'OR-2001', '2026-08-01', 500.00, 'cash', $this->cashier);
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

    public function test_sequential_payments_never_over_allocate_a_charge(): void
    {
        $svc = app(PaymentService::class);
        $svc->record($this->enrollment, 'OR-3001', '2026-08-01', 20000.00, 'cash', $this->cashier);
        $svc->record($this->enrollment, 'OR-3002', '2026-08-02', 6000.00, 'cash', $this->cashier);

        $tuition = $this->enrollment->ledgerEntries()->where('description', 'Tuition Fee')->first();
        $books = $this->enrollment->ledgerEntries()->where('description', 'Books')->first();

        // 25,000 tuition is exactly filled across both payments; 1,000 spills to books.
        $this->assertEqualsWithDelta(25000.00, (float) $tuition->allocations()->sum('amount'), 0.001);
        $this->assertEqualsWithDelta(1000.00, (float) $books->allocations()->sum('amount'), 0.001);
    }
}
