<?php

namespace Tests\Feature;

use App\Livewire\RecordPayment;
use App\Models\FeeStructure;
use App\Models\FeeType;
use App\Models\Payment;
use App\Models\SchoolYear;
use App\Models\Student;
use App\Models\User;
use App\Services\PaymentService;
use App\Services\RegistrationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class RecordPaymentUiTest extends TestCase
{
    use RefreshDatabase;

    private $enrollment;

    private User $cashier;

    protected function setUp(): void
    {
        parent::setUp();
        $year = SchoolYear::factory()->create(['is_active' => true]);
        $s = FeeStructure::create(['school_year_id' => $year->id, 'grade_level' => 'Grade 3']);
        $s->items()->create(['fee_type_id' => FeeType::create(['name' => 'Tuition Fee'])->id, 'amount' => 10000]);
        $this->enrollment = app(RegistrationService::class)->register(Student::factory()->create(), $year, 'Grade 3');
        $this->cashier = User::factory()->create(['role' => 'cashier']);
    }

    public function test_ledger_page_shows_charges_and_balance(): void
    {
        $this->actingAs($this->cashier)
            ->get(route('ledger.show', $this->enrollment))
            ->assertOk()
            ->assertSee('Tuition Fee')
            ->assertSee('10,000.00');
    }

    public function test_recording_payment_via_form_redirects_to_slip(): void
    {
        Livewire::actingAs($this->cashier)
            ->test(RecordPayment::class, ['enrollment' => $this->enrollment])
            ->set('or_number', 'OR-500')
            ->set('amount', '2500')
            ->set('method', 'cash')
            ->call('save')
            ->assertRedirect(route('slips.show', Payment::first()));

        $this->assertEqualsWithDelta(7500.00, $this->enrollment->fresh()->balance(), 0.001);
    }

    public function test_duplicate_or_shows_validation_error_not_crash(): void
    {
        app(PaymentService::class)->record($this->enrollment, 'OR-500', now()->toDateString(), 100, 'cash', $this->cashier);

        Livewire::actingAs($this->cashier)
            ->test(RecordPayment::class, ['enrollment' => $this->enrollment])
            ->set('or_number', 'OR-500')
            ->set('amount', '100')
            ->set('method', 'cash')
            ->call('save')
            ->assertHasErrors('or_number');
    }

    public function test_overpayment_requires_confirmation(): void
    {
        $component = Livewire::actingAs($this->cashier)
            ->test(RecordPayment::class, ['enrollment' => $this->enrollment])
            ->set('or_number', 'OR-501')
            ->set('amount', '15000')
            ->set('method', 'cash')
            ->call('save')
            ->assertHasErrors('amount'); // blocked until confirmed

        $component->set('confirmOverpay', true)->call('save');
        $this->assertEqualsWithDelta(-5000.00, $this->enrollment->fresh()->balance(), 0.001);
    }

    public function test_promissory_note_can_be_marked_fulfilled(): void
    {
        $note = $this->enrollment->promissoryNotes()->create([
            'amount' => 2000, 'due_date' => now()->addMonth(), 'status' => 'pending',
        ]);

        $this->actingAs($this->cashier)
            ->post(route('promissory.status', $note), ['status' => 'fulfilled'])
            ->assertRedirect();

        $this->assertSame('fulfilled', $note->fresh()->status);
        $this->assertEqualsWithDelta(0.0, $this->enrollment->pendingPromissoryTotal(), 0.001);
    }

    public function test_void_route_requires_reason_and_restores_balance(): void
    {
        $payment = app(PaymentService::class)->record($this->enrollment, 'OR-500', now()->toDateString(), 2500, 'cash', $this->cashier);

        $this->actingAs($this->cashier)
            ->post(route('payments.void', $payment), ['reason' => 'Keyed wrong student'])
            ->assertRedirect();

        $this->assertTrue($payment->fresh()->isVoided());
        $this->assertEqualsWithDelta(10000.00, $this->enrollment->fresh()->balance(), 0.001);
    }
}
