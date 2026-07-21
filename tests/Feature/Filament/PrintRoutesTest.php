<?php

namespace Tests\Feature\Filament;

use App\Models\FeeStructure;
use App\Models\FeeType;
use App\Models\SchoolYear;
use App\Models\Student;
use App\Models\User;
use App\Services\PaymentService;
use App\Services\RegistrationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PrintRoutesTest extends TestCase
{
    use RefreshDatabase;

    private User $cashier;

    private $enrollment;

    private $payment;

    protected function setUp(): void
    {
        parent::setUp();

        $year = SchoolYear::factory()->create(['is_active' => true]);
        $structure = FeeStructure::create(['school_year_id' => $year->id, 'grade_level' => 'Grade 3']);
        $structure->items()->create(['fee_type_id' => FeeType::create(['name' => 'Tuition Fee'])->id, 'amount' => 10000]);

        $this->cashier = User::factory()->create(['role' => 'cashier']);

        $student = Student::factory()->create(['first_name' => 'Print', 'last_name' => 'Testable']);
        $this->enrollment = app(RegistrationService::class)->register($student, $year, 'Grade 3');
        $this->payment = app(PaymentService::class)->record($this->enrollment, 'OR-5001', now()->toDateString(), 4000.00, 'cash', $this->cashier);
    }

    public function test_slip_route_renders_for_authenticated_user(): void
    {
        $this->actingAs($this->cashier)
            ->get(route('print.slip', $this->payment))
            ->assertOk()
            ->assertSee('OR-5001')
            ->assertSee('Testable, Print');
    }

    public function test_notice_route_renders_for_authenticated_user(): void
    {
        $this->actingAs($this->cashier)
            ->get(route('print.notice', $this->enrollment))
            ->assertOk()
            ->assertSee('Testable, Print');
    }

    public function test_statement_route_renders_for_authenticated_user(): void
    {
        $this->actingAs($this->cashier)
            ->get(route('print.statement', $this->enrollment))
            ->assertOk()
            ->assertSee('Testable, Print')
            ->assertSee('OR-5001');
    }

    public function test_batch_notices_route_renders_for_authenticated_user(): void
    {
        $this->actingAs($this->cashier)
            ->get(route('print.notices.batch'))
            ->assertOk()
            ->assertSee('Testable, Print');
    }

    public function test_all_print_routes_redirect_guests_to_login(): void
    {
        $this->get(route('print.slip', $this->payment))->assertRedirect(route('login'));
        $this->get(route('print.notice', $this->enrollment))->assertRedirect(route('login'));
        $this->get(route('print.statement', $this->enrollment))->assertRedirect(route('login'));
        $this->get(route('print.notices.batch'))->assertRedirect(route('login'));
    }
}
