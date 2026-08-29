<?php

namespace Tests\Feature;

use App\Models\ConsultationRequest;
use App\Models\PractitionerType;
use App\Models\Role;
use Tests\TestCase;

/**
 * The practitioner specialties a shopper can ask for.
 *
 * These were a constant in the codebase, so signing up a counsellor meant a
 * deployment before anyone could ask for one. They are rows now, managed by a
 * platform administrator.
 *
 * Consultations keep storing the slug rather than a foreign key, on purpose: a
 * consultation records what was asked for at the time, and has to stay readable
 * after the specialty it named is renamed or withdrawn.
 */
class PractitionerTypeTest extends TestCase
{
    private function admin(): array
    {
        $this->seed(\Database\Seeders\RolesAndPermissionsSeeder::class);

        return $this->tokenFor($this->makeUser([
            'role' => 'admin',
            'role_id' => Role::where('name', 'admin')->value('id'),
        ]));
    }

    public function test_the_original_ten_survived_the_move(): void
    {
        // The list a shopper saw the moment before this shipped is the list
        // they see the moment after.
        foreach (array_keys(ConsultationRequest::PRACTITIONER_TYPES) as $slug) {
            $this->assertDatabaseHas('practitioner_types', ['slug' => $slug, 'is_active' => true]);
        }
    }

    public function test_an_admin_can_add_a_specialty_and_shoppers_can_pick_it(): void
    {
        $this->postJson('/api/v1/admin/practitioner-types', [
            'label' => 'Counsellor',
            'description' => 'Talking therapy and stress',
        ], $this->admin())->assertCreated();

        // Derived, so an administrator never has to know what a slug is.
        $this->assertDatabaseHas('practitioner_types', ['slug' => 'counsellor', 'label' => 'Counsellor']);

        $options = $this->getJson('/api/v1/consultations/practitioner-types')->assertOk()->json('data');

        $this->assertContains('counsellor', array_column($options, 'value'));
    }

    public function test_two_specialties_with_the_same_name_do_not_collide(): void
    {
        $admin = $this->admin();

        $this->postJson('/api/v1/admin/practitioner-types', ['label' => 'Therapist'], $admin)->assertCreated();
        $this->postJson('/api/v1/admin/practitioner-types', ['label' => 'Therapist'], $admin)->assertCreated();

        $this->assertDatabaseHas('practitioner_types', ['slug' => 'therapist']);
        $this->assertDatabaseHas('practitioner_types', ['slug' => 'therapist_2']);
    }

    public function test_a_hidden_specialty_cannot_be_requested(): void
    {
        $type = PractitionerType::firstWhere('slug', 'dentist');

        $this->putJson("/api/v1/admin/practitioner-types/{$type->id}/toggle", [], $this->admin())->assertOk();

        $options = $this->getJson('/api/v1/consultations/practitioner-types')->json('data');
        $this->assertNotContains('dentist', array_column($options, 'value'));

        // And refused on submission too, not merely absent from the picker —
        // the picker is not the only way to reach the endpoint.
        $this->postJson('/api/v1/consultations', [
            'practitioner_type' => 'dentist',
            'subject' => 'A question',
            'message' => 'Please advise on a toothache that has lasted a week.',
            'name' => 'Ada Obi',
            'email' => 'ada@example.test',
        ])->assertStatus(422)->assertJsonValidationErrors('practitioner_type');
    }

    public function test_a_specialty_in_use_is_hidden_rather_than_deleted(): void
    {
        $type = PractitionerType::firstWhere('slug', 'dentist');

        ConsultationRequest::create([
            'reference' => ConsultationRequest::generateReference(),
            'practitioner_type' => 'dentist',
            'subject' => 'Toothache',
            'message' => 'A week of pain on the lower left.',
            'name' => 'Ada Obi',
            'email' => 'ada@example.test',
            'status' => ConsultationRequest::STATUS_OPEN,
            'priority' => 'normal',
        ]);

        $this->deleteJson("/api/v1/admin/practitioner-types/{$type->id}", [], $this->admin())->assertOk();

        // The row survives, so the consultation still reads as "Dentist"
        // rather than a tidied-up slug.
        $this->assertNotNull($type->fresh());
        $this->assertFalse($type->fresh()->is_active);
    }

    public function test_an_unused_specialty_is_deleted_outright(): void
    {
        $this->postJson('/api/v1/admin/practitioner-types', ['label' => 'Podiatrist'], $this->admin())
            ->assertCreated();

        $type = PractitionerType::firstWhere('slug', 'podiatrist');

        $this->deleteJson("/api/v1/admin/practitioner-types/{$type->id}", [], $this->admin())->assertOk();

        $this->assertNull(PractitionerType::find($type->id));
    }

    public function test_a_withdrawn_specialty_still_reads_on_an_old_consultation(): void
    {
        $consultation = ConsultationRequest::create([
            'reference' => ConsultationRequest::generateReference(),
            'practitioner_type' => 'herbalist',
            'subject' => 'A question',
            'message' => 'Asked for a specialty that no longer exists.',
            'name' => 'Ada Obi',
            'email' => 'ada@example.test',
            'status' => ConsultationRequest::STATUS_OPEN,
            'priority' => 'normal',
        ]);

        // No row, and no crash: a person reading the inbox sees words rather
        // than a raw slug.
        $this->assertSame('Herbalist', $consultation->practitionerLabel());
    }

    public function test_a_store_owner_cannot_manage_the_list(): void
    {
        $this->seed(\Database\Seeders\RolesAndPermissionsSeeder::class);

        $owner = $this->makeUser([
            'role' => 'store_owner',
            'role_id' => Role::where('name', 'store_owner')->value('id'),
        ]);

        // Who the platform offers to put people in front of is the platform's
        // decision, not a vendor's.
        $this->getJson('/api/v1/admin/practitioner-types', $this->tokenFor($owner))->assertStatus(403);
    }
}
