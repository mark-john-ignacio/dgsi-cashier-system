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

    /**
     * Ported from the deleted tests/Feature/ReportsTest.php
     * (test_batch_notices_render_one_page_per_unpaid_student) — the existing
     * batch-notices route test only had one, already-unpaid enrollment and
     * never proved that fully-paid students or other grade levels are
     * excluded.
     */
    public function test_batch_notices_excludes_paid_students_and_respects_grade_filter(): void
    {
        $year = SchoolYear::active();

        $paidStudent = Student::factory()->create(['first_name' => 'Fully', 'last_name' => 'Paid']);
        $paidEnrollment = app(RegistrationService::class)->register($paidStudent, $year, 'Grade 3');
        app(PaymentService::class)->record($paidEnrollment, 'OR-5002', now()->toDateString(), 10000.00, 'cash', $this->cashier);

        $structure4 = FeeStructure::create(['school_year_id' => $year->id, 'grade_level' => 'Grade 4']);
        $structure4->items()->create(['fee_type_id' => FeeType::create(['name' => 'Misc Fee'])->id, 'amount' => 5000]);
        $grade4Student = Student::factory()->create(['first_name' => 'Four', 'last_name' => 'Grader']);
        app(RegistrationService::class)->register($grade4Student, $year, 'Grade 4');

        $this->actingAs($this->cashier)
            ->get(route('print.notices.batch', ['grade_level' => 'Grade 3']))
            ->assertOk()
            ->assertSee('Testable, Print')
            ->assertDontSee('Paid, Fully')
            ->assertDontSee('Grader, Four');
    }

    public function test_all_print_routes_redirect_guests_to_login(): void
    {
        $this->get(route('print.slip', $this->payment))->assertRedirect(route('login'));
        $this->get(route('print.notice', $this->enrollment))->assertRedirect(route('login'));
        $this->get(route('print.statement', $this->enrollment))->assertRedirect(route('login'));
        $this->get(route('print.notices.batch'))->assertRedirect(route('login'));
    }
}
