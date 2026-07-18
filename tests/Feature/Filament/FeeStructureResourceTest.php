<?php

namespace Tests\Feature\Filament;

use App\Filament\Resources\FeeStructures\Pages\CreateFeeStructure;
use App\Filament\Resources\FeeStructures\Pages\EditFeeStructure;
use App\Filament\Resources\FeeStructures\Pages\ListFeeStructures;
use App\Models\FeeStructure;
use App\Models\FeeType;
use App\Models\SchoolYear;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class FeeStructureResourceTest extends TestCase
{
    use RefreshDatabase;

    private function admin(): User
    {
        return User::factory()->create(['role' => 'admin']);
    }

    public function test_cashier_cannot_see_resource(): void
    {
        $this->actingAs(User::factory()->create(['role' => 'cashier']));
        $this->get(ListFeeStructures::getUrl())->assertForbidden();
    }

    public function test_admin_creates_structure_with_items(): void
    {
        $year = SchoolYear::factory()->create(['is_active' => true]);
        $tuition = FeeType::create(['name' => 'Tuition Fee']);
        $books = FeeType::create(['name' => 'Books']);
        $this->actingAs($this->admin());

        Livewire::test(CreateFeeStructure::class)->fillForm([
            'school_year_id' => $year->id,
            'grade_level' => 'Grade 3',
            'items' => [
                ['fee_type_id' => $tuition->id, 'amount' => 25000],
                ['fee_type_id' => $books->id, 'amount' => 3500],
            ],
        ])->call('create')->assertHasNoFormErrors();

        $this->assertDatabaseCount('fee_structure_items', 2);
        $this->assertDatabaseHas('fee_structures', [
            'school_year_id' => $year->id,
            'grade_level' => 'Grade 3',
        ]);
    }

    public function test_duplicate_grade_level_in_same_year_is_rejected(): void
    {
        $year = SchoolYear::factory()->create();
        $feeType = FeeType::create(['name' => 'Tuition Fee']);
        FeeStructure::factory()->create(['school_year_id' => $year->id, 'grade_level' => 'Grade 3']);
        $this->actingAs($this->admin());

        Livewire::test(CreateFeeStructure::class)->fillForm([
            'school_year_id' => $year->id,
            'grade_level' => 'Grade 3',
            'items' => [
                ['fee_type_id' => $feeType->id, 'amount' => 1000],
            ],
        ])->call('create')->assertHasFormErrors(['grade_level']);
    }

    public function test_same_grade_level_in_different_years_is_allowed(): void
    {
        $yearOne = SchoolYear::factory()->create();
        $yearTwo = SchoolYear::factory()->create();
        $feeType = FeeType::create(['name' => 'Tuition Fee']);
        FeeStructure::factory()->create(['school_year_id' => $yearOne->id, 'grade_level' => 'Grade 3']);
        $this->actingAs($this->admin());

        Livewire::test(CreateFeeStructure::class)->fillForm([
            'school_year_id' => $yearTwo->id,
            'grade_level' => 'Grade 3',
            'items' => [
                ['fee_type_id' => $feeType->id, 'amount' => 1000],
            ],
        ])->call('create')->assertHasNoFormErrors();

        $this->assertDatabaseCount('fee_structures', 2);
    }

    public function test_editing_existing_structure_item_amount_persists(): void
    {
        $structure = FeeStructure::factory()->create();
        $feeType = FeeType::create(['name' => 'Tuition Fee']);
        $item = $structure->items()->create(['fee_type_id' => $feeType->id, 'amount' => 1000]);
        $this->actingAs($this->admin());

        Livewire::test(EditFeeStructure::class, ['record' => $structure->getRouteKey()])
            ->fillForm([
                'items' => [
                    'record-'.$item->getKey() => ['fee_type_id' => $feeType->id, 'amount' => 1500],
                ],
            ])
            ->call('save')
            ->assertHasNoFormErrors();

        $this->assertEquals(1500, $item->fresh()->amount);
    }
}
