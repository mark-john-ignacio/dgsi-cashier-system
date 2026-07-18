<?php

namespace Tests\Feature\Filament;

use App\Filament\Resources\FeeTypes\Pages\CreateFeeType;
use App\Filament\Resources\FeeTypes\Pages\ListFeeTypes;
use App\Models\FeeType;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class FeeTypeResourceTest extends TestCase
{
    use RefreshDatabase;

    private function admin(): User
    {
        return User::factory()->create(['role' => 'admin']);
    }

    public function test_cashier_cannot_see_resource(): void
    {
        $this->actingAs(User::factory()->create(['role' => 'cashier']));
        $this->get(ListFeeTypes::getUrl())->assertForbidden();
    }

    public function test_admin_creates_fee_type(): void
    {
        $this->actingAs($this->admin());
        Livewire::test(CreateFeeType::class)
            ->fillForm(['name' => 'Laboratory Fee'])
            ->call('create')
            ->assertHasNoFormErrors();

        $this->assertDatabaseHas('fee_types', ['name' => 'Laboratory Fee']);
    }

    public function test_duplicate_name_validation(): void
    {
        FeeType::factory()->create(['name' => 'Laboratory Fee']);

        $this->actingAs($this->admin());
        Livewire::test(CreateFeeType::class)
            ->fillForm(['name' => 'Laboratory Fee'])
            ->call('create')
            ->assertHasFormErrors(['name']);
    }
}
