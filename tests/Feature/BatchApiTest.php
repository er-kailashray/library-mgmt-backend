<?php

namespace Tests\Feature;

use App\Models\Batch;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\BuildsLibrary;
use Tests\TestCase;

class BatchApiTest extends TestCase
{
    use BuildsLibrary, RefreshDatabase;

    /** The library from createLibrary() already has "Morning", 08:00-12:00. */
    private function createBatch(string $start, string $end, string $name = 'New batch')
    {
        return $this->actingAs($this->owner)->postJson('/api/batches', [
            'name' => $name,
            'start_time' => $start,
            'end_time' => $end,
            'monthly_fee' => 800,
        ]);
    }

    public function test_can_create_a_batch_that_starts_on_a_half_hour(): void
    {
        $this->createLibrary();

        $this->createBatch('12:30', '16:45')->assertOk()->assertJsonPath('status', true);

        $this->assertDatabaseHas('batches', ['name' => 'New batch', 'library_id' => $this->library->id]);
    }

    public function test_a_batch_may_start_exactly_when_another_ends(): void
    {
        $this->createLibrary();

        $this->createBatch('12:00', '16:00')->assertOk();
        $this->createBatch('05:00', '08:00', 'Early')->assertOk();
    }

    public function test_overlapping_batch_is_refused_and_names_the_clash(): void
    {
        $this->createLibrary();

        $this->createBatch('05:00', '10:00')
            ->assertStatus(422)
            ->assertJsonPath('message', 'This time overlaps with Morning (8:00 AM - 12:00 PM).');

        $this->assertSame(1, Batch::where('library_id', $this->library->id)->count());
    }

    public function test_batch_inside_another_is_refused(): void
    {
        $this->createLibrary();

        $this->createBatch('09:00', '10:30')->assertStatus(422);
    }

    public function test_overnight_batch_clashes_with_an_early_morning_batch(): void
    {
        $this->createLibrary();
        $this->createBatch('22:00', '02:00', 'Night')->assertOk();

        // 01:00-03:00 runs into the end of "Night", even though 01:00 < 22:00.
        $this->createBatch('01:00', '03:00', 'Late')
            ->assertStatus(422)
            ->assertJsonPath('message', 'This time overlaps with Night (10:00 PM - 2:00 AM).');

        // 02:00 onwards is free again.
        $this->createBatch('02:00', '05:00', 'Dawn')->assertOk();
    }

    public function test_new_overnight_batch_clashes_with_an_existing_evening_batch(): void
    {
        $this->createLibrary();
        $this->createBatch('18:00', '23:00', 'Evening')->assertOk();

        $this->createBatch('22:30', '01:00', 'Night')->assertStatus(422);
    }

    public function test_start_and_end_cannot_be_the_same(): void
    {
        $this->createLibrary();

        $this->createBatch('14:00', '14:00')->assertStatus(422)->assertJsonValidationErrors('end_time');
    }

    public function test_times_must_be_on_a_15_minute_step(): void
    {
        $this->createLibrary();

        $this->createBatch('12:10', '14:00')->assertStatus(422)->assertJsonValidationErrors('start_time');
    }

    private function updateBatch(Batch $batch, array $overrides = [])
    {
        return $this->actingAs($this->owner)->postJson("/api/batches/{$batch->id}/update", array_merge([
            'name' => $batch->name,
            'start_time' => substr($batch->start_time, 0, 5),
            'end_time' => substr($batch->end_time, 0, 5),
            'monthly_fee' => $batch->monthly_fee,
        ], $overrides));
    }

    public function test_can_edit_name_times_and_fee(): void
    {
        $this->createLibrary();

        $this->updateBatch($this->batch, [
            'name' => 'Early Morning',
            'start_time' => '07:30',
            'end_time' => '11:45',
            'monthly_fee' => 1200,
        ])->assertOk()->assertJsonPath('data.name', 'Early Morning');

        $this->batch->refresh();
        $this->assertSame('07:30', substr($this->batch->start_time, 0, 5));
        $this->assertSame('11:45', substr($this->batch->end_time, 0, 5));
        $this->assertEquals(1200, $this->batch->monthly_fee);
    }

