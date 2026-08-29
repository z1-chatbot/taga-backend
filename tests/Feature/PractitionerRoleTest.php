<?php

namespace Tests\Feature;

use App\Models\ConsultationRequest;
use App\Models\PractitionerType;
use App\Models\Role;
use App\Models\User;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

/**
 * Practitioners as accounts of their own.
 *
 * The consultation queue used to sit behind the blanket `admin` gate, so the
 * only people who could answer a shopper's health question were the same people
 * who could refund an order and delete a pharmacy. A dentist now signs in as
 * themselves and sees the dentistry queue.
 *
 * Which specialties someone covers is per-person, not per-role — a role per
 * specialty would mean a new role every time an administrator adds one.
 */
class PractitionerRoleTest extends TestCase
{
    private function seedRoles(): void
    {
        $this->seed(\Database\Seeders\RolesAndPermissionsSeeder::class);
    }

    private function admin(): array
    {
        $this->seedRoles();

        return $this->tokenFor($this->makeUser([
            'role' => 'admin',
            'role_id' => Role::where('name', 'admin')->value('id'),
        ]));
    }

    /** A practitioner account covering the given specialty slugs. */
    private function practitioner(array $slugs): User
    {
        $this->seedRoles();

        $user = $this->makeUser([
            'role' => Role::PRACTITIONER,
            'role_id' => Role::where('name', Role::PRACTITIONER)->value('id'),
        ]);

        $user->practitionerTypes()->sync(
            PractitionerType::whereIn('slug', $slugs)->pluck('id')->all()
        );

        return $user;
    }

    private function consultation(string $slug, array $attributes = []): ConsultationRequest
    {
        return ConsultationRequest::create(array_merge([
            'reference' => ConsultationRequest::generateReference(),
            'practitioner_type' => $slug,
            'subject' => 'A question',
            'message' => 'Something that has been going on for a week.',
            'name' => 'Ada Obi',
            'email' => 'ada@example.test',
            'status' => ConsultationRequest::STATUS_OPEN,
            'priority' => 'normal',
        ], $attributes));
    }

    public function test_the_role_exists_and_carries_only_the_queue(): void
    {
        $this->seedRoles();

        $role = Role::where('name', Role::PRACTITIONER)->first();

        $this->assertNotNull($role);

        $held = $role->permissions()->pluck('name');

        $this->assertContains('consultations.view', $held);
        $this->assertContains('consultations.reply', $held);

        // A counsellor answering a question has no business in the refunds
        // screen, and this is the assertion that keeps it that way.
        $this->assertNotContains('orders.refund', $held);
        $this->assertNotContains('users.edit', $held);
        $this->assertNotContains('products.edit', $held);
    }

    public function test_a_practitioner_sees_their_own_specialties_and_nothing_else(): void
    {
        $dentist = $this->practitioner(['dentist']);

        $mine = $this->consultation('dentist');
        $this->consultation('nutritionist');

        $references = collect(
            $this->getJson('/api/v1/admin/consultations', $this->tokenFor($dentist))
                ->assertOk()
                ->json('data.data')
        )->pluck('reference');

        $this->assertSame([$mine->reference], $references->all());
    }

    public function test_a_ticket_handed_to_them_by_name_stays_in_their_inbox(): void
    {
        $dentist = $this->practitioner(['dentist']);

        // An administrator who assigns a ticket to a specific person has
        // overridden the specialty on purpose. It must not then vanish from the
        // very inbox it was pushed into.
        $handed = $this->consultation('nutritionist', ['assigned_to' => $dentist->id]);

        $references = collect(
            $this->getJson('/api/v1/admin/consultations', $this->tokenFor($dentist))
                ->assertOk()
                ->json('data.data')
        )->pluck('reference');

        $this->assertContains($handed->reference, $references);
    }

    public function test_a_practitioner_cannot_open_a_ticket_outside_their_scope(): void
    {
        $dentist = $this->practitioner(['dentist']);
        $other = $this->consultation('nutritionist');

        // 404 rather than 403: they have no business learning it exists.
        $this->getJson("/api/v1/admin/consultations/{$other->id}", $this->tokenFor($dentist))
            ->assertStatus(404);

        $this->postJson(
            "/api/v1/admin/consultations/{$other->id}/reply",
            ['body' => 'Replying to something that is not mine.'],
            $this->tokenFor($dentist)
        )->assertStatus(404);
    }

    public function test_a_practitioner_answers_their_own_ticket(): void
    {
        $dentist = $this->practitioner(['dentist']);
        $mine = $this->consultation('dentist');

        $this->postJson(
            "/api/v1/admin/consultations/{$mine->id}/reply",
            ['body' => 'Come in on Thursday and we will take a look at it.'],
            $this->tokenFor($dentist)
        )->assertOk();

        $this->assertDatabaseHas('consultation_messages', [
            'consultation_request_id' => $mine->id,
            'user_id' => $dentist->id,
        ]);
    }

