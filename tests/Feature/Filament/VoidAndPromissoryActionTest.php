<?php

namespace Tests\Feature\Filament;

use App\Filament\Pages\StudentLedger;
use App\Models\FeeStructure;
use App\Models\FeeType;
use App\Models\PromissoryNote;
use App\Models\SchoolYear;
use App\Models\Student;
use App\Models\User;
use App\Services\PaymentService;
use App\Services\RegistrationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class VoidAndPromissoryActionTest extends TestCase
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
        $structure->items()->create(['fee_type_id' => FeeType::create(['name' => 'Tuition Fee'])->id, 'amount' => 28500]);
        $this->enrollment = app(RegistrationService::class)->register(Student::factory()->create(), $year, 'Grade 3');
        $this->cashier = User::factory()->create(['role' => 'cashier']);
        $this->admin = User::factory()->create(['role' => 'admin']);
    }

    public function test_cashier_voids_todays_payment_with_reason(): void
    {
        $payment = app(PaymentService::class)->record(
            $this->enrollment, 'OR-2001', now()->toDateString(), 5000.00, 'cash', $this->cashier);

        Livewire::actingAs($this->cashier)
            ->test(StudentLedger::class, ['enrollment' => $this->enrollment->id])
            ->callAction('void', ['reason' => 'Wrong amount entered'], arguments: ['payment' => $payment->id])
            ->assertHasNoActionErrors();

        $payment->refresh();
        $this->assertNotNull($payment->voided_at);
        $this->assertSame('Wrong amount entered', $payment->void_reason);
        $this->assertSame($this->cashier->id, $payment->voided_by);
    }

    public function test_cashier_cannot_see_or_call_void_on_old_payment_but_admin_can(): void
    {
        $payment = app(PaymentService::class)->record(
            $this->enrollment, 'OR-2002', now()->toDateString(), 5000.00, 'cash', $this->cashier);
        $payment->forceFill(['created_at' => now()->subDays(2)])->save();

        Livewire::actingAs($this->cashier)
            ->test(StudentLedger::class, ['enrollment' => $this->enrollment->id])
            ->assertActionHidden('void', arguments: ['payment' => $payment->id]);

        $this->assertNull($payment->fresh()->voided_at);

        Livewire::actingAs($this->admin)
            ->test(StudentLedger::class, ['enrollment' => $this->enrollment->id])
            ->callAction('void', ['reason' => 'Admin correction'], arguments: ['payment' => $payment->id])
            ->assertHasNoActionErrors();

        $this->assertNotNull($payment->fresh()->voided_at);
    }

    public function test_void_without_reason_errors(): void
    {
        $payment = app(PaymentService::class)->record(
            $this->enrollment, 'OR-2003', now()->toDateString(), 5000.00, 'cash', $this->cashier);

        Livewire::actingAs($this->cashier)
            ->test(StudentLedger::class, ['enrollment' => $this->enrollment->id])
            ->callAction('void', ['reason' => ''], arguments: ['payment' => $payment->id])
            ->assertHasActionErrors(['reason']);

        $this->assertNull($payment->fresh()->voided_at);
    }

    public function test_promissory_note_create_persists_with_created_by(): void
    {
        Livewire::actingAs($this->cashier)
            ->test(StudentLedger::class, ['enrollment' => $this->enrollment->id])
            ->callAction('addPromissory', [
                'amount' => 1000,
                'due_date' => now()->addMonth()->toDateString(),
                'notes' => 'Will pay next month',
            ])
            ->assertHasNoActionErrors();

        $note = PromissoryNote::first();
        $this->assertNotNull($note);
        $this->assertSame($this->enrollment->id, $note->enrollment_id);
        $this->assertEqualsWithDelta(1000.0, (float) $note->amount, 0.001);
        $this->assertSame('Will pay next month', $note->notes);
        $this->assertSame('pending', $note->status);
        $this->assertSame($this->cashier->id, $note->created_by);
    }

    public function test_promissory_status_update_pending_to_fulfilled(): void
    {
        $note = PromissoryNote::factory()->create([
            'enrollment_id' => $this->enrollment->id,
            'status' => 'pending',
        ]);

        Livewire::actingAs($this->cashier)
            ->test(StudentLedger::class, ['enrollment' => $this->enrollment->id])
            ->callAction('promissoryStatus', ['status' => 'fulfilled'], arguments: ['note' => $note->id])
            ->assertHasNoActionErrors();

        $this->assertSame('fulfilled', $note->fresh()->status);
    }
}