    public function test_editing_a_batch_does_not_clash_with_itself(): void
    {
        $this->createLibrary();

        // Stretching 08:00-12:00 to 08:00-13:00 overlaps only its own old slot.
        $this->updateBatch($this->batch, ['end_time' => '13:00'])->assertOk();
        // Keeping its own name is fine too.
        $this->updateBatch($this->batch->fresh(), ['monthly_fee' => 900])->assertOk();
    }

    public function test_edit_is_refused_when_it_would_overlap_another_batch(): void
    {
        $this->createLibrary();
        $this->createBatch('14:00', '18:00', 'Afternoon')->assertOk();

        $this->updateBatch($this->batch, ['end_time' => '15:00'])
            ->assertStatus(422)
            ->assertJsonPath('message', 'This time overlaps with Afternoon (2:00 PM - 6:00 PM).');

        $this->assertSame('12:00', substr($this->batch->fresh()->end_time, 0, 5));
    }

    public function test_edit_cannot_take_another_batch_name(): void
    {
        $this->createLibrary();
        $this->createBatch('14:00', '18:00', 'Afternoon')->assertOk();

        $this->updateBatch($this->batch, ['name' => 'Afternoon'])->assertStatus(422)->assertJsonValidationErrors('name');
    }

    public function test_cannot_edit_another_librarys_batch(): void
    {
        $this->createLibrary();
        $otherBatch = $this->batch;
        $this->createLibrary();

        $this->updateBatch($otherBatch, ['name' => 'Hijacked'])->assertNotFound();
        $this->assertSame('Morning', $otherBatch->fresh()->name);
    }

    public function test_batch_list_counts_only_members_currently_in_the_batch(): void
    {
        $this->createLibrary();
        $this->admit('Rahul Sharma', 1, '2026-11-01');
        $former = $this->admit('Priya Verma', 2, '2026-11-01');
        $former->allocations()->update(['status' => 'ended']);

        $this->actingAs($this->owner)->getJson('/api/batches')
            ->assertOk()
            ->assertJsonPath('data.0.allocations_count', 1);
    }

    public function test_can_delete_an_empty_batch(): void
    {
        $this->createLibrary();

        $this->actingAs($this->owner)->deleteJson("/api/batches/{$this->batch->id}")
            ->assertOk()
            ->assertJsonPath('status', true);

        $this->assertModelMissing($this->batch);
    }

    public function test_cannot_delete_a_batch_with_active_members(): void
    {
        $this->createLibrary();
        $this->admit('Rahul Sharma', 1, '2026-11-01');
        $this->admit('Priya Verma', 2, '2026-11-01');

        $this->actingAs($this->owner)->deleteJson("/api/batches/{$this->batch->id}")
            ->assertStatus(422)
            ->assertJsonPath('message', '2 members are in this batch. Move them to another batch or end their membership before deleting it.');

        $this->assertModelExists($this->batch);
    }

    public function test_can_delete_a_batch_whose_members_have_all_left(): void
    {
        $this->createLibrary();
        $member = $this->admit('Rahul Sharma', 1, '2026-11-01');
        $member->allocations()->update(['status' => 'ended']);

        $this->actingAs($this->owner)->deleteJson("/api/batches/{$this->batch->id}")->assertOk();

        $this->assertModelMissing($this->batch);
        $this->assertModelExists($member);
    }

    public function test_cannot_delete_another_librarys_batch(): void
    {
        $this->createLibrary();
        $otherBatch = $this->batch;
        $this->createLibrary();

        $this->actingAs($this->owner)->deleteJson("/api/batches/{$otherBatch->id}")->assertNotFound();
        $this->assertModelExists($otherBatch);
    }
}
