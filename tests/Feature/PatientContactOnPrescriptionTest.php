<?php

namespace Tests\Feature;

use App\Models\Prescription;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * Reaching the patient, not just the prescriber.
 *
 * The upload form already carried `doctor_email` and `doctor_phone` so a
 * pharmacist could verify a prescription at source. Nothing let them reach the
 * person the medicine is for — and that is not the same person often enough to
 * matter: prescriptions are routinely uploaded by a relative, and a guest can
 * upload before any account exists, which leaves no address on file at all.
 *
 * Both fields are optional, and stay optional. A shopper photographing a paper
 * prescription on a phone must not be stopped from uploading over a phone
 * number they did not type in.
 */
class PatientContactOnPrescriptionTest extends TestCase
{
    private function upload(array $extra = [])
    {
        Storage::fake('local');

        return $this->postJson('/api/v1/prescriptions', array_merge([
            'file' => UploadedFile::fake()->image('script.jpg'),
        ], $extra), $this->tokenFor($this->makeUser()));
    }

    public function test_the_patients_email_and_phone_are_stored_and_returned(): void
    {
        $response = $this->upload([
            'patient_name' => 'Amaka Obi',
            'patient_email' => 'amaka@example.com',
            'patient_phone' => '+2348012345678',
        ])->assertCreated();

        $response
            ->assertJsonPath('data.patient_email', 'amaka@example.com')
            ->assertJsonPath('data.patient_phone', '+2348012345678');

        $this->assertDatabaseHas('prescriptions', [
            'id' => $response->json('data.id'),
            'patient_email' => 'amaka@example.com',
            'patient_phone' => '+2348012345678',
        ]);
    }

    public function test_both_fields_are_optional(): void
    {
        $response = $this->upload()->assertCreated();

        $this->assertNull($response->json('data.patient_email'));
        $this->assertNull($response->json('data.patient_phone'));
    }

    /**
     * The patient's address is validated as one. A pharmacist acting on a
     * mistyped address is worse than a pharmacist with no address at all,
     * because only one of those two looks like a dead end.
     */
    public function test_a_malformed_patient_email_is_rejected(): void
    {
        $this->upload(['patient_email' => 'not-an-address'])
            ->assertStatus(422)
            ->assertJsonValidationErrors('patient_email');
    }

    /**
     * The prescriber pair is a separate thing and must not be overwritten by
     * the patient pair — a pharmacist ringing the "prescriber" and reaching the
     * patient is a worse failure than either field being blank.
     */
    public function test_the_patient_and_the_prescriber_are_kept_apart(): void
    {
        $response = $this->upload([
            'patient_email' => 'amaka@example.com',
            'patient_phone' => '+2348012345678',
            'doctor_email' => 'clinic@example.com',
            'doctor_phone' => '+2349099999999',
        ])->assertCreated();

        $prescription = Prescription::find($response->json('data.id'));

        $this->assertSame('amaka@example.com', $prescription->patient_email);
        $this->assertSame('clinic@example.com', $prescription->doctor_email);
        $this->assertSame('+2348012345678', $prescription->patient_phone);
        $this->assertSame('+2349099999999', $prescription->doctor_phone);
    }
}
