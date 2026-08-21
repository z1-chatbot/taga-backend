<?php

namespace Tests\Feature;

use App\Mail\ConsultationReceivedEmail;
use App\Mail\ConsultationReplyEmail;
use App\Models\ConsultationRequest;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

/**
 * Consultation requests raised from the storefront widget.
 *
 * The properties worth holding on to:
 *
 *   1. A guest owns their request by session id alone, and somebody else's is
 *      indistinguishable from one that never existed.
 *   2. Internal notes never reach the requester, on any customer endpoint.
 *   3. A settled ticket takes no further replies from either side.
 *   4. Replying emails the requester; leaving a note does not.
 */
class ConsultationTicketTest extends TestCase
{
    private array $validPayload = [
        'practitioner_type' => 'dentist',
        'name' => 'Ada Obi',
        'email' => 'ada@example.test',
        'phone' => '08030000000',
        'preferred_contact' => 'phone',
        'subject' => 'Toothache',
        'message' => 'A sore molar for three days.',
    ];

    private function raiseAsGuest(string $guestId = 'test-guest', array $overrides = []): ConsultationRequest
    {
        Mail::fake();

        $response = $this->postJson(
            '/api/v1/consultations',
            array_merge($this->validPayload, $overrides),
            $this->guestHeaders($guestId)
        )->assertStatus(201);

        return ConsultationRequest::where('reference', $response->json('data.reference'))->firstOrFail();
    }

    private function adminHeaders(): array
    {
        return $this->tokenFor($this->makeUser(['role' => 'admin']));
    }

    public function test_a_guest_can_raise_a_request_and_is_given_a_reference(): void
    {
        Mail::fake();

        $this->postJson('/api/v1/consultations', $this->validPayload, $this->guestHeaders())
            ->assertStatus(201)
            ->assertJsonPath('data.status', 'open')
            ->assertJsonPath('data.practitioner_label', 'Dentist')
            // The opening message is in the thread as well as in the column, so
            // the conversation reads in order with no special first entry.
            ->assertJsonPath('data.messages.0.body', 'A sore molar for three days.');

        Mail::assertSent(ConsultationReceivedEmail::class);
    }

    public function test_the_reference_keeps_its_prefix(): void
    {
        // The ambiguous-character substitution used to run over the whole
        // string and turn CON- into C3N-.
        $this->assertStringStartsWith('CON-', $this->raiseAsGuest()->reference);
    }

    public function test_asking_to_be_phoned_without_a_number_is_refused(): void
    {
        $this->postJson(
            '/api/v1/consultations',
            array_merge($this->validPayload, ['phone' => '', 'preferred_contact' => 'phone']),
            $this->guestHeaders()
        )->assertStatus(422);
    }

    public function test_an_unknown_practitioner_type_is_refused(): void
    {
        $this->postJson(
            '/api/v1/consultations',
            array_merge($this->validPayload, ['practitioner_type' => 'astrologer']),
            $this->guestHeaders()
        )->assertStatus(422);
    }

    public function test_a_signed_out_caller_with_no_session_id_cannot_raise_one(): void
    {
        $this->postJson('/api/v1/consultations', $this->validPayload, ['Accept' => 'application/json'])
            ->assertStatus(422);
    }

    public function test_another_guest_cannot_read_the_request(): void
    {
        $consultation = $this->raiseAsGuest('owner-guest');

        // 404 rather than 403: a reference must not be probeable.
        $this->getJson("/api/v1/consultations/{$consultation->reference}", $this->guestHeaders('other-guest'))
            ->assertStatus(404);

        $this->getJson("/api/v1/consultations/{$consultation->reference}", $this->guestHeaders('owner-guest'))
            ->assertOk()
            ->assertJsonPath('data.reference', $consultation->reference);
    }

    public function test_a_missing_reference_returns_the_same_404(): void
    {
        $this->getJson('/api/v1/consultations/CON-NOSUCH', $this->guestHeaders())
            ->assertStatus(404);
    }

    public function test_the_list_only_returns_the_callers_own_requests(): void
    {
        $this->raiseAsGuest('owner-guest');

        $this->getJson('/api/v1/consultations', $this->guestHeaders('owner-guest'))
            ->assertOk()
            ->assertJsonPath('data.total', 1);

        $this->getJson('/api/v1/consultations', $this->guestHeaders('other-guest'))
            ->assertOk()
            ->assertJsonPath('data.total', 0);
    }

    public function test_signing_in_claims_the_requests_raised_as_a_guest(): void
    {
        $consultation = $this->raiseAsGuest('owner-guest');
        $user = $this->makeUser();

        $this->getJson(
            '/api/v1/consultations',
            array_merge($this->tokenFor($user), ['X-Guest-ID' => 'owner-guest'])
        )->assertOk()->assertJsonPath('data.total', 1);

        $this->assertSame($user->id, $consultation->fresh()->user_id);
        $this->assertNull($consultation->fresh()->session_id);
    }

