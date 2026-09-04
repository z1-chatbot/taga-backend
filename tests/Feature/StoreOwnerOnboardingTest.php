<?php

namespace Tests\Feature;

use App\Http\Controllers\Api\StoreApplicationController;
use App\Mail\AdminAlertEmail;
use App\Models\Product;
use App\Models\Store;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * A pharmacy owner created by an admin, setting their own shop up.
 *
 * The dashboard was built storefront-first: a pharmacist applies at /sell, an
 * admin approves, and approval is what creates their dashboard access. That
 * left the other direction with nowhere to go. An admin can create the owner's
 * ACCOUNT — granting the store_owner role — before any pharmacy exists, and
 * that owner then signed in to a dashboard whose only advice was to leave and
 * apply on the public site.
 *
 * So the owner sets the shop up from inside now, and two gates govern what they
 * can reach, matching the two halves of Store::canSell():
 *
 *   1. Does a store exist? Until it does there is nothing to manage.
 *   2. Is its licence approved and current? A shop can exist, be theirs and be
 *      editable while it is not allowed to sell anything at all.
 *
 * The load-bearing property, and the reason the previous in-dashboard create
 * form was deleted: setting a shop up must NEVER be the thing that lets it
 * sell. That form posted to an endpoint which made an ACTIVE store and promoted
 * the caller on the spot, so anyone reaching the screen could open an
 * unverified pharmacy. Approval by an admin is the only route to selling, and
 * the tests below pin that from both ends.
 */
class StoreOwnerOnboardingTest extends TestCase
{
    /** An account an admin created: the role, and nothing else. */
    private function freshOwner()
    {
        return $this->makeUser(['role' => 'store_owner']);
    }

    private function state(array $headers)
    {
        return $this->getJson('/api/v1/stores/my-store/state', $headers);
    }

    /** The dashboard's create form posts here — the storefront application. */
    private function createPharmacy(array $headers, array $overrides = [])
    {
        return $this->postJson('/api/v1/sell/apply', array_merge([
            'name' => 'Melrose Pharmacy',
            'phone' => '+2348012345678',
            'email' => 'shop@melrose.test',
            'state' => 'Lagos',
            'city' => 'Ikeja',
            'address' => '14 Allen Avenue',
            'license_document' => UploadedFile::fake()->create('licence.pdf', 120, 'application/pdf'),
        ], $overrides), $headers);
    }

    private function approve(Store $store): void
    {
        Mail::fake();

        $this->postJson("/api/v1/admin/stores/{$store->id}/verification/review", [
            'action' => 'approve',
            'pharmacy_license_number' => 'PCN-12345',
            'pharmacy_license_expiry' => now()->addYear()->toDateString(),
        ], $this->tokenFor($this->makeUser(['role' => 'admin'])))->assertOk();
    }

    // ---- gate 1: no store --------------------------------------------------

    public function test_an_owner_with_no_pharmacy_is_told_so_rather_than_erroring(): void
    {
        $this->state($this->tokenFor($this->freshOwner()))
            ->assertOk()
            ->assertJsonPath('data.has_store', false)
            ->assertJsonPath('data.stage', 'no_store')
            ->assertJsonPath('data.can_sell', false)
            ->assertJsonPath('data.store', null);
    }

    public function test_the_gate_is_for_pharmacy_accounts_only(): void
    {
        $this->state($this->tokenFor($this->makeUser(['role' => 'customer'])))->assertStatus(403);
        $this->state($this->tokenFor($this->makeUser(['role' => 'admin'])))->assertStatus(403);
    }

    // ---- creating it -------------------------------------------------------

    public function test_an_owner_can_set_their_pharmacy_up_from_the_dashboard(): void
    {
        Storage::fake('local');

        $owner = $this->freshOwner();
        $headers = $this->tokenFor($owner);

        $this->createPharmacy($headers)->assertCreated();

        $store = Store::where('owner_id', $owner->id)->first();

        $this->assertNotNull($store, 'the owner should now have a pharmacy');
        $this->assertSame('Melrose Pharmacy', $store->name);
    }

