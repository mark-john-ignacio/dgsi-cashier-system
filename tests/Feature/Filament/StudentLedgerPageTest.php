<?php

namespace Tests\Feature\Filament;

use App\Filament\Pages\StudentLedger;
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

class StudentLedgerPageTest extends TestCase
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

    public function test_ledger_page_shows_charges_payments_balance(): void
    {
        app(PaymentService::class)->record(
            $this->enrollment, 'OR-1001', '2026-08-01', 5000.00, 'cash', $this->cashier);

        $this->actingAs(User::factory()->create(['role' => 'cashier']));

        Livewire::test(StudentLedger::class, ['enrollment' => $this->enrollment->id])
            ->assertSee('Tuition Fee')->assertSee('Books')
            ->assertSee('25,000.00')->assertSee('5,000.00')
            ->assertSee(number_format($this->enrollment->balance(), 2));
    }

    public function test_voided_payment_shown_struck_and_excluded_from_totals(): void
    {
        $payment = app(PaymentService::class)->record(
            $this->enrollment, 'OR-1001', '2026-08-01', 5000.00, 'cash', $this->cashier);

        app(PaymentService::class)->void($payment, $this->cashier, 'Wrong amount entered');

        $this->actingAs(User::factory()->create(['role' => 'cashier']));

        $this->assertEqualsWithDelta(28500.00, $this->enrollment->fresh()->balance(), 0.001);

        Livewire::test(StudentLedger::class, ['enrollment' => $this->enrollment->id])
            ->assertSee('Voided')
            ->assertSee(number_format($this->enrollment->fresh()->balance(), 2));
    }

    public function test_missing_enrollment_returns_404(): void
    {
        $this->actingAs(User::factory()->create(['role' => 'cashier']));

        $this->get(StudentLedger::getUrl(['enrollment' => 999999]))->assertNotFound();
    }

    public function test_admin_can_also_view_the_page(): void
    {
        app(PaymentService::class)->record(
            $this->enrollment, 'OR-1001', '2026-08-01', 5000.00, 'cash', $this->cashier);

        $this->actingAs(User::factory()->create(['role' => 'admin']));

        $this->get(StudentLedger::getUrl(['enrollment' => $this->enrollment->id]))->assertOk();
    }
}
