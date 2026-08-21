<?php

namespace Tests\Feature;

use App\Models\Cart;
use App\Models\Product;
use Tests\TestCase;

/**
 * Basket behaviour for guests and signed-in shoppers.
 *
 * `total_items` counts units rather than lines — the storefront's header badge
 * reads that field, and showing "2" for a basket holding three boxes would be
 * wrong in a way nobody notices until they are charged.
 */
class CartTest extends TestCase
{
    private const GUEST = 'cart-test-guest';

    public function test_a_guest_can_add_an_item(): void
    {
        $product = Product::factory()->create(['price' => 800]);

        $this->postJson('/api/v1/cart', [
            'product_id' => $product->id,
            'quantity' => 2,
        ], $this->guestHeaders(self::GUEST))
            ->assertSuccessful()
            ->assertJsonPath('success', true);

        $this->assertDatabaseHas('carts', [
            'session_id' => self::GUEST,
            'product_id' => $product->id,
            'quantity' => 2,
        ]);
    }

    public function test_total_items_counts_units_not_lines(): void
    {
        $first = Product::factory()->create(['price' => 800]);
        $second = Product::factory()->create(['price' => 3500]);

        $this->postJson('/api/v1/cart', ['product_id' => $first->id, 'quantity' => 2], $this->guestHeaders(self::GUEST));
        $this->postJson('/api/v1/cart', ['product_id' => $second->id, 'quantity' => 1], $this->guestHeaders(self::GUEST));

        $response = $this->getJson('/api/v1/cart', $this->guestHeaders(self::GUEST))->assertOk();

        $this->assertCount(2, $response->json('data.items'), 'Expected two lines.');
        $this->assertSame(3, $response->json('data.total_items'), 'Expected three units.');
    }

    public function test_the_subtotal_reflects_quantity(): void
    {
        $product = Product::factory()->create(['price' => 800]);

        $this->postJson('/api/v1/cart', ['product_id' => $product->id, 'quantity' => 3], $this->guestHeaders(self::GUEST));

        $response = $this->getJson('/api/v1/cart', $this->guestHeaders(self::GUEST))->assertOk();

        $this->assertEquals(2400, $response->json('data.subtotal'));
    }

    public function test_adding_the_same_product_twice_merges_the_line(): void
    {
        $product = Product::factory()->create();

        $this->postJson('/api/v1/cart', ['product_id' => $product->id, 'quantity' => 1], $this->guestHeaders(self::GUEST));
        $this->postJson('/api/v1/cart', ['product_id' => $product->id, 'quantity' => 2], $this->guestHeaders(self::GUEST));

        $response = $this->getJson('/api/v1/cart', $this->guestHeaders(self::GUEST))->assertOk();

        $this->assertCount(1, $response->json('data.items'), 'Adding the same product should not create a second line.');
        $this->assertSame(3, $response->json('data.total_items'));
    }

    public function test_a_line_can_be_removed(): void
    {
        $product = Product::factory()->create();

        $this->postJson('/api/v1/cart', ['product_id' => $product->id, 'quantity' => 1], $this->guestHeaders(self::GUEST));

        $line = Cart::where('session_id', self::GUEST)->firstOrFail();

        $this->deleteJson("/api/v1/cart/{$line->id}", [], $this->guestHeaders(self::GUEST))
            ->assertSuccessful();

        $this->assertDatabaseMissing('carts', ['id' => $line->id]);
    }

    public function test_adding_an_unknown_product_is_rejected(): void
    {
        $this->postJson('/api/v1/cart', [
            'product_id' => 99999999,
            'quantity' => 1,
        ], $this->guestHeaders(self::GUEST))->assertStatus(422);
    }

    public function test_quantity_must_be_at_least_one(): void
    {
        $product = Product::factory()->create();

        $this->postJson('/api/v1/cart', [
            'product_id' => $product->id,
            'quantity' => 0,
        ], $this->guestHeaders(self::GUEST))->assertStatus(422);
    }

    public function test_one_guests_basket_is_not_visible_to_another(): void
    {
        $product = Product::factory()->create();

        $this->postJson('/api/v1/cart', ['product_id' => $product->id, 'quantity' => 1], $this->guestHeaders('guest-a'));

        $response = $this->getJson('/api/v1/cart', $this->guestHeaders('guest-b'))->assertOk();

        $this->assertCount(0, $response->json('data.items') ?? []);
    }

    // ---- the cash-on-delivery switch ----------------------------------------

    /**
     * The basket has to agree with checkout about cash on delivery.
     *
     * enable_cod gates order creation, but the basket used to advertise cash on
     * delivery regardless — so a shopper picked it, submitted, and only then
     * was refused. These cover the two sides of that.
     */
    private function setCod(bool $enabled): void
    {
        \App\Models\SystemSetting::updateOrCreate(
            ['category' => \App\Models\SystemSetting::CATEGORY_GENERAL, 'key' => 'enable_cod'],
            [
                'value' => $enabled,
                'type' => \App\Models\SystemSetting::TYPE_BOOLEAN,
                'label' => 'Enable COD',
                'is_active' => true,
            ]
        );
    }

    public function test_the_basket_offers_cash_on_delivery_while_it_is_switched_on(): void
    {
        $this->setCod(true);
        $product = Product::factory()->create(['price' => 800]);

        $this->postJson('/api/v1/cart', ['product_id' => $product->id, 'quantity' => 1], $this->guestHeaders(self::GUEST));

        $response = $this->getJson('/api/v1/cart', $this->guestHeaders(self::GUEST))->assertOk();

        $this->assertContains('cash_on_delivery', $response->json('data.allowed_payment_methods'));
        $this->assertContains('paystack', $response->json('data.allowed_payment_methods'));
    }

    public function test_switching_cash_on_delivery_off_withdraws_it_from_the_basket(): void
    {
        $this->setCod(false);
        $product = Product::factory()->create(['price' => 800]);

        $this->postJson('/api/v1/cart', ['product_id' => $product->id, 'quantity' => 1], $this->guestHeaders(self::GUEST));

        $response = $this->getJson('/api/v1/cart', $this->guestHeaders(self::GUEST))->assertOk();

        $this->assertNotContains('cash_on_delivery', $response->json('data.allowed_payment_methods'));
        $this->assertContains('paystack', $response->json('data.allowed_payment_methods'));
        $this->assertNotNull($response->json('data.payment_restriction_message'));
    }

    public function test_a_cash_only_basket_is_left_unpayable_rather_than_misleading(): void
    {
        // Checkout would refuse this order, so the basket must not pretend
        // otherwise. Nothing payable is the honest answer.
        $this->setCod(false);
        $product = Product::factory()->create([
            'price' => 800,
            'payment_method_restriction' => 'cash_on_delivery',
        ]);

        $this->postJson('/api/v1/cart', ['product_id' => $product->id, 'quantity' => 1], $this->guestHeaders(self::GUEST));

        $response = $this->getJson('/api/v1/cart', $this->guestHeaders(self::GUEST))->assertOk();

        $this->assertSame([], $response->json('data.allowed_payment_methods'));
        $this->assertStringContainsString('switched off', $response->json('data.payment_restriction_message'));
    }
}
