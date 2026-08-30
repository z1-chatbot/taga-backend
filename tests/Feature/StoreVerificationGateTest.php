<?php

namespace Tests\Feature;

use App\Mail\StoreVerificationDecisionEmail;
use App\Models\Cart;
use App\Models\Product;
use App\Models\Store;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

/**
 * A pharmacy sets itself up freely; nothing it lists sells until we say so.
 *
 * Registering, filling in the shop details and building a catalogue are all
 * open — that work should happen while the licence is being reviewed, not after
 * it. The gate is on selling. Before this, only prescription and controlled
 * stock was gated, so an unvetted pharmacy could register and take money for
 * over-the-counter medicine the same afternoon.
 */
class StoreVerificationGateTest extends TestCase
{
    private function storeWith(string $verification, array $extra = []): Store
    {
        $owner = $this->makeUser(['role' => 'store_owner']);

        return Store::create(array_merge([
            'owner_id' => $owner->id,
            'name' => 'Test Pharmacy',
            'slug' => 'gate-'.uniqid(),
            'state' => 'Lagos',
            'city' => 'Ikeja',
            'status' => 'active',
            'verification_status' => $verification,
        ], $extra));
    }

    private function otcProductFor(Store $store): Product
    {
        return Product::factory()->create([
            'store_id' => $store->id,
            'is_active' => true,
            'stock_quantity' => 10,
            'requires_prescription' => false,
        ]);
    }

    private function address(): array
    {
        return [
            'firstName' => 'Ada', 'lastName' => 'Obi', 'email' => 'ada@example.test',
            'address' => '1 Test Street', 'city' => 'Ikeja', 'state' => 'Lagos',
            'country' => 'Nigeria', 'phone' => '08012345678',
        ];
    }

    private function listedIds(): array
    {
        $rows = $this->getJson('/api/v1/products?per_page=100')->assertOk()->json('data.data');

        return array_column($rows ?? [], 'id');
    }

    // ---- setting up stays open ---------------------------------------------

    public function test_an_unverified_pharmacy_can_still_build_its_catalogue(): void
    {
        $store = $this->storeWith(Store::VERIFICATION_PENDING);
        $product = $this->otcProductFor($store);

        // The listing exists and is theirs to manage while we review the licence.
        $this->assertDatabaseHas('products', ['id' => $product->id, 'is_active' => true]);
    }

    // ---- but nothing sells -------------------------------------------------

    public function test_stock_from_a_pharmacy_that_never_submitted_is_not_on_sale(): void
    {
        $product = $this->otcProductFor($this->storeWith(Store::VERIFICATION_UNSUBMITTED));

        $this->assertNotContains($product->id, $this->listedIds());
    }

    public function test_stock_from_a_pharmacy_awaiting_review_is_not_on_sale(): void
    {
        $product = $this->otcProductFor($this->storeWith(Store::VERIFICATION_PENDING));

        $this->assertNotContains($product->id, $this->listedIds());
    }

    public function test_stock_from_a_refused_pharmacy_is_not_on_sale(): void
    {
        $product = $this->otcProductFor($this->storeWith(Store::VERIFICATION_REJECTED));

        $this->assertNotContains($product->id, $this->listedIds());
    }

    public function test_an_approved_pharmacys_stock_is_on_sale(): void
    {
        $product = $this->otcProductFor($this->storeWith(Store::VERIFICATION_APPROVED));

        $this->assertContains($product->id, $this->listedIds());
    }

    public function test_an_unverified_product_page_is_not_reachable(): void
    {
        $product = $this->otcProductFor($this->storeWith(Store::VERIFICATION_PENDING));

        $this->getJson("/api/v1/products/{$product->id}")->assertStatus(404);
    }

    public function test_checkout_refuses_stock_from_an_unlicensed_pharmacy(): void
    {
        $this->serviceableZone('Lagos', 'Lagos');

        $store = $this->storeWith(Store::VERIFICATION_PENDING);
        $product = $this->otcProductFor($store);
        $guest = 'gate-'.uniqid();

        // Posting the product id straight at checkout, bypassing the catalogue.
        Cart::create([
            'session_id' => $guest,
            'product_id' => $product->id,
            'quantity' => 1,
            'price' => $product->price,
        ]);

        $this->postJson('/api/v1/orders', [
            'shipping_address' => $this->address(),
            'payment_method' => 'online',
            'delivery_type' => 'home_delivery',
        ], $this->guestHeaders($guest))
            ->assertStatus(422)
            ->assertJsonPath('code', 'store_not_licensed');
    }

    public function test_a_lapsed_licence_stops_the_sale_too(): void
    {
        $store = $this->storeWith(Store::VERIFICATION_APPROVED, [
            'pharmacy_license_expiry' => now()->subDay(),
        ]);

        $this->assertFalse($store->canSell(), 'approval does not survive the licence lapsing');

        $product = $this->otcProductFor($store);

        $this->assertNotContains($product->id, $this->listedIds());
    }

