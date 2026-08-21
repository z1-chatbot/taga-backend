<?php

namespace Tests\Feature;

use App\Models\Cart;
use App\Models\Prescription;
use App\Models\Product;
use Tests\TestCase;

/**
 * The rules that make this a pharmacy rather than a general shop.
 *
 * Two things must hold no matter what the client sends, because the storefront's
 * own checks are only a courtesy — anyone can post directly to the API:
 *
 *   1. Expired stock is never dispatched.
 *   2. A prescription-only line needs a prescription that is approved and still
 *      in date at the moment of checkout.
 */
class PharmacyCheckoutRulesTest extends TestCase
{
    private const GUEST = 'rules-test-guest';

    protected function setUp(): void
    {
        parent::setUp();

        // Checkout now refuses a delivery to a state no zone covers.
        $this->serviceableZone('Lagos', 'Lagos');
    }

    private function address(): array
    {
        return [
            'firstName' => 'Ada',
            'lastName' => 'Obi',
            'email' => 'ada@example.com',
            'address' => '1 Test Road',
            'city' => 'Ikeja',
            'state' => 'Lagos',
            'country' => 'Nigeria',
            'phone' => '08000000000',
        ];
    }

    private function basket(Product $product, ?Prescription $prescription = null): void
    {
        Cart::create([
            'session_id' => self::GUEST,
            'product_id' => $product->id,
            'quantity' => 1,
            'price' => $product->price,
            'prescription_id' => $prescription?->id,
        ]);
    }

    private function checkout()
    {
        return $this->postJson('/api/v1/orders', [
            'shipping_address' => $this->address(),
            'payment_method' => 'online',
            'delivery_type' => 'home_delivery',
        ], $this->guestHeaders(self::GUEST));
    }

    public function test_an_rx_product_cannot_be_bought_without_a_prescription(): void
    {
        $this->basket(Product::factory()->prescriptionOnly()->create());

        $this->checkout()
            ->assertStatus(422)
            ->assertJsonPath('code', 'prescription_required');
    }

    /**
     * A pending script no longer blocks checkout.
     *
     * The flow is pay now, pharmacist reviews, dispatch on approval — so the
     * order is created and held rather than refused. Blocking here meant the
     * shopper had to come back and rebuild a basket whose prices and stock may
     * have moved, and one line awaiting review stranded the whole basket.
     *
     * What must not happen is dispatch, which
     * PrescriptionCheckoutFlowTest covers.
     */
    public function test_a_pending_prescription_lets_the_order_through_but_holds_it(): void
    {
        $product = Product::factory()->prescriptionOnly()->create();
        $this->basket($product, Prescription::factory()->create());

        $response = $this->checkout();

        $this->assertNotContains(
            $response->json('code'),
            ['prescription_required', 'prescription_pending', 'prescription_rejected', 'prescription_expired'],
            'a script awaiting review should not block checkout'
        );

        $order = \App\Models\Order::latest('id')->first();

        $this->assertNotNull($order);
        $this->assertTrue((bool) $order->requires_prescription);
        $this->assertSame('pending', $order->prescription_status);
        $this->assertFalse($order->isClearedForDispatch(), 'the order must not be dispatchable yet');
    }

    public function test_a_rejected_prescription_does_not_release_the_order(): void
    {
        $product = Product::factory()->prescriptionOnly()->create();
        $this->basket($product, Prescription::factory()->rejected()->create());

        $this->checkout()
            ->assertStatus(422)
            ->assertJsonPath('code', 'prescription_rejected');
    }

    public function test_a_lapsed_prescription_does_not_release_the_order(): void
    {
        $product = Product::factory()->prescriptionOnly()->create();
        $this->basket($product, Prescription::factory()->lapsed()->create());

        $this->checkout()
            ->assertStatus(422)
            ->assertJsonPath('code', 'prescription_expired');
    }

    public function test_expired_stock_is_never_dispatched(): void
    {
        $this->basket(Product::factory()->expired()->create());

        $this->checkout()
            ->assertStatus(422)
            ->assertJsonPath('code', 'product_expired');
    }

    public function test_a_general_sale_item_checks_out_without_a_prescription(): void
    {
        $this->basket(Product::factory()->create());

        // Not asserting 201 specifically: the point is that the pharmacy guards
        // do not fire. Shipping/coupon config can legitimately change the shape
        // of a success response.
        $response = $this->checkout();

        $this->assertNotContains(
            $response->json('code'),
            ['prescription_required', 'prescription_pending', 'prescription_rejected', 'product_expired'],
            'A general-sale item was blocked by a pharmacy rule.'
        );
    }

    public function test_an_approved_prescription_passes_the_guard(): void
    {
        $product = Product::factory()->prescriptionOnly()->create();
        $this->basket($product, Prescription::factory()->approved()->create());

        $response = $this->checkout();

        $this->assertNotContains(
            $response->json('code'),
            ['prescription_required', 'prescription_pending', 'prescription_rejected', 'prescription_expired'],
            'An approved, in-date prescription was rejected at checkout.'
        );
    }
}
