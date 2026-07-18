<?php

namespace Tests\Feature\Filament;

use App\Filament\Pages\StudentLedger;
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

class RecordPaymentActionTest extends TestCase
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

    public function test_records_payment_and_redirects_to_slip(): void
    {
        Livewire::actingAs($this->cashier)
            ->test(StudentLedger::class, ['enrollment' => $this->enrollment->id])
            ->callAction('recordPayment', [
                'or_number' => 'OR-9001', 'payment_date' => now()->toDateString(),
                'amount' => 5000, 'method' => 'cash',
            ])->assertHasNoActionErrors()
            ->assertRedirect(route('slips.show', Payment::first()));

        $this->assertEqualsWithDelta(23500.0, $this->enrollment->fresh()->balance(), 0.001);
    }

    public function test_duplicate_or_number_errors_on_or_field(): void
    {
        app(PaymentService::class)->record($this->enrollment, 'OR-9001', now()->toDateString(), 100, 'cash', $this->cashier);

        Livewire::actingAs($this->cashier)
            ->test(StudentLedger::class, ['enrollment' => $this->enrollment->id])
            ->callAction('recordPayment', [
                'or_number' => 'OR-9001', 'payment_date' => now()->toDateString(),
                'amount' => 100, 'method' => 'cash',
            ])
            ->assertHasActionErrors(['or_number']);
    }

    public function test_overpay_requires_confirmation_checkbox(): void
    {
        Livewire::actingAs($this->cashier)
            ->test(StudentLedger::class, ['enrollment' => $this->enrollment->id])
            ->callAction('recordPayment', [
                'or_number' => 'OR-9002', 'payment_date' => now()->toDateString(),
                'amount' => 30000, 'method' => 'cash',
            ])
            ->assertHasActionErrors(['amount']);

        $this->assertSame(0, Payment::count());

        Livewire::actingAs($this->cashier)
            ->test(StudentLedger::class, ['enrollment' => $this->enrollment->id])
            ->callAction('recordPayment', [
                'or_number' => 'OR-9002', 'payment_date' => now()->toDateString(),
                'amount' => 30000, 'method' => 'cash', 'confirm_overpay' => true,
            ])
            ->assertHasNoActionErrors();

        $this->assertGreaterThan(0.0, Payment::first()->unallocatedAmount());
        $this->assertEqualsWithDelta(1500.0, Payment::first()->unallocatedAmount(), 0.001);
    }

    public function test_future_payment_date_rejected(): void
    {
        Livewire::actingAs($this->cashier)
            ->test(StudentLedger::class, ['enrollment' => $this->enrollment->id])
            ->callAction('recordPayment', [
                'or_number' => 'OR-9003', 'payment_date' => now()->addDay()->toDateString(),
                'amount' => 100, 'method' => 'cash',
            ])
            ->assertHasActionErrors(['payment_date']);

        $this->assertSame(0, Payment::count());
    }
}
