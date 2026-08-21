<?php

namespace Tests\Feature;

use App\Models\Prescription;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * Prescriptions are medical records. Two properties are load-bearing:
 *
 *   1. The file lives on the private disk and is only ever streamed through an
 *      authorising endpoint — never a public URL.
 *   2. Someone else's prescription returns 404, not 403, so ids cannot be
 *      probed to learn which ones exist.
 */
class PrescriptionAccessTest extends TestCase
{
    public function test_another_users_prescription_returns_404_not_403(): void
    {
        $owner = $this->makeUser();
        $stranger = $this->makeUser();

        $prescription = Prescription::factory()->create(['user_id' => $owner->id]);

        // 403 would confirm the record exists. 404 must be indistinguishable
        // from a prescription that was never there.
        $this->getJson("/api/v1/prescriptions/{$prescription->id}", $this->tokenFor($stranger))
            ->assertStatus(404);
    }

    public function test_a_missing_prescription_returns_the_same_404(): void
    {
        $stranger = $this->makeUser();

        $this->getJson('/api/v1/prescriptions/99999999', $this->tokenFor($stranger))
            ->assertStatus(404);
    }

    public function test_downloading_another_users_prescription_returns_404(): void
    {
        $owner = $this->makeUser();
        $stranger = $this->makeUser();

        $prescription = Prescription::factory()->create(['user_id' => $owner->id]);

        $this->getJson("/api/v1/prescriptions/{$prescription->id}/download", $this->tokenFor($stranger))
            ->assertStatus(404);
    }

    public function test_the_owner_can_read_their_own_prescription(): void
    {
        $owner = $this->makeUser();
        $prescription = Prescription::factory()->create(['user_id' => $owner->id]);

        $this->getJson("/api/v1/prescriptions/{$prescription->id}", $this->tokenFor($owner))
            ->assertOk()
            ->assertJsonPath('data.id', $prescription->id);
    }

    public function test_an_upload_is_stored_on_the_private_disk(): void
    {
        Storage::fake('local');

        $user = $this->makeUser();

        $response = $this->post('/api/v1/prescriptions', [
            'file' => UploadedFile::fake()->create('script.pdf', 200, 'application/pdf'),
            'patient_name' => 'Ada Obi',
        ], $this->tokenFor($user));

        $response->assertSuccessful();

        $prescription = Prescription::latest('id')->first();

        $this->assertNotNull($prescription);
        Storage::disk('local')->assertExists($prescription->file_path);

        // The stored path must not be something a browser could fetch directly.
        $this->assertStringNotContainsString('public', $prescription->file_path);
        $this->assertStringStartsWith('prescriptions/', $prescription->file_path);
    }

    public function test_an_upload_rejects_a_disallowed_file_type(): void
    {
        Storage::fake('local');

        $user = $this->makeUser();

        $this->post('/api/v1/prescriptions', [
            'file' => UploadedFile::fake()->create('payload.exe', 10, 'application/octet-stream'),
        ], $this->tokenFor($user))->assertStatus(422);
    }

    public function test_an_upload_rejects_a_future_issue_date(): void
    {
        Storage::fake('local');

        $user = $this->makeUser();

        $this->post('/api/v1/prescriptions', [
            'file' => UploadedFile::fake()->create('script.pdf', 100, 'application/pdf'),
            'issued_date' => now()->addWeek()->toDateString(),
        ], $this->tokenFor($user))->assertStatus(422);
    }

    public function test_listing_requires_authentication_or_a_session(): void
    {
        $this->getJson('/api/v1/prescriptions')->assertStatus(401);
    }

    public function test_a_shopper_only_sees_their_own_prescriptions(): void
    {
        $owner = $this->makeUser();
        $stranger = $this->makeUser();

        Prescription::factory()->count(2)->create(['user_id' => $owner->id]);
        Prescription::factory()->create(['user_id' => $stranger->id]);

        $response = $this->getJson('/api/v1/prescriptions', $this->tokenFor($owner))->assertOk();

        $ids = collect($response->json('data.data') ?? $response->json('data'))->pluck('id');

        $this->assertCount(2, $ids);
    }
}
