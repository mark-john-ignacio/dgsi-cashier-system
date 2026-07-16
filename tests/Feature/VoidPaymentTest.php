<?php

namespace Tests\Feature;

use App\Exceptions\VoidNotAllowed;
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

class VoidPaymentTest extends TestCase
{
    use RefreshDatabase;

    private $enrollment;

    private User $cashier;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        $year = SchoolYear::factory()->create(['is_active' => true]);
        $structure = FeeStructure::create(['school_year_id' => $year->id, 'grade_level' => 'Grade 3']);
        $structure->items()->create(['fee_type_id' => FeeType::create(['name' => 'Tuition Fee'])->id, 'amount' => 25000]);
        $this->enrollment = app(RegistrationService::class)->register(Student::factory()->create(), $year, 'Grade 3');
        $this->cashier = User::factory()->create(['role' => 'cashier']);
        $this->admin = User::factory()->create(['role' => 'admin']);
    }

    public function test_void_restores_balance_and_keeps_row_visible(): void
    {
        $svc = app(PaymentService::class);
        $payment = $svc->record($this->enrollment, 'OR-1', '2026-08-01', 5000, 'cash', $this->cashier);
        $this->assertEqualsWithDelta(20000.00, $this->enrollment->fresh()->balance(), 0.001);

        $svc->void($payment, $this->cashier, 'Wrong amount keyed in');

        $fresh = $payment->fresh();
        $this->assertTrue($fresh->isVoided());
        $this->assertSame('Wrong amount keyed in', $fresh->void_reason);
        $this->assertEqualsWithDelta(25000.00, $this->enrollment->fresh()->balance(), 0.001);
        $this->assertDatabaseCount('payments', 1); // never deleted
    }

    public function test_cashier_cannot_void_payment_created_on_a_previous_day(): void
    {
        $svc = app(PaymentService::class);
        $payment = $svc->record($this->enrollment, 'OR-1', '2026-08-01', 5000, 'cash', $this->cashier);
        $payment->forceFill(['created_at' => now()->subDay()])->save();

        $this->expectException(VoidNotAllowed::class);
        $svc->void($payment->fresh(), $this->cashier, 'Late void attempt');
    }

    public function test_admin_can_void_old_payments_and_action_is_audited(): void
    {
        $svc = app(PaymentService::class);
        $payment = $svc->record($this->enrollment, 'OR-1', '2026-08-01', 5000, 'cash', $this->cashier);
        $payment->forceFill(['created_at' => now()->subDays(10)])->save();

        $svc->void($payment->fresh(), $this->admin, 'Audit correction');

        $this->assertTrue($payment->fresh()->isVoided());
        $this->assertDatabaseHas('audit_logs', [
            'action' => 'payment.voided',
            'subject_type' => Payment::class,
            'subject_id' => $payment->id,
            'user_id' => $this->admin->id,
        ]);
    }

    public function test_void_requires_a_reason_and_rejects_double_void(): void
    {
        $svc = app(PaymentService::class);
        $payment = $svc->record($this->enrollment, 'OR-1', '2026-08-01', 5000, 'cash', $this->cashier);

        try {
            $svc->void($payment, $this->cashier, '  ');
            $this->fail('Blank reason should be rejected');
        } catch (VoidNotAllowed) {
        }

        $svc->void($payment, $this->cashier, 'Valid reason');
        $this->expectException(VoidNotAllowed::class);
        $svc->void($payment->fresh(), $this->admin, 'Double void');
    }
}
