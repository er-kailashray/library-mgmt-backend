<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\Concerns\BuildsLibrary;
use Tests\TestCase;

class MemberDetailApiTest extends TestCase
{
    use BuildsLibrary, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->createLibrary(seats: 3);
    }

    public function test_can_view_member_detail_with_allocations(): void
    {
        $member = $this->admit('Rahul Sharma', 1, '2026-11-01');

        $this->actingAs($this->owner)->getJson("/api/members/{$member->id}")
            ->assertOk()
            ->assertJsonPath('status', true)
            ->assertJsonPath('data.name', 'Rahul Sharma')
            ->assertJsonPath('data.status', 'active')
            ->assertJsonCount(1, 'data.allocations')
            ->assertJsonPath('data.allocations.0.batch_name', 'Morning')
            ->assertJsonPath('data.allocations.0.seat_number', 1);
    }

    public function test_member_detail_reports_no_id_proof_by_default(): void
    {
        $member = $this->admit('Rahul Sharma', 1, '2026-11-01');

        $this->actingAs($this->owner)->getJson("/api/members/{$member->id}")
            ->assertJsonPath('data.has_id_proof_front', false)
            ->assertJsonPath('data.has_id_proof_back', false)
            ->assertJsonPath('data.id_proof_type', null);
    }

    public function test_can_add_both_front_and_back_id_proof_images(): void
    {
        Storage::fake('local');
        $member = $this->admit('Rahul Sharma', 1, '2026-11-01');

        $this->actingAs($this->owner)->postJson("/api/members/{$member->id}/id-proof", [
            'id_proof_type' => 'aadhaar',
            'side' => 'front',
            'id_proof' => UploadedFile::fake()->image('aadhaar-front.jpg'),
        ])->assertOk();

        $this->actingAs($this->owner)->postJson("/api/members/{$member->id}/id-proof", [
            'id_proof_type' => 'aadhaar',
            'side' => 'back',
            'id_proof' => UploadedFile::fake()->image('aadhaar-back.jpg'),
        ])->assertOk();

        $this->actingAs($this->owner)->getJson("/api/members/{$member->id}")
            ->assertJsonPath('data.has_id_proof_front', true)
            ->assertJsonPath('data.has_id_proof_back', true)
            ->assertJsonPath('data.id_proof_type', 'aadhaar');

        $this->actingAs($this->owner)->get("/api/members/{$member->id}/id-proof/front")
            ->assertOk()->assertHeader('content-type', 'image/jpeg');
        $this->actingAs($this->owner)->get("/api/members/{$member->id}/id-proof/back")
            ->assertOk()->assertHeader('content-type', 'image/jpeg');
    }

    public function test_back_image_is_optional(): void
    {
        Storage::fake('local');
        $member = $this->admit('Rahul Sharma', 1, '2026-11-01');

        $this->actingAs($this->owner)->postJson("/api/members/{$member->id}/id-proof", [
            'id_proof_type' => 'pan',
            'side' => 'front',
            'id_proof' => UploadedFile::fake()->image('pan-front.jpg'),
        ])->assertOk();

        $this->actingAs($this->owner)->get("/api/members/{$member->id}/id-proof/back")->assertNotFound();
    }

    public function test_id_proof_endpoint_404s_when_none_on_file(): void
    {
        $member = $this->admit('Rahul Sharma', 1, '2026-11-01');

        $this->actingAs($this->owner)->getJson("/api/members/{$member->id}/id-proof/front")->assertNotFound();
    }

    public function test_id_proof_requires_a_valid_side(): void
    {
        Storage::fake('local');
        $member = $this->admit('Rahul Sharma', 1, '2026-11-01');

        $this->actingAs($this->owner)->postJson("/api/members/{$member->id}/id-proof", [
            'id_proof_type' => 'pan',
            'side' => 'sideways',
            'id_proof' => UploadedFile::fake()->image('pan.jpg'),
        ])->assertStatus(422)->assertJsonValidationErrors('side');
    }

    public function test_cannot_view_another_librarys_id_proof(): void
    {
        Storage::fake('local');
        $myOwner = $this->owner;

        $this->createLibrary();
        $stranger = $this->admit('Stranger', 1, '2026-10-10');
        $this->actingAs($this->owner)->postJson("/api/members/{$stranger->id}/id-proof", [
            'id_proof_type' => 'pan',
            'side' => 'front',
            'id_proof' => UploadedFile::fake()->image('pan.jpg'),
        ])->assertOk();

        $this->actingAs($myOwner)->getJson("/api/members/{$stranger->id}/id-proof/front")->assertNotFound();
    }

    public function test_can_update_member_name_and_phone(): void
    {
        $member = $this->admit('Rahul Sharma', 1, '2026-11-01');

        $this->actingAs($this->owner)->postJson("/api/members/{$member->id}/update", [
            'name' => 'Rahul S. Sharma',
            'phone' => '9123456780',
        ])->assertOk()->assertJsonPath('status', true);

        $member->refresh();
        $this->assertSame('Rahul S. Sharma', $member->name);
        $this->assertSame('9123456780', $member->phone);
    }

    public function test_cannot_change_phone_of_a_member_who_has_left(): void
    {
        $member = $this->admit('Rahul Sharma', 1, '2026-11-01');
        $this->actingAs($this->owner)->postJson("/api/members/{$member->id}/deactivate")->assertOk();

        $this->actingAs($this->owner)->postJson("/api/members/{$member->id}/update", [
            'name' => 'Rahul Sharma',
            'phone' => '9123456780',
        ])->assertStatus(422);

        $this->assertSame($member->phone, $member->fresh()->phone);
    }

    public function test_can_rename_a_member_who_has_left_without_changing_their_phone(): void
    {
        $member = $this->admit('Rahul Sharma', 1, '2026-11-01');
        $this->actingAs($this->owner)->postJson("/api/members/{$member->id}/deactivate")->assertOk();

        $this->actingAs($this->owner)->postJson("/api/members/{$member->id}/update", [
            'name' => 'Rahul Sharma (typo fixed)',
            'phone' => $member->phone,
        ])->assertOk();

        $this->assertSame('Rahul Sharma (typo fixed)', $member->fresh()->name);
    }

    public function test_can_update_member_gender(): void
    {
        $member = $this->admit('Rahul Sharma', 1, '2026-11-01');

        $this->actingAs($this->owner)->postJson("/api/members/{$member->id}/update", [
            'name' => $member->name,
            'phone' => $member->phone,
            'gender' => 'male',
        ])->assertOk()->assertJsonPath('data.gender', 'male');

        $this->assertSame('male', $member->fresh()->gender);
    }

    public function test_update_rejects_invalid_gender(): void
    {
        $member = $this->admit('Rahul Sharma', 1, '2026-11-01');

        $this->actingAs($this->owner)->postJson("/api/members/{$member->id}/update", [
            'name' => $member->name,
            'phone' => $member->phone,
            'gender' => 'not-a-gender',
        ])->assertStatus(422)->assertJsonValidationErrors('gender');
    }

    public function test_update_rejects_phone_already_used_by_another_member_in_same_library(): void
    {
        $first = $this->admit('Rahul Sharma', 1, '2026-11-01');
        $second = $this->admit('Second Student', 2, '2026-11-01');

        $this->actingAs($this->owner)->postJson("/api/members/{$second->id}/update", [
            'name' => 'Second Student',
            'phone' => $first->phone,
        ])->assertStatus(422)->assertJsonValidationErrors('phone');
    }

    public function test_update_allows_keeping_the_same_phone(): void
    {
        $member = $this->admit('Rahul Sharma', 1, '2026-11-01');

        $this->actingAs($this->owner)->postJson("/api/members/{$member->id}/update", [
            'name' => 'Rahul Sharma Jr.',
            'phone' => $member->phone,
        ])->assertOk();

        $this->assertSame('Rahul Sharma Jr.', $member->fresh()->name);
    }

    public function test_cannot_update_another_librarys_member(): void
    {
        $myOwner = $this->owner;

        $this->createLibrary();
        $stranger = $this->admit('Stranger', 1, '2026-10-10');

        $this->actingAs($myOwner)->postJson("/api/members/{$stranger->id}/update", [
            'name' => 'Hacked Name',
            'phone' => $stranger->phone,
        ])->assertNotFound();
    }

    public function test_can_add_id_proof_to_a_member_admitted_without_one(): void
    {
        Storage::fake('local');
        $member = $this->admit('Rahul Sharma', 1, '2026-11-01');

        $this->actingAs($this->owner)->postJson("/api/members/{$member->id}/id-proof", [
            'id_proof_type' => 'pan',
            'side' => 'front',
            'id_proof' => UploadedFile::fake()->image('pan.jpg'),
        ])->assertOk()->assertJsonPath('status', true);

        $member->refresh();
        $this->assertSame('pan', $member->id_proof_type);
        $this->assertNotNull($member->id_proof_front_path);
        Storage::disk('local')->assertExists($member->id_proof_front_path);
    }

    public function test_updating_id_proof_replaces_the_old_file_for_the_same_side(): void
    {
        Storage::fake('local');
        $member = $this->admit('Rahul Sharma', 1, '2026-11-01');

        $this->actingAs($this->owner)->postJson("/api/members/{$member->id}/id-proof", [
            'id_proof_type' => 'aadhaar',
            'side' => 'front',
            'id_proof' => UploadedFile::fake()->image('aadhaar-1.jpg'),
        ])->assertOk();
        $originalPath = $member->fresh()->id_proof_front_path;

        $this->actingAs($this->owner)->postJson("/api/members/{$member->id}/id-proof", [
            'id_proof_type' => 'pan',
            'side' => 'front',
            'id_proof' => UploadedFile::fake()->image('pan.jpg'),
        ])->assertOk();

        $member->refresh();
        $this->assertSame('pan', $member->id_proof_type);
        $this->assertNotSame($originalPath, $member->id_proof_front_path);
        Storage::disk('local')->assertMissing($originalPath);
        Storage::disk('local')->assertExists($member->id_proof_front_path);
    }

    public function test_cannot_update_id_proof_for_another_librarys_member(): void
    {
        Storage::fake('local');
        $myOwner = $this->owner;

        $this->createLibrary();
        $stranger = $this->admit('Stranger', 1, '2026-10-10');

        $this->actingAs($myOwner)->postJson("/api/members/{$stranger->id}/id-proof", [
            'id_proof_type' => 'pan',
            'side' => 'front',
            'id_proof' => UploadedFile::fake()->image('pan.jpg'),
        ])->assertNotFound();
    }

    public function test_can_deactivate_member_and_free_their_seat(): void
    {
        $member = $this->admit('Rahul Sharma', 1, '2026-11-01');

        $this->actingAs($this->owner)->postJson("/api/members/{$member->id}/deactivate")
            ->assertOk()
            ->assertJsonPath('status', true);

        $this->assertSame('inactive', $member->fresh()->status);
        $this->assertSame('ended', $member->allocations()->first()->fresh()->status);

        $this->actingAs($this->owner)->getJson('/api/seat-matrix')
            ->assertJsonPath('data.seats.0.batches.0.available', true);
    }

    public function test_deactivated_member_disappears_from_members_list(): void
    {
        $member = $this->admit('Rahul Sharma', 1, '2026-11-01');

        $this->actingAs($this->owner)->postJson("/api/members/{$member->id}/deactivate")->assertOk();

        $this->actingAs($this->owner)->getJson('/api/members')->assertJsonCount(0, 'data');
    }

    public function test_cannot_view_or_deactivate_another_librarys_member(): void
    {
        $myOwner = $this->owner;

        $this->createLibrary();
        $stranger = $this->admit('Stranger', 1, '2026-10-10');

        $this->actingAs($myOwner)->getJson("/api/members/{$stranger->id}")->assertNotFound();
        $this->actingAs($myOwner)->postJson("/api/members/{$stranger->id}/deactivate")->assertNotFound();
    }

    public function test_member_detail_requires_authentication(): void
    {
        $member = $this->admit('Rahul Sharma', 1, '2026-11-01');

        $this->getJson("/api/members/{$member->id}")->assertUnauthorized();
    }
}
