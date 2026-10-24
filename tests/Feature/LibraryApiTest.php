<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\Concerns\BuildsLibrary;
use Tests\TestCase;

class LibraryApiTest extends TestCase
{
    use BuildsLibrary, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->createLibrary(seats: 3);
    }

    public function test_can_view_library_profile(): void
    {
        $this->actingAs($this->owner)->getJson('/api/library')
            ->assertOk()
            ->assertJsonPath('data.library_name', 'Gyan Library')
            ->assertJsonPath('data.total_seats', 3)
            ->assertJsonPath('data.amenities', [])
            ->assertJsonCount(0, 'data.photos');
    }

    public function test_can_update_library_name(): void
    {
        $this->actingAs($this->owner)->postJson('/api/library/update', [
            'library_name' => 'New Name Library',
            'total_seats' => 3,
        ])->assertOk()->assertJsonPath('data.library_name', 'New Name Library');

        $this->assertSame('New Name Library', $this->library->fresh()->library_name);
    }

    public function test_increasing_seats_adds_new_seat_rows(): void
    {
        $this->actingAs($this->owner)->postJson('/api/library/update', [
            'library_name' => $this->library->library_name,
            'total_seats' => 5,
        ])->assertOk()->assertJsonPath('data.total_seats', 5);

        $this->assertSame(5, $this->library->seats()->count());
    }

    public function test_decreasing_seats_removes_unoccupied_seat_rows(): void
    {
        $this->actingAs($this->owner)->postJson('/api/library/update', [
            'library_name' => $this->library->library_name,
            'total_seats' => 1,
        ])->assertOk()->assertJsonPath('data.total_seats', 1);

        $this->assertSame(1, $this->library->seats()->count());
    }

    public function test_cannot_decrease_seats_below_an_occupied_seat(): void
    {
        $this->admit('Rahul Sharma', 3, '2026-11-01');

        $this->actingAs($this->owner)->postJson('/api/library/update', [
            'library_name' => $this->library->library_name,
            'total_seats' => 1,
        ])->assertStatus(422);

        $this->assertSame(3, $this->library->seats()->count());
    }

    public function test_can_save_the_newer_amenities(): void
    {
        $this->actingAs($this->owner)->postJson('/api/library/amenities', [
            'amenities' => ['newspaper', 'charging', 'first_aid'],
        ])->assertOk()->assertJsonPath('data.amenities', ['newspaper', 'charging', 'first_aid']);
    }

    public function test_can_update_amenities(): void
    {
        $this->actingAs($this->owner)->postJson('/api/library/amenities', [
            'amenities' => ['ac', 'wifi'],
        ])->assertOk()->assertJsonPath('data.amenities', ['ac', 'wifi']);

        $this->assertSame(['ac', 'wifi'], $this->library->fresh()->amenities);
    }

    public function test_rejects_invalid_amenity(): void
    {
        $this->actingAs($this->owner)->postJson('/api/library/amenities', [
            'amenities' => ['ac', 'swimming_pool'],
        ])->assertStatus(422);
    }

    public function test_can_upload_and_delete_a_photo(): void
    {
        Storage::fake('public');

        $upload = $this->actingAs($this->owner)->postJson('/api/library/photos', [
            'photo' => UploadedFile::fake()->image('library.jpg'),
        ])->assertOk();

        $photoId = $upload->json('data.id');
        $this->assertSame(1, $this->library->photos()->count());

        $this->actingAs($this->owner)->deleteJson("/api/library/photos/{$photoId}")->assertOk();
        $this->assertSame(0, $this->library->photos()->count());
    }

    public function test_rejects_uploading_more_than_the_photo_limit(): void
    {
        Storage::fake('public');

        for ($i = 0; $i < 10; $i++) {
            $this->library->photos()->create(['path' => "library-photos/{$this->library->id}/photo-{$i}.jpg"]);
        }

        $this->actingAs($this->owner)->postJson('/api/library/photos', [
            'photo' => UploadedFile::fake()->image('library.jpg'),
        ])->assertStatus(422);
    }

    public function test_cannot_manage_another_librarys_photos(): void
    {
        Storage::fake('public');
        $myOwner = $this->owner;

        $this->createLibrary();
        $stranger = $this->library->photos()->create(['path' => 'library-photos/999/stray.jpg']);

        $this->actingAs($myOwner)->deleteJson("/api/library/photos/{$stranger->id}")->assertNotFound();
    }

    public function test_can_set_library_location(): void
    {
        $this->actingAs($this->owner)->postJson('/api/library/location', [
            'address' => '12 MG Road, Patna',
            'latitude' => 25.6093239,
            'longitude' => 85.1235252,
        ])->assertOk()
            ->assertJsonPath('data.address', '12 MG Road, Patna')
            ->assertJsonPath('data.latitude', 25.6093239)
            ->assertJsonPath('data.longitude', 85.1235252);

        $this->actingAs($this->owner)->getJson('/api/dashboard')
            ->assertJsonPath('data.library_address', '12 MG Road, Patna');
    }

    public function test_rejects_location_without_valid_coordinates(): void
    {
        $this->actingAs($this->owner)->postJson('/api/library/location', [
            'address' => '12 MG Road, Patna',
            'latitude' => 120,
        ])->assertStatus(422)->assertJsonValidationErrors(['latitude', 'longitude']);
    }
}