    /**
     * The whole point, in one assertion.
     *
     * Setting a shop up is not permission to trade from it. A new pharmacy is
     * inactive AND unapproved — two independent locks, because
     * `Store::canSell()` needs both and a single flag is one accident away from
     * an unverified pharmacy dispensing medicine.
     */
    public function test_setting_a_pharmacy_up_never_makes_it_able_to_sell(): void
    {
        Storage::fake('local');

        $owner = $this->freshOwner();
        $this->createPharmacy($this->tokenFor($owner))->assertCreated();

        $store = Store::where('owner_id', $owner->id)->first();

        $this->assertSame('inactive', $store->status);
        $this->assertSame(Store::VERIFICATION_PENDING, $store->verification_status);
        $this->assertFalse($store->canSell());
        $this->assertFalse((bool) $store->can_sell_prescription);
        $this->assertFalse((bool) $store->can_sell_controlled);
        $this->assertNull($store->verified_at);
    }

    public function test_an_unapproved_pharmacys_stock_is_not_in_the_catalogue(): void
    {
        Storage::fake('local');

        $owner = $this->freshOwner();
        $this->createPharmacy($this->tokenFor($owner))->assertCreated();

        $store = Store::where('owner_id', $owner->id)->first();

        $product = Product::factory()->create([
            'store_id' => $store->id,
            'is_active' => true,
            'stock_quantity' => 10,
            'requires_prescription' => false,
        ]);

        $this->assertFalse(
            Product::sellable()->whereKey($product->id)->exists(),
            'a pharmacy awaiting approval must not have stock on sale'
        );
    }

    public function test_the_licence_document_is_kept_off_public_storage(): void
    {
        Storage::fake('local');

        $owner = $this->freshOwner();
        $this->createPharmacy($this->tokenFor($owner))->assertCreated();

        $store = Store::where('owner_id', $owner->id)->first();

        $this->assertNotNull($store->pharmacy_license_document);
        $this->assertStringStartsWith('licenses/', $store->pharmacy_license_document);
        Storage::disk('local')->assertExists($store->pharmacy_license_document);
    }

    // ---- gate 2: awaiting approval ----------------------------------------

    public function test_the_gate_reports_pending_once_the_pharmacy_is_submitted(): void
    {
        Storage::fake('local');

        $owner = $this->freshOwner();
        $headers = $this->tokenFor($owner);

        $this->createPharmacy($headers)->assertCreated();

        $this->state($headers)
            ->assertOk()
            ->assertJsonPath('data.has_store', true)
            ->assertJsonPath('data.stage', 'pending')
            ->assertJsonPath('data.can_sell', false)
            ->assertJsonPath('data.store.name', 'Melrose Pharmacy');
    }

    public function test_a_second_submission_while_under_review_is_refused(): void
    {
        Storage::fake('local');

        $headers = $this->tokenFor($this->freshOwner());

        $this->createPharmacy($headers)->assertCreated();
        $this->createPharmacy($headers)
            ->assertStatus(422)
            ->assertJsonPath('code', 'application_under_review');
    }

    // ---- gate 2: opened ----------------------------------------------------

    public function test_approval_opens_the_dashboard_and_the_shop(): void
    {
        Storage::fake('local');

        $owner = $this->freshOwner();
        $headers = $this->tokenFor($owner);

        $this->createPharmacy($headers)->assertCreated();
        $this->approve(Store::where('owner_id', $owner->id)->first());

        $this->state($headers)
            ->assertOk()
            ->assertJsonPath('data.stage', 'active')
            ->assertJsonPath('data.can_sell', true)
            ->assertJsonPath('data.blocked_reason', null);
    }

    // ---- the states the owner has to be able to act on ---------------------

    /**
     * A rejected pharmacy is told the reason. It cannot correct an application
     * it is not told the fault in, and the dashboard is the only place it will
     * look after reading the email.
     */
    public function test_a_rejected_pharmacy_is_given_the_reason(): void
    {
        Storage::fake('local');
        Mail::fake();

        $owner = $this->freshOwner();
        $headers = $this->tokenFor($owner);

        $this->createPharmacy($headers)->assertCreated();
        $store = Store::where('owner_id', $owner->id)->first();

        $this->postJson("/api/v1/admin/stores/{$store->id}/verification/review", [
            'action' => 'reject',
            'notes' => 'The licence photo is unreadable.',
        ], $this->tokenFor($this->makeUser(['role' => 'admin'])))->assertOk();

        $this->state($headers)
            ->assertOk()
            ->assertJsonPath('data.stage', 'rejected')
            ->assertJsonPath('data.can_sell', false)
            ->assertJsonPath('data.store.verification_notes', 'The licence photo is unreadable.');
    }