    public function test_the_counts_describe_the_queue_the_reader_can_see(): void
    {
        $dentist = $this->practitioner(['dentist']);

        $this->consultation('dentist');
        $this->consultation('nutritionist');
        $this->consultation('nutritionist');

        // Reading "3 waiting" and finding one in the list is worse than no
        // badge at all.
        $stats = $this->getJson('/api/v1/admin/consultations/stats', $this->tokenFor($dentist))
            ->assertOk()
            ->json('data');

        $this->assertSame(1, $stats['active']);
    }

    public function test_a_practitioner_with_no_specialty_sees_nothing_rather_than_everything(): void
    {
        $unassigned = $this->practitioner([]);

        $this->consultation('dentist');

        $rows = $this->getJson('/api/v1/admin/consultations', $this->tokenFor($unassigned))
            ->assertOk()
            ->json('data.data');

        $this->assertSame([], $rows);
    }

    public function test_an_administrator_still_sees_the_whole_queue(): void
    {
        $admin = $this->admin();

        $this->consultation('dentist');
        $this->consultation('nutritionist');

        $rows = $this->getJson('/api/v1/admin/consultations', $admin)->assertOk()->json('data.data');

        $this->assertCount(2, $rows);
    }

    public function test_a_practitioner_cannot_reach_the_rest_of_the_admin_portal(): void
    {
        $dentist = $this->practitioner(['dentist']);

        // The whole point of the role: a login that opens the queue and nothing
        // else. This used to require an administrator account.
        $this->getJson('/api/v1/admin/users', $this->tokenFor($dentist))->assertStatus(403);
        $this->getJson('/api/v1/admin/practitioner-types', $this->tokenFor($dentist))->assertStatus(403);
    }

    public function test_a_request_reaches_every_practitioner_in_that_specialty(): void
    {
        // A pool, not a named assignment. Nobody routes a nurse request to a
        // particular nurse — it lands in front of all of them.
        $first = $this->practitioner(['nurse']);
        $second = $this->practitioner(['nurse']);

        $request = $this->consultation('nurse');

        foreach ([$first, $second] as $nurse) {
            $references = collect(
                $this->getJson('/api/v1/admin/consultations', $this->tokenFor($nurse))
                    ->assertOk()
                    ->json('data.data')
            )->pluck('reference');

            $this->assertContains($request->reference, $references);
        }
    }

    public function test_the_first_to_answer_takes_it(): void
    {
        $first = $this->practitioner(['nurse']);
        $request = $this->consultation('nurse');

        $this->postJson(
            "/api/v1/admin/consultations/{$request->id}/reply",
            ['body' => 'I can help with that — how long has it been going on?'],
            $this->tokenFor($first)
        )->assertOk();

        // Answering claims it, without anyone having to remember to press a
        // button. Relying on that memory is how two people reply to the same
        // person with two different answers.
        $this->assertSame($first->id, $request->fresh()->assigned_to);
    }

    public function test_a_colleague_can_still_read_a_claimed_request(): void
    {
        $holder = $this->practitioner(['nurse']);
        $other = $this->practitioner(['nurse']);

        $request = $this->consultation('nurse', ['assigned_to' => $holder->id]);

        // Visible, so the rest of the specialty can see it is covered rather
        // than watching it sit there looking unanswered.
        $this->getJson("/api/v1/admin/consultations/{$request->id}", $this->tokenFor($other))
            ->assertOk()
            ->assertJsonPath('data.assignee.id', $holder->id);
    }

    public function test_a_colleague_cannot_answer_a_claimed_request(): void
    {
        $holder = $this->practitioner(['nurse']);
        $other = $this->practitioner(['nurse']);

        $request = $this->consultation('nurse', ['assigned_to' => $holder->id]);

        $this->postJson(
            "/api/v1/admin/consultations/{$request->id}/reply",
            ['body' => 'Answering something a colleague is already on.'],
            $this->tokenFor($other)
        )->assertStatus(409)->assertJsonPath('code', 'claimed_by_another');
    }

    public function test_a_colleague_cannot_take_a_claimed_request_off_its_owner(): void
    {
        $holder = $this->practitioner(['nurse']);
        $other = $this->practitioner(['nurse']);

        $request = $this->consultation('nurse', ['assigned_to' => $holder->id]);

        $this->putJson(
            "/api/v1/admin/consultations/{$request->id}",
            ['assigned_to' => $other->id],
            $this->tokenFor($other)
        )->assertStatus(409);

        $this->assertSame($holder->id, $request->fresh()->assigned_to);
    }

