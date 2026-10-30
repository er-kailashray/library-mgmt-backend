<?php

namespace Tests\Feature;

use App\Models\Batch;
use App\Models\Seat;
use App\Models\SeatBlock;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\BuildsLibrary;
use Tests\TestCase;

/** Taking a seat out of use for a while: in every batch or some, until a date or until unblocked. */
class SeatBlockApiTest extends TestCase
{
    use BuildsLibrary, RefreshDatabase;

    private Batch $evening;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo('2026-11-10 10:00:00');
        $this->createLibrary(seats: 5);
        $this->evening = Batch::create(['library_id' => $this->library->id, 'name' => 'Evening', 'start_time' => '16:00', 'end_time' => '20:00', 'monthly_fee' => 800]);
    }

    private function seatId(int $number): int
    {
        return Seat::where('library_id', $this->library->id)->where('seat_number', $number)->value('id');
    }

    /** The seat-matrix entry for one seat in one batch. */
    private function cell(int $seatNumber, int $batchId): array
    {
        $seats = $this->actingAs($this->owner)->getJson('/api/seat-matrix')->assertOk()->json('data.seats');
        $seat = collect($seats)->firstWhere('seat_number', $seatNumber);

        return collect($seat['batches'])->firstWhere('batch_id', $batchId);
    }

    public function test_blocking_in_every_batch_takes_the_seat_out_of_use(): void
    {
        $this->actingAs($this->owner)
            ->postJson("/api/seats/{$this->seatId(2)}/block", ['reason' => 'Broken chair'])
            ->assertCreated()
            ->assertJsonPath('data.0.all_batches', true)
            ->assertJsonPath('data.0.ends_on', null);

        foreach ([$this->batch->id, $this->evening->id] as $batchId) {
            $cell = $this->cell(2, $batchId);
            $this->assertFalse($cell['available']);
            $this->assertSame('Broken chair', $cell['blocked']['reason']);
        }
    }

    public function test_blocking_some_batches_leaves_the_others_free(): void
    {
        $this->actingAs($this->owner)
            ->postJson("/api/seats/{$this->seatId(2)}/block", ['batch_ids' => [$this->evening->id], 'ends_on' => '2026-11-15'])
            ->assertCreated();

        $this->assertTrue($this->cell(2, $this->batch->id)['available']);
        $this->assertFalse($this->cell(2, $this->evening->id)['available']);
    }

    public function test_a_block_runs_out_by_itself_after_its_last_day(): void
    {
        $this->actingAs($this->owner)->postJson("/api/seats/{$this->seatId(2)}/block", ['ends_on' => '2026-11-12'])->assertCreated();

        $this->travelTo('2026-11-12 18:00:00');
        $this->assertFalse($this->cell(2, $this->batch->id)['available']);

        $this->travelTo('2026-11-13 09:00:00');
        $this->assertTrue($this->cell(2, $this->batch->id)['available']);
        $this->assertNull($this->cell(2, $this->batch->id)['blocked']);
    }

    public function test_a_block_set_up_ahead_starts_on_its_day(): void
    {
        $this->actingAs($this->owner)->postJson("/api/seats/{$this->seatId(2)}/block", ['starts_on' => '2026-11-20'])->assertCreated();

        $this->assertTrue($this->cell(2, $this->batch->id)['available']);
        // Listed on the seat so the owner can see and remove it.
        $seat = collect($this->actingAs($this->owner)->getJson('/api/seat-matrix')->json('data.seats'))->firstWhere('seat_number', 2);
        $this->assertSame('2026-11-20', $seat['blocks'][0]['starts_on']);

        $this->travelTo('2026-11-20 09:00:00');
        $this->assertFalse($this->cell(2, $this->batch->id)['available']);
    }

    public function test_cannot_block_where_someone_sits(): void
    {
        $this->admit('Rahul Sharma', 2, '2026-11-25', $this->evening);

        $this->actingAs($this->owner)
            ->postJson("/api/seats/{$this->seatId(2)}/block")
            ->assertUnprocessable()
            ->assertJsonValidationErrors('batch_ids');

        // The batch where it's free can still be blocked.
        $this->actingAs($this->owner)
            ->postJson("/api/seats/{$this->seatId(2)}/block", ['batch_ids' => [$this->batch->id]])
            ->assertCreated();
    }

    public function test_rejects_overlapping_blocks_and_bad_dates(): void
    {
        $this->actingAs($this->owner)->postJson("/api/seats/{$this->seatId(2)}/block", ['batch_ids' => [$this->batch->id]])->assertCreated();

        $this->actingAs($this->owner)
            ->postJson("/api/seats/{$this->seatId(2)}/block")
            ->assertJsonValidationErrors('starts_on');

        $this->actingAs($this->owner)
            ->postJson("/api/seats/{$this->seatId(3)}/block", ['starts_on' => '2026-11-01'])
            ->assertJsonValidationErrors('starts_on');

        $this->actingAs($this->owner)
            ->postJson("/api/seats/{$this->seatId(3)}/block", ['starts_on' => '2026-11-20', 'ends_on' => '2026-11-15'])
            ->assertJsonValidationErrors('ends_on');
    }

    public function test_admission_and_seat_change_skip_blocked_seats(): void
    {
        $this->actingAs($this->owner)->postJson("/api/seats/{$this->seatId(2)}/block")->assertCreated();

        $this->actingAs($this->owner)->postJson('/api/admission', [
            'name' => 'Priya Verma',
            'phone' => '9876543211',
            'allocations' => [['batch_id' => $this->batch->id, 'seat_id' => $this->seatId(2)]],
        ])->assertUnprocessable();

        $member = $this->admit('Rahul Sharma', 1, '2026-11-25');
        $this->actingAs($this->owner)
            ->postJson("/api/members/{$member->id}/change-seat", ['batch_id' => $this->batch->id, 'seat_id' => $this->seatId(2)])
            ->assertJsonValidationErrors('seat_id');
    }

    public function test_blocked_seats_leave_the_occupancy_total(): void
    {
        $this->admit('Rahul Sharma', 1, '2026-11-25');
        $this->actingAs($this->owner)->postJson("/api/seats/{$this->seatId(2)}/block", ['batch_ids' => [$this->batch->id]])->assertCreated();

        $occupancy = collect($this->actingAs($this->owner)->getJson('/api/dashboard')->json('data.batch_occupancy'));
        $morning = $occupancy->firstWhere('id', $this->batch->id);
        $this->assertSame(4, $morning['total']);
        $this->assertSame(1, $morning['blocked']);
        $this->assertSame(5, $occupancy->firstWhere('id', $this->evening->id)['total']);

        $reportRow = collect($this->actingAs($this->owner)->getJson('/api/reports')->json('data.members.occupancy'))->firstWhere('batch_id', $this->batch->id);
        $this->assertSame(4, $reportRow['total']);
    }

    public function test_unblocking_puts_the_seat_back_and_keeps_past_days(): void
    {
        $this->actingAs($this->owner)->postJson("/api/seats/{$this->seatId(2)}/block")->assertCreated();
        $block = SeatBlock::sole();

        $this->travelTo('2026-11-14 10:00:00');
        $this->actingAs($this->owner)->deleteJson("/api/seat-blocks/{$block->id}")->assertOk();

        $this->assertTrue($this->cell(2, $this->batch->id)['available']);
        $this->assertSame('2026-11-13', $block->fresh()->ends_on->toDateString());
    }

    public function test_unblocking_a_block_that_has_not_started_removes_it(): void
    {
        $this->actingAs($this->owner)->postJson("/api/seats/{$this->seatId(2)}/block", ['starts_on' => '2026-11-20'])->assertCreated();

        $this->actingAs($this->owner)->deleteJson('/api/seat-blocks/'.SeatBlock::sole()->id)->assertOk();

        $this->assertSame(0, SeatBlock::count());
    }

    public function test_unblocking_a_block_that_already_ended_changes_nothing(): void
    {
        $this->actingAs($this->owner)->postJson("/api/seats/{$this->seatId(2)}/block", ['ends_on' => '2026-11-12'])->assertCreated();
        $block = SeatBlock::sole();

        $this->travelTo('2026-11-20 10:00:00');
        $this->actingAs($this->owner)->deleteJson("/api/seat-blocks/{$block->id}")->assertOk();

        $this->assertSame('2026-11-12', $block->fresh()->ends_on->toDateString());
    }

    public function test_another_library_cannot_block_or_unblock(): void
    {
        $this->actingAs($this->owner)->postJson("/api/seats/{$this->seatId(2)}/block")->assertCreated();
        $seatId = $this->seatId(3);
        $blockId = SeatBlock::sole()->id;

        $this->createLibrary(seats: 2);

        $this->actingAs($this->owner)->postJson("/api/seats/{$seatId}/block")->assertNotFound();
        $this->actingAs($this->owner)->deleteJson("/api/seat-blocks/{$blockId}")->assertNotFound();
        $this->assertSame(1, SeatBlock::count());
    }
}
