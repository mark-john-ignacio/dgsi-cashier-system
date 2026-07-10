<?php

namespace Tests\Feature;

use App\Models\FeeStructure;
use App\Models\FeeType;
use App\Models\SchoolYear;
use App\Models\Student;
use App\Services\RegistrationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class LedgerTest extends TestCase
{
    use RefreshDatabase;

    private function makeStructure(SchoolYear $year, string $grade = 'Grade 3'): FeeStructure
    {
        $structure = FeeStructure::create(['school_year_id' => $year->id, 'grade_level' => $grade]);
        $structure->items()->create([
            'fee_type_id' => FeeType::create(['name' => 'Tuition Fee'])->id, 'amount' => 25000,
        ]);
        $structure->items()->create([
            'fee_type_id' => FeeType::create(['name' => 'Books'])->id, 'amount' => 3500,
        ]);

        return $structure;
    }

    public function test_registration_copies_fee_structure_to_ledger(): void
    {
        $year = SchoolYear::factory()->create();
        $this->makeStructure($year);

        $enrollment = app(RegistrationService::class)
            ->register(Student::factory()->create(), $year, 'Grade 3', 'St. Luke');

        $this->assertCount(2, $enrollment->ledgerEntries);
        $this->assertEqualsWithDelta(28500.00, $enrollment->totalAssessed(), 0.001);
        $this->assertEqualsWithDelta(28500.00, $enrollment->balance(), 0.001);
    }

    public function test_later_fee_structure_change_does_not_rewrite_existing_ledgers(): void
    {
        $year = SchoolYear::factory()->create();
        $structure = $this->makeStructure($year);
        $enrollment = app(RegistrationService::class)
            ->register(Student::factory()->create(), $year, 'Grade 3');

        $structure->items()->first()->update(['amount' => 99999]);

        $this->assertEqualsWithDelta(28500.00, $enrollment->fresh()->totalAssessed(), 0.001);
    }

    public function test_negative_adjustment_reduces_balance(): void
    {
        $year = SchoolYear::factory()->create();
        $this->makeStructure($year);
        $enrollment = app(RegistrationService::class)
            ->register(Student::factory()->create(), $year, 'Grade 3');

        $enrollment->ledgerEntries()->create([
            'type' => 'adjustment', 'description' => 'Sibling discount', 'amount' => -2000,
        ]);

        $this->assertEqualsWithDelta(26500.00, $enrollment->balance(), 0.001);
    }

    public function test_registration_fails_without_fee_structure(): void
    {
        $year = SchoolYear::factory()->create();
        $this->expectException(\RuntimeException::class);
        app(RegistrationService::class)->register(Student::factory()->create(), $year, 'Grade 7');
    }
}