    public function test_a_practitioner_takes_a_request_on_themselves_only(): void
    {
        $nurse = $this->practitioner(['nurse']);
        $colleague = $this->practitioner(['nurse']);

        $request = $this->consultation('nurse');

        // Taking it on is theirs to do.
        $this->putJson(
            "/api/v1/admin/consultations/{$request->id}",
            ['assigned_to' => $nurse->id],
            $this->tokenFor($nurse)
        )->assertOk();

        $unclaimed = $this->consultation('nurse');

        // Pushing work onto a named colleague is a supervisor's job.
        $this->putJson(
            "/api/v1/admin/consultations/{$unclaimed->id}",
            ['assigned_to' => $colleague->id],
            $this->tokenFor($nurse)
        )->assertStatus(403)->assertJsonPath('code', 'cannot_assign_to_others');
    }

    public function test_an_administrator_steps_in_over_a_claimed_request(): void
    {
        $admin = $this->admin();
        $holder = $this->practitioner(['nurse']);

        $request = $this->consultation('nurse', ['assigned_to' => $holder->id]);

        // At any point, which is the whole reason the queue is visible to them.
        $this->postJson(
            "/api/v1/admin/consultations/{$request->id}/reply",
            ['body' => 'Stepping in on this one.'],
            $admin
        )->assertOk();

        $this->putJson(
            "/api/v1/admin/consultations/{$request->id}",
            ['status' => ConsultationRequest::STATUS_RESOLVED],
            $admin
        )->assertOk();
    }

    public function test_the_practitioner_role_grants_the_queue_and_nothing_else(): void
    {
        $this->seedRoles();

        $held = Role::where('name', Role::PRACTITIONER)->first()->permissions()->pluck('name');

        // Not even the dashboard: it is platform-wide trade figures, and
        // holding the permission only gets them to a page that refuses them.
        $this->assertSame(
            ['consultations.reply', 'consultations.view'],
            $held->sort()->values()->all()
        );
    }

    public function test_a_practitioner_can_hand_a_request_back(): void
    {
        $nurse = $this->practitioner(['nurse']);

        $request = $this->consultation('nurse', [
            'assigned_to' => $nurse->id,
            'status' => ConsultationRequest::STATUS_IN_PROGRESS,
        ]);

        // Someone who takes a request on and gets stuck needs a way out that is
        // not "ask an administrator".
        $this->putJson(
            "/api/v1/admin/consultations/{$request->id}",
            ['assigned_to' => null],
            $this->tokenFor($nurse)
        )->assertOk();

        $this->assertNull($request->fresh()->assigned_to);

        // And back to looking new. Left in progress with nobody on it, it reads
        // as covered when it is not.
        $this->assertSame(ConsultationRequest::STATUS_OPEN, $request->fresh()->status);
    }

    public function test_a_handed_back_request_is_open_to_the_specialty_again(): void
    {
        $nurse = $this->practitioner(['nurse']);
        $colleague = $this->practitioner(['nurse']);

        $request = $this->consultation('nurse', ['assigned_to' => $nurse->id]);

        $this->putJson(
            "/api/v1/admin/consultations/{$request->id}",
            ['assigned_to' => null],
            $this->tokenFor($nurse)
        )->assertOk();

        // The colleague who was locked out a moment ago can now answer it.
        $this->postJson(
            "/api/v1/admin/consultations/{$request->id}/reply",
            ['body' => 'Picking this up.'],
            $this->tokenFor($colleague)
        )->assertOk();

        $this->assertSame($colleague->id, $request->fresh()->assigned_to);
    }

    public function test_handing_back_tells_the_specialty_it_is_free_again(): void
    {
        $nurse = $this->practitioner(['nurse']);
        $colleague = $this->practitioner(['nurse']);

        $request = $this->consultation('nurse', ['assigned_to' => $nurse->id]);

        Mail::fake();

        $this->putJson(
            "/api/v1/admin/consultations/{$request->id}",
            ['assigned_to' => null],
            $this->tokenFor($nurse)
        )->assertOk();

        // Otherwise a released request is invisible until somebody happens to
        // reopen the queue — the same silence the pool alert exists to prevent.
        Mail::assertSent(
            \App\Mail\ConsultationAwaitingEmail::class,
            fn ($mail) => $mail->hasTo($colleague->email)
        );
    }

    public function test_the_thread_survives_a_hand_back(): void
    {
        $nurse = $this->practitioner(['nurse']);
        $request = $this->consultation('nurse');

        $this->postJson(
            "/api/v1/admin/consultations/{$request->id}/reply",
            ['body' => 'Asking a first question before I hand this on.'],
            $this->tokenFor($nurse)
        )->assertOk();

        $this->putJson(
            "/api/v1/admin/consultations/{$request->id}",
            ['assigned_to' => null],
            $this->tokenFor($nurse)
        )->assertOk();

        // Whoever picks it up next needs to see what was already said, or the
        // requester gets asked the same question twice.
        $this->assertSame(1, $request->fresh()->messages()->count());
    }