    public function test_platform_owned_stock_is_unaffected(): void
    {
        $product = Product::factory()->create([
            'store_id' => null,
            'is_active' => true,
            'stock_quantity' => 5,
            'requires_prescription' => false,
        ]);

        $this->assertContains($product->id, $this->listedIds());
    }

    // ---- and the pharmacy is told ------------------------------------------

    /**
     * Addressed to the person who registered, not to the premises.
     *
     * The application captures two addresses on two separate steps — `email` is
     * the pharmacy's public contact, `owner_email` is whoever signed up — and
     * this used to prefer the first. Wherever a shop published a general address
     * (info@, a branch inbox nobody opens) the decision went there and the
     * person waiting on it never saw it. Approval is what promotes their
     * account and opens the dashboard, so it belongs to the human.
     */
    public function test_approval_emails_the_person_who_registered_not_the_premises(): void
    {
        Mail::fake();

        $store = $this->storeWith(Store::VERIFICATION_PENDING, ['email' => 'shop@pharmacy.test']);
        $ownerEmail = $store->owner->email;

        $this->assertNotSame('shop@pharmacy.test', $ownerEmail, 'the fixture must use two different addresses');

        $this->postJson("/api/v1/admin/stores/{$store->id}/verification/review", [
            'action' => 'approve',
            'can_sell_prescription' => true,
            'pharmacy_license_number' => 'PCN-12345',
            'pharmacy_license_expiry' => now()->addYear()->toDateString(),
        ], $this->tokenFor($this->makeUser(['role' => 'admin'])))->assertOk();

        Mail::assertSent(StoreVerificationDecisionEmail::class, function ($mail) use ($ownerEmail) {
            return $mail->approved === true
                && $mail->hasTo($ownerEmail)
                && ! $mail->hasTo('shop@pharmacy.test');
        });
    }

    /**
     * The premises address is the fallback, not the default. A store whose owner
     * record carries no usable address must still be reachable rather than
     * silently unsendable.
     *
     * Blanked rather than nulled: both `users.email` and `stores.owner_id` are
     * NOT NULL, so an empty address is the only shape this can actually take in
     * this schema — and it is the one `?:` has to survive.
     */
    public function test_the_pharmacy_address_is_used_when_the_owner_has_none(): void
    {
        Mail::fake();

        $store = $this->storeWith(Store::VERIFICATION_PENDING, ['email' => 'shop@pharmacy.test']);
        $store->owner->forceFill(['email' => ''])->save();

        $this->postJson("/api/v1/admin/stores/{$store->id}/verification/review", [
            'action' => 'approve',
            'can_sell_prescription' => true,
            'pharmacy_license_number' => 'PCN-12345',
            'pharmacy_license_expiry' => now()->addYear()->toDateString(),
        ], $this->tokenFor($this->makeUser(['role' => 'admin'])))->assertOk();

        Mail::assertSent(StoreVerificationDecisionEmail::class, fn ($mail) => $mail->hasTo('shop@pharmacy.test'));
    }

    /**
     * The mailbox decides the SMTP credentials, not just the From line, so it
     * decides whether the message is delivered at all. This one goes out with
     * the other account messages; config/mail.php already points the Reply-To
     * of `noreply` at support, so a pharmacy that replies still reaches a person.
     */
    public function test_the_decision_goes_out_through_the_noreply_mailbox(): void
    {
        $mailbox = (new \ReflectionClass(StoreVerificationDecisionEmail::class))
            ->getDefaultProperties()['mailbox'] ?? null;

        $this->assertSame('noreply', $mailbox);
    }

    public function test_rejection_emails_the_pharmacy_with_the_reason(): void
    {
        Mail::fake();

        $store = $this->storeWith(Store::VERIFICATION_PENDING, ['email' => 'shop@pharmacy.test']);

        $this->postJson("/api/v1/admin/stores/{$store->id}/verification/review", [
            'action' => 'reject',
            'notes' => 'The licence document is not legible.',
        ], $this->tokenFor($this->makeUser(['role' => 'admin'])))->assertOk();

        Mail::assertSent(StoreVerificationDecisionEmail::class, function ($mail) {
            return $mail->approved === false
                && $mail->reason === 'The licence document is not legible.';
        });
    }

    public function test_approval_puts_the_pharmacys_stock_on_sale(): void
    {
        Mail::fake();

        $store = $this->storeWith(Store::VERIFICATION_PENDING);
        $product = $this->otcProductFor($store);

        $this->assertNotContains($product->id, $this->listedIds());

        $this->postJson("/api/v1/admin/stores/{$store->id}/verification/review", [
            'action' => 'approve',
            'pharmacy_license_number' => 'PCN-12345',
            'pharmacy_license_expiry' => now()->addYear()->toDateString(),
        ], $this->tokenFor($this->makeUser(['role' => 'admin'])))->assertOk();

        $this->assertContains(
            $product->id,
            $this->listedIds(),
            'approving the licence is what puts a catalogue on sale'
        );
    }
}
