<?php

namespace Tests\Feature;

use App\Models\FeeStructure;
use App\Models\FeeType;
use App\Models\SchoolYear;
use App\Models\Student;
use App\Models\User;
use App\Services\PaymentService;
use App\Services\RegistrationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ReportsTest extends TestCase
{
    use RefreshDatabase;

    private SchoolYear $year;

    private User $cashier;

    private $paidEnrollment;

    private $unpaidEnrollment;

    protected function setUp(): void
    {
        parent::setUp();
        $this->year = SchoolYear::factory()->create(['is_active' => true]);
        $s = FeeStructure::create(['school_year_id' => $this->year->id, 'grade_level' => 'Grade 3']);
        $s->items()->create(['fee_type_id' => FeeType::create(['name' => 'Tuition Fee'])->id, 'amount' => 10000]);
        $this->cashier = User::factory()->create(['role' => 'cashier']);

        $reg = app(RegistrationService::class);
        $this->paidEnrollment = $reg->register(
            Student::factory()->create(['first_name' => 'Maria', 'last_name' => 'Santos']), $this->year, 'Grade 3');
        $this->unpaidEnrollment = $reg->register(
            Student::factory()->create(['first_name' => 'Jose', 'last_name' => 'Reyes']), $this->year, 'Grade 3');

        app(PaymentService::class)->record($this->paidEnrollment, 'OR-1', now()->toDateString(), 10000, 'cash', $this->cashier);
    }

    public function test_daily_report_totals_todays_collections_and_shows_voids(): void
    {
        $voided = app(PaymentService::class)->record($this->unpaidEnrollment, 'OR-2', now()->toDateString(), 500, 'cash', $this->cashier);
        app(PaymentService::class)->void($voided, $this->cashier, 'Test void');

        $this->actingAs($this->cashier)
            ->get(route('reports.daily', ['date' => now()->toDateString()]))
            ->assertOk()
            ->assertSee('OR-1')
            ->assertSee('OR-2')      // voided still listed
            ->assertSee('VOIDED')
            ->assertSee('10,000.00'); // total excludes the voided 500
    }

    public function test_daily_report_exports_csv(): void
    {
        $response = $this->actingAs($this->cashier)
            ->get(route('reports.daily', ['date' => now()->toDateString(), 'csv' => 1]));

        $response->assertOk()->assertHeader('content-disposition');
        $this->assertStringContainsString('OR-1', $response->streamedContent());
    }

    public function test_unpaid_report_lists_only_students_with_balance(): void
    {
        $this->actingAs($this->cashier)
            ->get(route('reports.unpaid'))
            ->assertOk()
            ->assertSee('Reyes, Jose')
            ->assertDontSee('Santos, Maria');
    }

    public function test_notice_and_statement_render(): void
    {
        $this->actingAs($this->cashier)
            ->get(route('reports.notice', $this->unpaidEnrollment))
            ->assertOk()->assertSee('Reyes, Jose')->assertSee('10,000.00');

        $this->actingAs($this->cashier)
            ->get(route('reports.statement', $this->paidEnrollment))
            ->assertOk()->assertSee('Santos, Maria')->assertSee('OR-1');
    }

    public function test_batch_notices_render_one_page_per_unpaid_student(): void
    {
        $this->actingAs($this->cashier)
            ->get(route('reports.notices.batch', ['grade_level' => 'Grade 3']))
            ->assertOk()
            ->assertSee('Reyes, Jose')
            ->assertDontSee('Santos, Maria');
    }
}