    public function test_everyone_covering_the_specialty_is_told_someone_is_waiting(): void
    {
        Mail::fake();

        $this->seedRoles();

        $nurse = $this->practitioner(['nurse']);
        $secondNurse = $this->practitioner(['nurse']);
        $dentist = $this->practitioner(['dentist']);

        $this->postJson('/api/v1/consultations', [
            'practitioner_type' => 'nurse',
            'subject' => 'A question',
            'message' => 'Something that has been going on for a week.',
            'name' => 'Ada Obi',
            'email' => 'ada@example.test',
            'session_id' => 'guest-'.uniqid(),
        ])->assertCreated();

        // Nobody is personally on the hook for a pooled request, so without
        // this it sits unread until somebody happens to open the queue.
        Mail::assertSent(
            \App\Mail\ConsultationAwaitingEmail::class,
            fn ($mail) => $mail->hasTo($nurse->email)
        );
        Mail::assertSent(
            \App\Mail\ConsultationAwaitingEmail::class,
            fn ($mail) => $mail->hasTo($secondNurse->email)
        );

        // And only the specialty asked for.
        Mail::assertNotSent(
            \App\Mail\ConsultationAwaitingEmail::class,
            fn ($mail) => $mail->hasTo($dentist->email)
        );
    }

    public function test_the_alert_does_not_carry_the_message(): void
    {
        $nurse = $this->practitioner(['nurse']);

        $consultation = $this->consultation('nurse', [
            'message' => 'A private symptom nobody else needs to read.',
        ]);

        $rendered = (new \App\Mail\ConsultationAwaitingEmail($consultation, $nurse->name))
            ->render();

        // This lands in several inboxes at once and only one of those people
        // will handle it. The rest have no business reading someone's symptoms.
        $this->assertStringNotContainsString('private symptom', $rendered);
        $this->assertStringContainsString($consultation->reference, $rendered);
    }

    public function test_a_request_with_nobody_covering_it_still_goes_through(): void
    {
        Mail::fake();
        $this->seedRoles();

        // No practitioner covers dentistry. That is an administrator's problem
        // to fix, not a reason to fail the request the shopper just made.
        $this->postJson('/api/v1/consultations', [
            'practitioner_type' => 'dentist',
            'subject' => 'Toothache',
            'message' => 'A week of pain on the lower left.',
            'name' => 'Ada Obi',
            'email' => 'ada@example.test',
            'session_id' => 'guest-'.uniqid(),
        ])->assertCreated();

        $this->assertDatabaseHas('consultation_requests', ['practitioner_type' => 'dentist']);
    }

    public function test_creating_a_practitioner_without_a_specialty_is_refused(): void
    {
        $roleId = Role::where('name', Role::PRACTITIONER)->value('id');

        // An account with no specialty answers nobody and shows an empty queue,
        // which reads as broken rather than incomplete.
        $this->postJson('/api/v1/admin/users/staff', [
            'name' => 'Dr Nnamdi Eze',
            'email' => 'nnamdi@example.test',
            'role_id' => $roleId,
            'password' => 'a-long-enough-password',
            'password_confirmation' => 'a-long-enough-password',
        ], $this->admin())->assertStatus(422)->assertJsonPath('code', 'specialty_required_for_practitioner');
    }

    public function test_an_administrator_creates_a_practitioner_with_specialties(): void
    {
        $admin = $this->admin();
        $roleId = Role::where('name', Role::PRACTITIONER)->value('id');
        $dentistry = PractitionerType::firstWhere('slug', 'dentist');

        $this->postJson('/api/v1/admin/users/staff', [
            'name' => 'Dr Nnamdi Eze',
            'email' => 'nnamdi@example.test',
            'role_id' => $roleId,
            'practitioner_type_ids' => [$dentistry->id],
            'password' => 'a-long-enough-password',
            'password_confirmation' => 'a-long-enough-password',
        ], $admin)->assertCreated();

        $created = User::firstWhere('email', 'nnamdi@example.test');

        $this->assertTrue($created->isPractitioner());
        $this->assertSame(['dentist'], $created->practitionerTypes()->pluck('slug')->all());
    }

    public function test_withdrawing_a_specialty_takes_its_assignments_with_it(): void
    {
        $dentist = $this->practitioner(['dentist']);
        $type = PractitionerType::firstWhere('slug', 'dentist');

        // Deleted outright, not merely hidden — nothing has asked for it here.
        $type->delete();

        // The practitioner's account survives; only the link goes.
        $this->assertNotNull($dentist->fresh());
        $this->assertSame([], $dentist->practitionerTypes()->pluck('slug')->all());
    }
}
