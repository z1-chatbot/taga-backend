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

    // ---- how an order can be paid for --------------------------------------

    /**
     * There is one way to pay, and the basket says so.
     *
     * Three tests here used to cover a cash-on-delivery switch: that the basket
     * offered it while it was on, withdrew it when it went off, and left a
     * cash-only basket unpayable rather than advertising something checkout
     * would refuse. The switch, the per-product override and the whole payment
     * branch are gone — Taga takes online payment before dispatch and has no
     * code path that accepts anything else — so what is worth pinning now is
     * that nothing reintroduces a second option.
     */
    public function test_the_basket_offers_online_payment_and_nothing_else(): void
    {
        $product = Product::factory()->create(['price' => 800]);

        $this->postJson('/api/v1/cart', ['product_id' => $product->id, 'quantity' => 1], $this->guestHeaders(self::GUEST));

        $response = $this->getJson('/api/v1/cart', $this->guestHeaders(self::GUEST))->assertOk();

        $this->assertSame(['paystack'], $response->json('data.allowed_payment_methods'));
        $this->assertNull($response->json('data.payment_restriction_message'));
    }

    /**
     * A pharmacy cannot mark stock cash-only, so a basket can never be left
     * with nothing it can pay with. The option is gone from the product form
     * and refused by the API; this pins the API half.
     */
    public function test_a_product_cannot_be_marked_cash_on_delivery_only(): void
    {
        $admin = $this->makeUser(['role' => 'admin']);

        $this->postJson('/api/v1/admin/products', [
            'name' => 'Paracetamol 500mg',
            'price' => 800,
            'payment_method_restriction' => 'cash_on_delivery',
        ], $this->tokenFor($admin))
            ->assertStatus(422)
            ->assertJsonValidationErrors('payment_method_restriction');
    }
}
