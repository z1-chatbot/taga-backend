<?php

namespace Database\Factories;

use App\Models\Prescription;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Prescription>
 */
class PrescriptionFactory extends Factory
{
    protected $model = Prescription::class;

    public function definition(): array
    {
        return [
            // A path on the private disk. Tests never write a real file unless
            // they are exercising the download endpoint.
            'file_path' => 'prescriptions/'.$this->faker->uuid().'.pdf',
            'original_filename' => 'script.pdf',
            'mime_type' => 'application/pdf',
            'file_size' => 1024,
            'status' => Prescription::STATUS_PENDING,
            'patient_name' => $this->faker->name(),
            'doctor_name' => 'Dr '.$this->faker->lastName(),
            'issued_date' => now()->subDays(3)->toDateString(),
            'expires_at' => now()->addMonths(5),
        ];
    }

    public function approved(): static
    {
        return $this->state(fn () => [
            'status' => Prescription::STATUS_APPROVED,
            'reviewed_at' => now(),
        ]);
    }

    public function rejected(string $reason = 'Illegible'): static
    {
        return $this->state(fn () => [
            'status' => Prescription::STATUS_REJECTED,
            'rejection_reason' => $reason,
            'reviewed_at' => now(),
        ]);
    }

    /** Approved, but past its validity window. */
    public function lapsed(): static
    {
        return $this->state(fn () => [
            'status' => Prescription::STATUS_APPROVED,
            'reviewed_at' => now()->subMonths(8),
            'expires_at' => now()->subDay(),
        ]);
    }
}
