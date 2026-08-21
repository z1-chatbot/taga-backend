<?php

namespace Tests\Feature;

use App\Mail\StoreVerificationDecisionEmail;
use App\Models\Role;
use App\Models\Store;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * A pharmacy applying to sell, and waiting.
 *
 * Until now there was no way in at all: the endpoint that created a store was
 * reachable only from inside the dashboard, and the dashboard was closed to
 * anyone who did not already own a store. These tests describe the door that
 * replaces it — apply from the storefront with a licence attached, wait, and be
 * let in by an admin's approval and nothing else.
 */
class StoreApplicationTest extends TestCase
{
    private User $applicant;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('local');
        Mail::fake();

        // The dashboard login gate keys on this role, so an approval that
        // cannot find it locks the pharmacy out of the shop it just opened.
        Role::firstOrCreate(['name' => 'store_owner'], ['display_name' => 'Store Owner']);

        $this->applicant = $this->makeUser(['role' => 'customer', 'role_id' => null]);
    }

    private function application(array $overrides = []): array
    {
        return array_merge([
            'name' => 'Ada Pharmacy',
            'phone' => '08012345678',
            'email' => 'ada@pharmacy.test',
            'state' => 'Lagos',
            'city' => 'Ikeja',
            'address' => '1 Test Road',
            // The licence is the document. Nothing off it is retyped.
            'license_document' => UploadedFile::fake()->create('licence.pdf', 200, 'application/pdf'),
        ], $overrides);
    }

    private function apply(?User $user = null, array $overrides = [])
    {
        return $this->post('/api/v1/sell/apply', $this->application($overrides),
            $this->tokenFor($user ?? $this->applicant));
    }

    /** Approval is where the licence details are recorded, off the document. */
    private function approve(Store $store, array $payload = [])
    {
        return $this->postJson("/api/v1/admin/stores/{$store->id}/verification/review",
            array_merge([
                'action' => 'approve',
                'pharmacy_license_number' => 'PCN-12345',
                'pharmacy_license_expiry' => now()->addYear()->toDateString(),
            ], $payload),
            $this->tokenFor($this->makeUser(['role' => 'admin'])));
    }

    /**
     * Without the Accept header Laravel answers a failed validation with a 302
     * back to a form that does not exist on an API, so every negative case
     * would pass for the wrong reason.
     */
    private function register(array $overrides = [])
    {
        return $this->post('/api/v1/sell/register', $this->registration($overrides),
            ['Accept' => 'application/json']);
    }

    private function registration(array $overrides = []): array
    {
        return array_merge($this->application(), [
            'owner_name' => 'Ada Obi',
            'owner_email' => 'ada.owner'.uniqid().'@example.test',
            'owner_password' => 'Passw0rd!23',
            'owner_password_confirmation' => 'Passw0rd!23',
            'owner_phone' => '08033333333',
        ], $overrides);
    }

    // ------------------------------------------------ applying with no account

    public function test_a_pharmacy_with_no_account_applies_in_one_submission(): void
    {
        $payload = $this->registration();

        $this->post('/api/v1/sell/register', $payload, ['Accept' => 'application/json'])
            ->assertStatus(201)
            ->assertJsonPath('data.status', Store::VERIFICATION_PENDING)
            ->assertJsonPath('data.account_email', $payload['owner_email']);

        $owner = User::where('email', $payload['owner_email'])->first();

        $this->assertNotNull($owner, 'the account is created in the same submission');
        $this->assertNotNull(Store::where('owner_id', $owner->id)->first());
    }

    public function test_registering_this_way_grants_nothing_either(): void
    {
        $payload = $this->registration();
        $this->post('/api/v1/sell/register', $payload, ['Accept' => 'application/json']);

        $owner = User::where('email', $payload['owner_email'])->first();
        $store = Store::where('owner_id', $owner->id)->first();

        // Identical to applying from a signed-in account: an ordinary customer
        // with a shut shop. Approval is still the only promotion.
        $this->assertSame('customer', $owner->role);
        $this->assertNull($owner->role_id);
        $this->assertSame('inactive', $store->status);
        $this->assertFalse($store->canSell());
        $this->assertSame(0, Store::sellable()->count());
    }

    public function test_the_person_and_the_pharmacy_keep_their_own_details(): void
    {
        $payload = $this->registration([
            'owner_name' => 'Ada Obi',
            'owner_phone' => '08033333333',
            'name' => 'Ada Pharmacy',
            'phone' => '08012345678',
            'email' => 'shop@pharmacy.test',
        ]);

        $this->post('/api/v1/sell/register', $payload);

        $owner = User::where('email', $payload['owner_email'])->first();
        $store = Store::where('owner_id', $owner->id)->first();

        // Mixing these is how a pharmacist's mobile ends up published as a
        // shop's public number.
        $this->assertSame('Ada Obi', $owner->name);
        $this->assertSame('08033333333', $owner->phone);
        $this->assertSame('Ada Pharmacy', $store->name);
        $this->assertSame('08012345678', $store->phone);
        $this->assertSame('shop@pharmacy.test', $store->email);
    }

    public function test_an_email_that_already_has_an_account_is_sent_to_sign_in(): void
    {
        $existing = $this->makeUser(['email' => 'taken'.uniqid().'@example.test']);

        $this->register(['owner_email' => $existing->email])
            ->assertStatus(422)
            ->assertJsonValidationErrors('owner_email');

        $this->assertSame(0, Store::count(), 'no half-made pharmacy is left behind');
    }

    public function test_a_mismatched_password_is_refused(): void
    {
        $this->register(['owner_password_confirmation' => 'something-else'])
            ->assertStatus(422)
            ->assertJsonValidationErrors('owner_password');

        $this->assertSame(0, Store::count());
    }

    public function test_the_licence_is_required_here_too(): void
    {
        $this->post('/api/v1/sell/register',
            collect($this->registration())->except('license_document')->toArray(),
            ['Accept' => 'application/json'])
            ->assertStatus(422)
            ->assertJsonValidationErrors('license_document');

        $this->assertSame(0, User::where('role', 'customer')->where('is_active', false)->count());
        $this->assertSame(0, Store::count());
    }

    public function test_a_failed_validation_creates_neither_account_nor_pharmacy(): void
    {
        $this->register(['state' => ''])->assertStatus(422);

        $this->assertSame(0, Store::count());
    }

    public function test_the_new_account_still_has_to_verify_its_email(): void
    {
        $payload = $this->registration();

        $this->post('/api/v1/sell/register', $payload, ['Accept' => 'application/json'])
            ->assertStatus(201)
            ->assertJsonPath('data.requires_verification', true);

        $owner = User::where('email', $payload['owner_email'])->first();

        $this->assertFalse((bool) $owner->is_active);
        $this->assertNull($owner->email_verified_at);
    }

    public function test_approving_a_one_form_application_opens_the_dashboard(): void
    {
        $payload = $this->registration();
        $this->post('/api/v1/sell/register', $payload, ['Accept' => 'application/json']);

        $owner = User::where('email', $payload['owner_email'])->first();
        $store = Store::where('owner_id', $owner->id)->first();

        $this->approve($store)->assertOk();

        $this->assertSame('store_owner', $owner->fresh()->role);
        $this->assertNotNull($owner->fresh()->role_id);
        $this->assertTrue($store->fresh()->canSell());
    }

    // ------------------------------------------------------------- applying

    public function test_a_customer_can_apply_to_sell(): void
    {
        $this->apply()->assertStatus(201)->assertJsonPath('data.status', Store::VERIFICATION_PENDING);

        $store = Store::where('owner_id', $this->applicant->id)->first();

        $this->assertNotNull($store);
        $this->assertSame('Ada Pharmacy', $store->name);
    }

    public function test_applying_grants_nothing(): void
    {
        $this->apply();

        $store = Store::where('owner_id', $this->applicant->id)->first();
        $applicant = $this->applicant->fresh();

        // Two independent locks: the shop is shut and the licence is unapproved.
        $this->assertSame('inactive', $store->status);
        $this->assertSame(Store::VERIFICATION_PENDING, $store->verification_status);
        $this->assertFalse($store->canSell());
        $this->assertFalse((bool) $store->can_sell_prescription);
        $this->assertFalse((bool) $store->can_sell_controlled);

        // And they are still an ordinary customer, so the dashboard stays shut.
        $this->assertSame('customer', $applicant->role);
        $this->assertNull($applicant->role_id);
    }

    public function test_an_applied_pharmacy_is_not_in_the_public_catalogue(): void
    {
        $this->apply();

        $this->assertSame(0, Store::sellable()->count());
    }

    public function test_the_licence_is_required_to_apply(): void
    {
        $this->post('/api/v1/sell/apply',
            collect($this->application())->except('license_document')->toArray(),
            $this->tokenFor($this->applicant))
            ->assertStatus(422)
            ->assertJsonValidationErrors('license_document');

        $this->assertSame(0, Store::where('owner_id', $this->applicant->id)->count());
    }

    public function test_the_applicant_types_nothing_off_the_licence(): void
    {
        // Whatever they send for these is ignored: the reviewer's reading of
        // the document is the only version, so there is no second one for it
        // to disagree with.
        $this->apply(null, [
            'pharmacy_license_number' => 'MADE-UP-BY-APPLICANT',
            'pharmacy_license_expiry' => now()->addYears(9)->toDateString(),
        ])->assertStatus(201);

        $store = Store::where('owner_id', $this->applicant->id)->first();

        $this->assertNull($store->pharmacy_license_number);
        $this->assertNull($store->pharmacy_license_expiry);
    }

    public function test_approval_records_the_licence_off_the_document(): void
    {
        $this->apply();
        $store = Store::where('owner_id', $this->applicant->id)->first();

        $expiry = now()->addMonths(8)->toDateString();

        $this->approve($store, [
            'pharmacy_license_number' => 'PCN-55555',
            'pharmacy_license_expiry' => $expiry,
            'superintendent_pharmacist_name' => 'Dr Ada Obi',
        ])->assertOk();

        $store = $store->fresh();

        $this->assertSame('PCN-55555', $store->pharmacy_license_number);
        $this->assertSame($expiry, $store->pharmacy_license_expiry->toDateString());
        $this->assertSame('Dr Ada Obi', $store->superintendent_pharmacist_name);
    }

    public function test_a_licence_cannot_be_approved_without_its_expiry(): void
    {
        $this->apply();
        $store = Store::where('owner_id', $this->applicant->id)->first();

        // The renewal reminders and isLicenceValid() both run on this date. An
        // approval that skipped it would quietly opt the pharmacy out of both.
        $this->postJson("/api/v1/admin/stores/{$store->id}/verification/review", [
            'action' => 'approve',
            'pharmacy_license_number' => 'PCN-12345',
        ], $this->tokenFor($this->makeUser(['role' => 'admin'])))
            ->assertStatus(422)
            ->assertJsonValidationErrors('pharmacy_license_expiry');

        $this->assertSame(Store::VERIFICATION_PENDING, $store->fresh()->verification_status);
    }

    public function test_a_rejection_needs_no_licence_details(): void
    {
        $this->apply();
        $store = Store::where('owner_id', $this->applicant->id)->first();

        $this->postJson("/api/v1/admin/stores/{$store->id}/verification/review", [
            'action' => 'reject',
            'notes' => 'The scan is unreadable.',
        ], $this->tokenFor($this->makeUser(['role' => 'admin'])))->assertOk();

        $this->assertSame(Store::VERIFICATION_REJECTED, $store->fresh()->verification_status);
    }

    public function test_the_licence_document_is_kept_off_the_public_disk(): void
    {
        $this->apply();

        $store = Store::where('owner_id', $this->applicant->id)->first();

        $this->assertNotNull($store->pharmacy_license_document);
        Storage::disk('local')->assertExists($store->pharmacy_license_document);
        $this->assertStringStartsWith('licenses/', $store->pharmacy_license_document);
    }

    public function test_you_must_be_signed_in_to_apply(): void
    {
        $this->postJson('/api/v1/sell/apply', [])->assertStatus(401);
    }

    public function test_applying_twice_while_under_review_is_refused(): void
    {
        $this->apply()->assertStatus(201);

        $this->apply()->assertStatus(422)->assertJsonPath('code', 'application_under_review');

        $this->assertSame(1, Store::where('owner_id', $this->applicant->id)->count());
    }

    // --------------------------------------------------------------- status

    public function test_someone_who_has_not_applied_is_told_so(): void
    {
        $this->getJson('/api/v1/sell/application', $this->tokenFor($this->applicant))
            ->assertOk()->assertJsonPath('data.has_applied', false);
    }

    public function test_an_applicant_can_see_that_the_review_is_under_way(): void
    {
        $this->apply();

        $this->getJson('/api/v1/sell/application', $this->tokenFor($this->applicant))
            ->assertOk()
            ->assertJsonPath('data.has_applied', true)
            ->assertJsonPath('data.status', Store::VERIFICATION_PENDING)
            ->assertJsonPath('data.approved', false)
            ->assertJsonPath('data.can_resubmit', false)
            ->assertJsonPath('data.dashboard_url', null);
    }

    public function test_the_status_never_exposes_the_licence_document(): void
    {
        $this->apply();

        $body = $this->getJson('/api/v1/sell/application', $this->tokenFor($this->applicant))
            ->assertOk()->json('data');

        $this->assertArrayNotHasKey('pharmacy_license_document', $body);
        $this->assertStringNotContainsString('licenses/', json_encode($body));
    }

    public function test_one_applicant_cannot_see_anothers_application(): void
    {
        $this->apply();

        $stranger = $this->makeUser(['role' => 'customer', 'role_id' => null]);

        $this->getJson('/api/v1/sell/application', $this->tokenFor($stranger))
            ->assertOk()->assertJsonPath('data.has_applied', false);
    }

    // ------------------------------------------------------------- approval

    public function test_approval_opens_the_shop_and_lets_the_owner_in(): void
    {
        $this->apply();
        $store = Store::where('owner_id', $this->applicant->id)->first();

        $this->approve($store)->assertOk();

        $store = $store->fresh();
        $owner = $this->applicant->fresh();

        $this->assertSame('active', $store->status);
        $this->assertSame(Store::VERIFICATION_APPROVED, $store->verification_status);
        $this->assertTrue($store->canSell());

        // The dashboard login gate reads both of these.
        $this->assertSame('store_owner', $owner->role);
        $this->assertNotNull($owner->role_id);
    }

    public function test_an_approved_pharmacy_reaches_the_public_catalogue(): void
    {
        $this->apply();
        $store = Store::where('owner_id', $this->applicant->id)->first();

        $this->assertSame(0, Store::sellable()->count());

        $this->approve($store);

        $this->assertSame(1, Store::sellable()->where('id', $store->id)->count());
    }

    public function test_approval_emails_the_pharmacy(): void
    {
        $this->apply();
        $store = Store::where('owner_id', $this->applicant->id)->first();

        $this->approve($store);

        Mail::assertSent(StoreVerificationDecisionEmail::class, function ($mail) {
            // An applicant has never been inside, so this mail is their way in.
            return $mail->approved
                && $mail->isApplicant
                && $mail->dashboardUrl !== '';
        });
    }

    public function test_the_status_page_hands_an_approved_applicant_the_dashboard_link(): void
    {
        $this->apply();
        $store = Store::where('owner_id', $this->applicant->id)->first();
        $this->approve($store);

        $this->getJson('/api/v1/sell/application', $this->tokenFor($this->applicant))
            ->assertOk()
            ->assertJsonPath('data.approved', true)
            ->assertJsonPath('data.dashboard_url', fn ($url) => is_string($url) && $url !== '');
    }

    public function test_an_approved_owner_cannot_apply_again(): void
    {
        $this->apply();
        $store = Store::where('owner_id', $this->applicant->id)->first();
        $this->approve($store);

        $this->apply()->assertStatus(422)->assertJsonPath('code', 'already_approved');
    }

    // ------------------------------------------------------------ rejection

    public function test_rejection_leaves_them_outside_with_a_reason(): void
    {
        $this->apply();
        $store = Store::where('owner_id', $this->applicant->id)->first();

        $this->postJson("/api/v1/admin/stores/{$store->id}/verification/review", [
            'action' => 'reject',
            'notes' => 'The licence image is unreadable.',
        ], $this->tokenFor($this->makeUser(['role' => 'admin'])))->assertOk();

        $owner = $this->applicant->fresh();

        $this->assertSame('customer', $owner->role, 'a rejected applicant is not promoted');
        $this->assertSame('inactive', $store->fresh()->status);

        $this->getJson('/api/v1/sell/application', $this->tokenFor($this->applicant))
            ->assertOk()
            ->assertJsonPath('data.status', Store::VERIFICATION_REJECTED)
            ->assertJsonPath('data.can_resubmit', true)
            ->assertJsonPath('data.rejection_reason', 'The licence image is unreadable.');
    }

    public function test_rejection_points_an_applicant_back_at_the_public_form(): void
    {
        $this->apply();
        $store = Store::where('owner_id', $this->applicant->id)->first();

        $this->postJson("/api/v1/admin/stores/{$store->id}/verification/review", [
            'action' => 'reject',
            'notes' => 'Superintendent pharmacist licence has lapsed.',
        ], $this->tokenFor($this->makeUser(['role' => 'admin'])));

        Mail::assertSent(StoreVerificationDecisionEmail::class, function ($mail) {
            // A dashboard link would be useless: they cannot open one.
            return ! $mail->approved
                && $mail->isApplicant
                && str_ends_with($mail->applyUrl, '/sell');
        });
    }

    public function test_a_rejected_applicant_can_correct_and_resend(): void
    {
        $this->apply();
        $store = Store::where('owner_id', $this->applicant->id)->first();

        $this->postJson("/api/v1/admin/stores/{$store->id}/verification/review", [
            'action' => 'reject',
            'notes' => 'Wrong document.',
        ], $this->tokenFor($this->makeUser(['role' => 'admin'])));

        $this->apply()->assertStatus(201);

        $store = $store->fresh();

        $this->assertSame(Store::VERIFICATION_PENDING, $store->verification_status);
        $this->assertNull($store->verification_notes, 'the old reason must not linger');
        $this->assertSame(1, Store::where('owner_id', $this->applicant->id)->count(),
            'correcting an application must not create a second pharmacy');
    }

    // ----------------------------------------------------- the closed doors

    public function test_the_old_self_serve_creation_endpoint_is_gone(): void
    {
        // It made an ACTIVE store and promoted the caller on the spot, which is
        // the exact gate this whole flow exists to enforce.
        $response = $this->postJson('/api/v1/store', [
            'name' => 'Backdoor Pharmacy',
            'phone' => '08012345678',
            'state' => 'Lagos',
            'city' => 'Ikeja',
        ], $this->tokenFor($this->applicant));

        $this->assertContains($response->status(), [404, 405]);
        $this->assertSame('customer', $this->applicant->fresh()->role);
        $this->assertSame(0, Store::where('name', 'Backdoor Pharmacy')->count());
    }

    public function test_an_applicant_cannot_reach_the_store_dashboard_endpoints(): void
    {
        $this->apply();

        // getMyStore resolves by role and store_id, neither of which an
        // applicant has. Being able to read it would mean being half inside.
        $this->getJson('/api/v1/stores/my-store', $this->tokenFor($this->applicant))
            ->assertStatus(403);
    }

    public function test_an_applicant_cannot_approve_themselves(): void
    {
        $this->apply();
        $store = Store::where('owner_id', $this->applicant->id)->first();

        $this->postJson("/api/v1/admin/stores/{$store->id}/verification/review", [
            'action' => 'approve',
        ], $this->tokenFor($this->applicant))->assertStatus(403);

        $this->assertSame(Store::VERIFICATION_PENDING, $store->fresh()->verification_status);
        $this->assertSame('customer', $this->applicant->fresh()->role);
    }
}