    /**
     * An approved licence that has lapsed is its own state, not "pending".
     * Telling a pharmacist their licence is under review when it has simply run
     * out sends them to wait for an email that is never coming.
     */
    public function test_a_lapsed_licence_reads_as_expired_not_pending(): void
    {
        Storage::fake('local');

        $owner = $this->freshOwner();
        $headers = $this->tokenFor($owner);

        $this->createPharmacy($headers)->assertCreated();
        $store = Store::where('owner_id', $owner->id)->first();
        $this->approve($store);

        $store->fresh()->update(['pharmacy_license_expiry' => now()->subDay()]);

        $this->state($headers)
            ->assertOk()
            ->assertJsonPath('data.stage', 'expired')
            ->assertJsonPath('data.can_sell', false);
    }

    /**
     * Suspension is the admin's other lever and has nothing to do with the
     * licence, so it must not be reported as a licence problem.
     */
    public function test_a_suspended_pharmacy_is_not_reported_as_a_licence_problem(): void
    {
        Storage::fake('local');

        $owner = $this->freshOwner();
        $headers = $this->tokenFor($owner);

        $this->createPharmacy($headers)->assertCreated();
        $store = Store::where('owner_id', $owner->id)->first();
        $this->approve($store);

        $this->postJson("/api/v1/admin/stores/{$store->id}/suspend", [], $this->tokenFor($this->makeUser(['role' => 'admin'])))
            ->assertOk();

        $response = $this->state($headers)->assertOk();

        $this->assertFalse($response->json('data.can_sell'));
        $this->assertNotSame('active', $response->json('data.stage'));
    }

    // ---- telling a reviewer there is something to review -------------------

    /**
     * The question this whole section answers: does anybody find out?
     *
     * Only /sell/register alerted an admin. Both other routes into the same
     * queue -- an existing customer applying while signed in, and an
     * admin-created owner setting up in the dashboard -- landed silently, so a
     * pharmacy could sit pending indefinitely while being told by email that we
     * would get back to them either way.
     */
    public function test_an_admin_is_told_when_an_owner_sets_up_from_the_dashboard(): void
    {
        Storage::fake('local');
        Mail::fake();

        $admin = $this->makeUser(['role' => 'admin']);

        $this->createPharmacy($this->tokenFor($this->freshOwner()))->assertCreated();

        Mail::assertSent(
            AdminAlertEmail::class,
            fn ($mail) => $mail->hasTo($admin->email)
                && str_contains($mail->subject, 'New pharmacy application')
                && str_contains($mail->subject, 'Melrose Pharmacy')
        );
    }

    /** Every administrator, not just whoever happens to be first. */
    public function test_every_administrator_is_told(): void
    {
        Storage::fake('local');
        Mail::fake();

        $one = $this->makeUser(['role' => 'admin']);
        $two = $this->makeUser(['role' => 'admin']);

        $this->createPharmacy($this->tokenFor($this->freshOwner()))->assertCreated();

        Mail::assertSent(AdminAlertEmail::class, fn ($mail) => $mail->hasTo($one->email));
        Mail::assertSent(AdminAlertEmail::class, fn ($mail) => $mail->hasTo($two->email));
    }

    /**
     * A resubmission is a different message. A reviewer who has already turned
     * this pharmacy down once needs to know that is what they are looking at,
     * not read it as a fresh application.
     */
    public function test_a_resubmission_says_so(): void
    {
        Storage::fake('local');
        Mail::fake();

        $this->makeUser(['role' => 'admin']);

        $owner = $this->freshOwner();
        $headers = $this->tokenFor($owner);

        $this->createPharmacy($headers)->assertCreated();
        $store = Store::where('owner_id', $owner->id)->first();

        $this->postJson("/api/v1/admin/stores/{$store->id}/verification/review", [
            'action' => 'reject',
            'notes' => 'The licence photo is unreadable.',
        ], $this->tokenFor($this->makeUser(['role' => 'admin'])))->assertOk();

        $this->createPharmacy($headers)->assertCreated();

        Mail::assertSent(
            AdminAlertEmail::class,
            fn ($mail) => str_contains($mail->subject, 'Pharmacy licence resubmitted')
        );
    }

    /**
     * The note has to match where the applicant actually is. Telling a reviewer
     * an admin-created owner is "outside the dashboard" is false -- they are
     * inside it with everything but their own shop page locked -- and a reviewer
     * acting on a false description of the applicant's position is exactly what
     * this alert exists to prevent.
     */
    public function test_the_alert_describes_where_the_applicant_actually_is(): void
    {
        Storage::fake('local');
        Mail::fake();

        $this->makeUser(['role' => 'admin']);

        $this->createPharmacy($this->tokenFor($this->freshOwner()))->assertCreated();

        Mail::assertSent(
            AdminAlertEmail::class,
            fn ($mail) => str_contains((string) $mail->note, 'dashboard except their own shop is locked')
                && ! str_contains((string) $mail->note, 'outside the dashboard')
        );
    }