    public function test_an_internal_note_never_reaches_the_requester(): void
    {
        $consultation = $this->raiseAsGuest('owner-guest');

        Mail::fake();

        $this->postJson(
            "/api/v1/admin/consultations/{$consultation->id}/reply",
            ['body' => 'Check the repeat before the call.', 'is_internal' => true],
            $this->adminHeaders()
        )->assertOk();

        // A note is not a reply: it must not email, must not move the ticket on,
        // and must not appear on any customer-facing response.
        Mail::assertNothingSent();
        $this->assertSame('open', $consultation->fresh()->status);

        $customerView = $this->getJson(
            "/api/v1/consultations/{$consultation->reference}",
            $this->guestHeaders('owner-guest')
        )->assertOk();

        $this->assertCount(1, $customerView->json('data.messages'));
        $this->assertStringNotContainsString('Check the repeat', json_encode($customerView->json()));
    }

    public function test_replying_emails_the_requester_and_moves_the_ticket_on(): void
    {
        $consultation = $this->raiseAsGuest('owner-guest');

        Mail::fake();

        $this->postJson(
            "/api/v1/admin/consultations/{$consultation->id}/reply",
            ['body' => 'Our dentist can call you tomorrow at 10am.'],
            $this->adminHeaders()
        )->assertOk()->assertJsonPath('data.status', 'in_progress');

        Mail::assertSent(ConsultationReplyEmail::class);

        $fresh = $consultation->fresh();
        $this->assertSame('admin', $fresh->last_reply_by);
        // Whoever answers owns it, without having to remember to say so.
        $this->assertNotNull($fresh->assigned_to);
    }

    public function test_scheduling_without_a_time_is_refused(): void
    {
        $consultation = $this->raiseAsGuest();

        $this->putJson(
            "/api/v1/admin/consultations/{$consultation->id}",
            ['status' => 'scheduled'],
            $this->adminHeaders()
        )->assertStatus(422);

        $this->putJson(
            "/api/v1/admin/consultations/{$consultation->id}",
            ['status' => 'scheduled', 'scheduled_at' => now()->addDay()->toDateTimeString()],
            $this->adminHeaders()
        )->assertOk()->assertJsonPath('data.status', 'scheduled');
    }

    public function test_reopening_clears_the_resolution_stamp(): void
    {
        $consultation = $this->raiseAsGuest();
        $headers = $this->adminHeaders();

        $this->putJson("/api/v1/admin/consultations/{$consultation->id}", ['status' => 'resolved'], $headers)
            ->assertOk();
        $this->assertNotNull($consultation->fresh()->resolved_at);

        $this->putJson("/api/v1/admin/consultations/{$consultation->id}", ['status' => 'in_progress'], $headers)
            ->assertOk();
        $this->assertNull($consultation->fresh()->resolved_at);
    }

    public function test_a_partial_update_leaves_the_other_fields_alone(): void
    {
        $consultation = $this->raiseAsGuest();
        $headers = $this->adminHeaders();

        $this->putJson(
            "/api/v1/admin/consultations/{$consultation->id}",
            ['status' => 'scheduled', 'scheduled_at' => now()->addDay()->toDateTimeString(), 'priority' => 'high'],
            $headers
        )->assertOk();

        // Sending only a priority must not blank the appointment.
        $this->putJson("/api/v1/admin/consultations/{$consultation->id}", ['priority' => 'low'], $headers)
            ->assertOk()
            ->assertJsonPath('data.priority', 'low');

        $this->assertNotNull($consultation->fresh()->scheduled_at);
    }

    public function test_a_settled_request_takes_no_further_replies(): void
    {
        $consultation = $this->raiseAsGuest('owner-guest');

        $this->putJson(
            "/api/v1/admin/consultations/{$consultation->id}",
            ['status' => 'closed'],
            $this->adminHeaders()
        )->assertOk();

        $this->postJson(
            "/api/v1/consultations/{$consultation->reference}/reply",
            ['body' => 'One more thing'],
            $this->guestHeaders('owner-guest')
        )->assertStatus(422);
    }

    public function test_the_admin_queue_is_closed_to_customers(): void
    {
        $consultation = $this->raiseAsGuest();
        $customer = $this->makeUser();

        $this->getJson('/api/v1/admin/consultations', $this->tokenFor($customer))
            ->assertStatus(403);

        $this->postJson(
            "/api/v1/admin/consultations/{$consultation->id}/reply",
            ['body' => 'Pretending to be support'],
            $this->tokenFor($customer)
        )->assertStatus(403);
    }
}
