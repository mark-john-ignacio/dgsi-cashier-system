<?php

namespace Tests\Feature;

use App\Models\Enrollment;
use App\Models\PromissoryNote;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PromissoryNoteTest extends TestCase
{
    use RefreshDatabase;

    public function test_pending_promissory_total_counts_only_pending_notes(): void
    {
        $enrollment = Enrollment::factory()->create();
        PromissoryNote::factory()->create(['enrollment_id' => $enrollment->id, 'amount' => 3000, 'status' => 'pending']);
        PromissoryNote::factory()->create(['enrollment_id' => $enrollment->id, 'amount' => 1000, 'status' => 'fulfilled']);

        $this->assertEqualsWithDelta(3000.00, $enrollment->pendingPromissoryTotal(), 0.001);
    }
}