    /**
     * Best-effort, like every other admin alert: the licence is already saved
     * and the applicant has been told it is with us, so a mail outage must not
     * turn a successful application into a failed one.
     */
    public function test_a_mail_failure_does_not_fail_the_application(): void
    {
        Storage::fake('local');

        $this->makeUser(['role' => 'admin']);

        Mail::shouldReceive('to')->andThrow(new \RuntimeException('SMTP down'));

        $owner = $this->freshOwner();
        $this->createPharmacy($this->tokenFor($owner))->assertCreated();

        $this->assertNotNull(
            Store::where('owner_id', $owner->id)->first(),
            'the pharmacy must survive a mail outage'
        );
    }

    // ---- Google accounts do not become pharmacies --------------------------

    /**
     * The rule that keeps the two kinds of account apart.
     *
     * Google sign-in is offered to shoppers, who know that is what they are
     * signing up as. A pharmacy registers on the Sell page with its own email
     * and password, or is created by an administrator. Without this, a shopper's
     * Google account could turn into a dashboard account reachable only through
     * a provider the dashboard refuses -- and every question about how such an
     * owner is meant to get in exists solely because of that crossover.
     */
    public function test_a_google_account_cannot_apply_to_sell(): void
    {
        Storage::fake('local');

        $owner = $this->freshOwner();
        $owner->update([
            'auth_provider' => User::AUTH_GOOGLE,
            'google_id' => 'google-'.uniqid(),
            'password' => null,
        ]);

        $this->createPharmacy($this->tokenFor($owner))
            ->assertStatus(422)
            ->assertJsonPath('code', 'google_account_cannot_sell');

        $this->assertNull(
            Store::where('owner_id', $owner->id)->first(),
            'no pharmacy should have been created'
        );
    }

    /**
     * Refused at the application, not at approval. Telling somebody their
     * account is unsuitable after a reviewer has read their licence is worse
     * than telling them before they upload it.
     */
    public function test_the_refusal_says_to_register_with_an_email_and_password(): void
    {
        Storage::fake('local');

        $owner = $this->freshOwner();
        $owner->update([
            'auth_provider' => User::AUTH_GOOGLE,
            'google_id' => 'google-'.uniqid(),
            'password' => null,
        ]);

        $message = $this->createPharmacy($this->tokenFor($owner))
            ->assertStatus(422)
            ->json('message');

        $this->assertStringContainsString('email address and password', $message);
    }

    /**
     * Belt and braces at the moment of promotion. Reaching it means a store was
     * attached to a Google account by some route other than the application, and
     * promoting it would build the very account the rule prevents.
     */
    public function test_approval_never_promotes_a_google_account(): void
    {
        Storage::fake('local');

        // A customer, not freshOwner(): promotion is only observable on an
        // account that does not already hold the role.
        $owner = $this->makeUser(['role' => 'customer']);
        $this->createPharmacy($this->tokenFor($owner))->assertCreated();

        $store = Store::where('owner_id', $owner->id)->firstOrFail();

        // Converted after the fact, which the application itself would refuse.
        $owner->update([
            'auth_provider' => User::AUTH_GOOGLE,
            'google_id' => 'google-'.uniqid(),
            'password' => null,
        ]);

        StoreApplicationController::grantDashboardAccess($store->fresh());

        $fresh = $owner->fresh();

        $this->assertSame('customer', $fresh->role, 'a Google account must not be promoted');
        $this->assertNull($fresh->store_id);
    }

    /** The same call promotes an ordinary password account, as it always did. */
    public function test_approval_still_promotes_a_password_account(): void
    {
        Storage::fake('local');

        $owner = $this->makeUser(['role' => 'customer']);
        $this->createPharmacy($this->tokenFor($owner))->assertCreated();

        $store = Store::where('owner_id', $owner->id)->firstOrFail();

        StoreApplicationController::grantDashboardAccess($store->fresh());

        $this->assertSame('store_owner', $owner->fresh()->role);
    }

    /** A password account is unaffected: this is the ordinary path. */
    public function test_a_password_account_applies_as_before(): void
    {
        Storage::fake('local');

        $owner = $this->freshOwner();

        $this->createPharmacy($this->tokenFor($owner))->assertCreated();

        $this->assertNotNull(Store::where('owner_id', $owner->id)->first());
    }
}
