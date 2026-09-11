<?php

namespace Tests\Feature\Filament;

use App\Filament\Resources\Students\Pages\CreateStudent;
use App\Filament\Resources\Students\Pages\ListStudents;
use App\Filament\Resources\Students\StudentResource;
use App\Models\FeeStructure;
use App\Models\FeeType;
use App\Models\LedgerEntry;
use App\Models\SchoolYear;
use App\Models\Student;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class StudentResourceTest extends TestCase
{
    use RefreshDatabase;

    public function test_both_roles_can_load_the_list_page(): void
    {
        foreach (['admin', 'cashier'] as $role) {
            $this->actingAs(User::factory()->create(['role' => $role]));
            $this->get(ListStudents::getUrl())->assertOk();
        }
    }

    public function test_search_by_student_no_finds_the_record(): void
    {
        $this->actingAs(User::factory()->create(['role' => 'cashier']));
        $match = Student::factory()->create(['student_no' => 'DGS-0001']);
        $other = Student::factory()->create(['student_no' => 'DGS-0002']);

        Livewire::test(ListStudents::class)
            ->searchTable('DGS-0001')
            ->assertCanSeeTableRecords([$match])
            ->assertCanNotSeeTableRecords([$other]);
    }

    /**
     * Ported from the deleted tests/Feature/StudentSearchTest.php — the old
     * StudentSearch Livewire component searched by name; the Filament table
     * search only had student_no coverage before this port.
     */
    public function test_search_by_last_name_finds_the_record(): void
    {
        $this->actingAs(User::factory()->create(['role' => 'cashier']));
        $match = Student::factory()->create(['first_name' => 'Jose', 'last_name' => 'Reyes']);
        $other = Student::factory()->create(['first_name' => 'Maria', 'last_name' => 'Santos']);

        Livewire::test(ListStudents::class)
            ->searchTable('Reyes')
            ->assertCanSeeTableRecords([$match])
            ->assertCanNotSeeTableRecords([$other]);
    }

    public function test_create_with_valid_data_persists(): void
    {
        $this->actingAs(User::factory()->create(['role' => 'admin']));

        Livewire::test(CreateStudent::class)
            ->fillForm([
                'student_no' => 'DGS-1000',
                'first_name' => 'Juan',
                'last_name' => 'Dela Cruz',
                'guardian_name' => 'Maria Dela Cruz',
                'guardian_contact' => '09171234567',
                'status' => 'enrolled',
            ])
            ->call('create')
            ->assertHasNoFormErrors();

        $this->assertDatabaseHas('students', [
            'student_no' => 'DGS-1000',
            'first_name' => 'Juan',
            'last_name' => 'Dela Cruz',
        ]);
    }

    public function test_register_action_creates_enrollment_and_charges_via_service(): void
    {
        $this->actingAs(User::factory()->create(['role' => 'cashier']));
        $year = SchoolYear::factory()->create(['is_active' => true]);
        $structure = FeeStructure::create(['school_year_id' => $year->id, 'grade_level' => 'Grade 3']);
        $structure->items()->create(['fee_type_id' => FeeType::create(['name' => 'Tuition Fee'])->id, 'amount' => 10000]);
        $structure->items()->create(['fee_type_id' => FeeType::create(['name' => 'Books'])->id, 'amount' => 2000]);
        $student = Student::factory()->create();

        Livewire::test(ListStudents::class)
            ->callTableAction('register', $student, data: [
                'grade_level' => 'Grade 3',
                'section' => 'Rizal',
            ]);

        $this->assertDatabaseCount('enrollments', 1);
        $this->assertDatabaseHas('enrollments', [
            'student_id' => $student->id,
            'school_year_id' => $year->id,
            'grade_level' => 'Grade 3',
            'section' => 'Rizal',
        ]);
        $this->assertSame($structure->items()->count(), LedgerEntry::query()->count());
    }

    public function test_register_without_fee_structure_notifies_failure_and_creates_nothing(): void
    {
        $this->actingAs(User::factory()->create(['role' => 'cashier']));
        SchoolYear::factory()->create(['is_active' => true]);
        $student = Student::factory()->create();

        Livewire::test(ListStudents::class)
            ->callTableAction('register', $student, data: [
                'grade_level' => 'Grade 3',
                'section' => null,
            ])
            ->assertNotified();

        $this->assertDatabaseCount('enrollments', 0);
        $this->assertDatabaseCount('ledger_entries', 0);
    }

    public function test_list_page_renders_ledger_link_for_currently_enrolled_student_without_crashing(): void
    {
        $this->actingAs(User::factory()->create(['role' => 'cashier']));
        $year = SchoolYear::factory()->create(['is_active' => true]);
        $structure = FeeStructure::create(['school_year_id' => $year->id, 'grade_level' => 'Grade 3']);
        $structure->items()->create(['fee_type_id' => FeeType::create(['name' => 'Tuition Fee'])->id, 'amount' => 10000]);
        $student = Student::factory()->create();

        Livewire::test(ListStudents::class)
            ->callTableAction('register', $student, data: ['grade_level' => 'Grade 3']);

        $enrollment = $student->enrollments()->where('school_year_id', $year->id)->firstOrFail();

        Livewire::test(ListStudents::class)->assertOk();
        $this->get(ListStudents::getUrl())
            ->assertOk()
            ->assertSee(StudentResource::ledgerUrl($enrollment), false);
    }
}
