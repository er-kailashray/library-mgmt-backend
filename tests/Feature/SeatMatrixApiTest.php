<?php

namespace Tests\Feature;

use App\Models\Library;
use App\Models\Seat;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\BuildsLibrary;
use Tests\TestCase;

class SeatMatrixApiTest extends TestCase
{
    use BuildsLibrary, RefreshDatabase;

    public function test_returns_seat_matrix_with_availability(): void
    {
        $this->createLibrary(seats: 3);
        $this->admit('Rahul Sharma', 1, '2026-11-01');

        $this->actingAs($this->owner)->getJson('/api/seat-matrix')
            ->assertOk()
            ->assertJsonPath('status', true)
            ->assertJsonCount(1, 'data.batches')
            ->assertJsonCount(3, 'data.seats')
            ->assertJsonPath('data.seats.0.batches.0.available', false)
            ->assertJsonPath('data.seats.1.batches.0.available', true);
    }

    /**
     * A freshly registered library has seats (created at registration) but no
     * batches yet. This must be a normal empty response, not a 404 - the
     * mobile app renders this as an empty state, not an error, and treating
     * "nothing configured yet" as an error broke that UI.
     */
    public function test_returns_empty_matrix_when_library_has_no_batches(): void
    {
        $owner = User::factory()->create();
        $library = Library::create([
            'user_id' => $owner->id,
            'library_name' => 'Fresh Library',
            'total_seats' => 2,
        ]);
        Seat::create(['library_id' => $library->id, 'seat_number' => 1]);
        Seat::create(['library_id' => $library->id, 'seat_number' => 2]);

        $this->actingAs($owner)->getJson('/api/seat-matrix')
            ->assertOk()
            ->assertJsonPath('status', true)
            ->assertJsonPath('data.batches', [])
            ->assertJsonCount(2, 'data.seats')
            ->assertJsonPath('data.seats.0.batches', []);
    }

    public function test_seat_matrix_requires_authentication(): void
    {
        $this->getJson('/api/seat-matrix')->assertUnauthorized();
    }
}
