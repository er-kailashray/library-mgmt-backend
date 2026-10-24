<?php

namespace Tests\Feature;

use App\Models\Library;
use App\Models\Member;
use App\Models\Payment;
use App\Models\SeatAllocation;
use App\Models\User;
use Database\Seeders\DemoLibrarySeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class DemoLibrarySeederTest extends TestCase
{
    use RefreshDatabase;

    public function test_seeds_a_full_demo_library_that_the_app_can_load(): void
    {
        $this->travelTo('2026-10-24 11:30:00');
        $this->seed(DemoLibrarySeeder::class);

        $owner = User::where('phone', DemoLibrarySeeder::OWNER_PHONE)->firstOrFail();
        $library = Library::where('user_id', $owner->id)->firstOrFail();

        $this->assertGreaterThanOrEqual(40, Member::where('library_id', $library->id)->count());
        $this->assertSame(3, Member::where('library_id', $library->id)->where('status', 'inactive')->count());
        $this->assertGreaterThan(0, Payment::where('library_id', $library->id)->where('status', 'partial')->count());
        $this->assertSame(1, Payment::where('library_id', $library->id)->where('status', 'refunded')->count());
        // Joined on many different dates.
        $this->assertGreaterThan(20, Member::where('library_id', $library->id)->distinct()->count('join_date'));
        // No seat is given to two people in the same batch.
        $clash = SeatAllocation::where('library_id', $library->id)->where('status', 'active')
            ->selectRaw('batch_id, seat_id, count(*) as c')->groupBy('batch_id', 'seat_id')->having('c', '>', 1)->count();
        $this->assertSame(0, $clash);

        // Every main screen loads.
        foreach (['/api/dashboard', '/api/members', '/api/payments', '/api/attendance', '/api/renewals/upcoming', '/api/seat-matrix', '/api/batches'] as $url) {
            $this->actingAs($owner)->getJson($url)->assertOk();
        }
        $dashboard = $this->actingAs($owner)->getJson('/api/dashboard')->json('data');
        $this->assertGreaterThan(0, $dashboard['due_members_count']);
        $this->assertGreaterThan(0, $dashboard['monthly_collection']);
    }

    public function test_running_it_again_replaces_the_demo_library(): void
    {
        $this->seed(DemoLibrarySeeder::class);
        $this->seed(DemoLibrarySeeder::class);

        $this->assertSame(1, User::where('phone', DemoLibrarySeeder::OWNER_PHONE)->count());
        $this->assertSame(1, Library::count());
    }
}
