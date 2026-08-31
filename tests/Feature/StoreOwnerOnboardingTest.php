<?php

namespace Tests\Feature;

use App\Models\Product;
use App\Models\Store;
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
}
