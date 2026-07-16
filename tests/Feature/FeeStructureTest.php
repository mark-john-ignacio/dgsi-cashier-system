<?php

namespace Tests\Feature;

use App\Models\FeeStructure;
use App\Models\FeeType;
use App\Models\SchoolYear;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class FeeStructureTest extends TestCase
{
    use RefreshDatabase;

    public function test_fee_structure_totals_its_items(): void
    {
        $year = SchoolYear::factory()->create();
        $tuition = FeeType::create(['name' => 'Tuition Fee']);
        $books = FeeType::create(['name' => 'Books']);

        $structure = FeeStructure::create(['school_year_id' => $year->id, 'grade_level' => 'Grade 3']);
        $structure->items()->create(['fee_type_id' => $tuition->id, 'amount' => 25000]);
        $structure->items()->create(['fee_type_id' => $books->id, 'amount' => 3500]);

        $this->assertEqualsWithDelta(28500.00, $structure->total(), 0.001);
    }

    public function test_one_structure_per_grade_per_year(): void
    {
        $year = SchoolYear::factory()->create();
        FeeStructure::create(['school_year_id' => $year->id, 'grade_level' => 'Grade 3']);

        $this->expectException(QueryException::class);
        FeeStructure::create(['school_year_id' => $year->id, 'grade_level' => 'Grade 3']);
    }
}
