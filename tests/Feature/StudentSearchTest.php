<?php

namespace Tests\Feature;

use App\Livewire\StudentSearch;
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

class StudentSearchTest extends TestCase
{
    use RefreshDatabase;

    private SchoolYear $year;

    protected function setUp(): void
    {
        parent::setUp();
        $this->year = SchoolYear::factory()->create(['is_active' => true]);
        $structure = FeeStructure::create(['school_year_id' => $this->year->id, 'grade_level' => 'Grade 3']);
        $structure->items()->create(['fee_type_id' => FeeType::create(['name' => 'Tuition Fee'])->id, 'amount' => 10000]);
    }

    public function test_dashboard_requires_login(): void
    {
        $this->get('/dashboard')->assertRedirect('/login');
    }

    public function test_dashboard_shows_todays_collections(): void
    {
        $cashier = User::factory()->create(['role' => 'cashier']);
        $enrollment = app(RegistrationService::class)
            ->register(Student::factory()->create(), $this->year, 'Grade 3');
        app(PaymentService::class)->record($enrollment, 'OR-1', now()->toDateString(), 1500, 'cash', $cashier);

        $this->actingAs($cashier)->get('/dashboard')
            ->assertOk()
            ->assertSee('1,500.00');
    }

    public function test_search_filters_by_name_and_balance(): void
    {
        $cashier = User::factory()->create(['role' => 'cashier']);
        $paid = app(RegistrationService::class)->register(
            Student::factory()->create(['first_name' => 'Maria', 'last_name' => 'Santos']), $this->year, 'Grade 3');
        $unpaid = app(RegistrationService::class)->register(
            Student::factory()->create(['first_name' => 'Jose', 'last_name' => 'Reyes']), $this->year, 'Grade 3');
        app(PaymentService::class)->record($paid, 'OR-1', now()->toDateString(), 10000, 'cash', $cashier);

        Livewire::actingAs($cashier)->test(StudentSearch::class)
            ->set('query', 'Reyes')
            ->assertSee('Reyes, Jose')
            ->assertDontSee('Santos, Maria');

        Livewire::actingAs($cashier)->test(StudentSearch::class)
            ->set('withBalanceOnly', true)
            ->assertSee('Reyes, Jose')
            ->assertDontSee('Santos, Maria');
    }
}
