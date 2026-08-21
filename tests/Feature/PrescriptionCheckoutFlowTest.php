<?php

namespace Tests\Feature;

use App\Exceptions\PrescriptionNotClearedException;
use App\Models\Order;
use App\Models\Prescription;
use App\Models\Product;
use Tests\TestCase;

/**
 * The pay-now, review-before-dispatch flow for prescription medicines.
 *
 * A shopper can pay for a basket whose script is still awaiting review. Nothing
 * ships until a pharmacist approves, and a rejection cancels the order, puts the
 * stock back, and flags a paid order for refund.
 *
 * Before this, a prescription id could never reach a cart line at all, so any
 * basket containing an Rx medicine failed at checkout permanently.
 */
class PrescriptionCheckoutFlowTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        // Checkout now refuses a delivery to a state no zone covers.
        $this->serviceableZone('Lagos', 'Lagos');
    }

    private function rxProduct(): Product
    {
        return Product::factory()->prescriptionOnly()->create(['stock_quantity' => 50]);
    }

    private function scriptFor($user, string $status = Prescription::STATUS_PENDING): Prescription
    {
        return Prescription::factory()->create([
            'user_id' => $user->id,
            'status' => $status,
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

    /**
     * An Rx medicine goes into the basket freely — requiring the script before
     * you can even add the item means uploading before shopping, which is a
     * strange order to do things in. The basket flags the line instead, and
     * checkout is where the script becomes mandatory.
     */
    public function test_an_rx_product_enters_the_basket_flagged_as_needing_a_script(): void
    {
        $user = $this->makeUser();
        $product = $this->rxProduct();

        $this->postJson('/api/v1/cart', [
            'product_id' => $product->id,
            'quantity' => 1,
        ], $this->tokenFor($user))
            ->assertOk()
            ->assertJsonPath('data.prescriptions_outstanding', 1)
            ->assertJsonPath('data.items.0.needs_prescription_upload', true);
    }

    public function test_checkout_is_refused_while_a_line_still_needs_a_script(): void
    {
        $user = $this->makeUser();
        $product = $this->rxProduct();

        $this->postJson('/api/v1/cart', [
            'product_id' => $product->id,
            'quantity' => 1,
        ], $this->tokenFor($user))->assertOk();

        $this->postJson('/api/v1/orders', [
            'shipping_address' => $this->address(),
            'billing_address' => $this->address(),
            'payment_method' => 'cash_on_delivery',
        ], $this->tokenFor($user))
            ->assertStatus(422)
            ->assertJsonPath('code', 'prescription_required');
    }

    public function test_a_script_can_be_attached_to_a_line_already_in_the_basket(): void
    {
        $user = $this->makeUser();
        $product = $this->rxProduct();
        $script = $this->scriptFor($user);

        $added = $this->postJson('/api/v1/cart', [
            'product_id' => $product->id,
            'quantity' => 1,
        ], $this->tokenFor($user));

        $itemId = $added->json('data.items.0.id');

        $this->postJson("/api/v1/cart/{$itemId}/prescription", [
            'prescription_id' => $script->id,
        ], $this->tokenFor($user))
            ->assertOk()
            ->assertJsonPath('data.prescriptions_outstanding', 0)
            ->assertJsonPath('data.items.0.needs_prescription_upload', false);
    }

    public function test_a_line_cannot_be_given_someone_elses_script(): void
    {
        $user = $this->makeUser();
        $stranger = $this->makeUser();
        $product = $this->rxProduct();
        $script = $this->scriptFor($stranger);

        $added = $this->postJson('/api/v1/cart', [
            'product_id' => $product->id,
            'quantity' => 1,
        ], $this->tokenFor($user));

        $itemId = $added->json('data.items.0.id');

        $this->postJson("/api/v1/cart/{$itemId}/prescription", [
            'prescription_id' => $script->id,
        ], $this->tokenFor($user))->assertStatus(404);
    }

    public function test_a_shopper_cannot_attach_someone_elses_prescription(): void
    {
        $owner = $this->makeUser();
        $stranger = $this->makeUser();
        $product = $this->rxProduct();
        $script = $this->scriptFor($owner);

        // 404 rather than 403 — prescription ids are sequential, and a
        // distinguishable response would confirm which ones exist.
        $this->postJson('/api/v1/cart', [
            'product_id' => $product->id,
            'quantity' => 1,
            'prescription_id' => $script->id,
        ], $this->tokenFor($stranger))->assertStatus(404);
    }

    public function test_buy_now_also_refuses_someone_elses_prescription(): void
    {
        $owner = $this->makeUser();
        $stranger = $this->makeUser();
        $product = $this->rxProduct();
        $script = $this->scriptFor($owner, Prescription::STATUS_APPROVED);

        $this->postJson('/api/v1/orders/buy-now', [
            'product_id' => $product->id,
            'quantity' => 1,
            'prescription_id' => $script->id,
            'shipping_address' => $this->address(),
            'billing_address' => $this->address(),
            'payment_method' => 'cash_on_delivery',
        ], $this->tokenFor($stranger))->assertStatus(404);
    }

    public function test_a_rejected_script_cannot_be_attached(): void
    {
        $user = $this->makeUser();
        $product = $this->rxProduct();
        $script = $this->scriptFor($user, Prescription::STATUS_REJECTED);

        $this->postJson('/api/v1/cart', [
            'product_id' => $product->id,
            'quantity' => 1,
            'prescription_id' => $script->id,
        ], $this->tokenFor($user))
            ->assertStatus(422)
            ->assertJsonPath('code', 'prescription_rejected');
    }

    public function test_checkout_completes_while_the_script_is_still_pending(): void
    {
        $user = $this->makeUser();
        $product = $this->rxProduct();
        $script = $this->scriptFor($user);

        $this->postJson('/api/v1/cart', [
            'product_id' => $product->id,
            'quantity' => 1,
            'prescription_id' => $script->id,
        ], $this->tokenFor($user))->assertOk();

        $response = $this->postJson('/api/v1/orders', [
            'shipping_address' => $this->address(),
            'billing_address' => $this->address(),
            'payment_method' => 'cash_on_delivery',
        ], $this->tokenFor($user));

        $response->assertStatus(201);

        $order = Order::find($response->json('data.id'));

        $this->assertTrue((bool) $order->requires_prescription);
        $this->assertSame('pending', $order->prescription_status);
        $this->assertFalse($order->isClearedForDispatch());
    }

    public function test_a_pending_order_cannot_be_dispatched(): void
    {
        $order = Order::factory()->create([
            'status' => Order::STATUS_PENDING,
            'payment_status' => Order::PAYMENT_PAID,
            'requires_prescription' => true,
            'prescription_status' => 'pending',
        ]);

        // Every route that moves an order toward dispatch goes through the
        // model, so the gate lives there rather than in one controller.
        foreach (['shipped', 'out_for_delivery', 'delivered', 'assigned_to_agent'] as $status) {
            try {
                $order->update(['status' => $status]);
                $this->fail("Order was dispatched to {$status} with an unapproved prescription");
            } catch (PrescriptionNotClearedException $e) {
                $this->assertStringContainsString('has not been approved', $e->getMessage());
            }
        }
    }

    public function test_approval_releases_the_order_for_dispatch(): void
    {
        $order = Order::factory()->create([
            'status' => Order::STATUS_PENDING,
            'payment_status' => Order::PAYMENT_PAID,
            'requires_prescription' => true,
            'prescription_status' => 'pending',
        ]);

        $order->update(['prescription_status' => 'approved']);
        $order->update(['status' => Order::STATUS_SHIPPED]);

        $this->assertSame(Order::STATUS_SHIPPED, $order->fresh()->status);
    }

    public function test_rejection_cancels_the_order_and_puts_the_stock_back(): void
    {
        $admin = $this->makeUser(['role' => 'admin']);
        $user = $this->makeUser();
        $product = $this->rxProduct();
        $script = $this->scriptFor($user);

        $this->postJson('/api/v1/orders/buy-now', [
            'product_id' => $product->id,
            'quantity' => 3,
            'prescription_id' => $script->id,
            'shipping_address' => $this->address(),
            'billing_address' => $this->address(),
            'payment_method' => 'paystack',
        ], $this->tokenFor($user))->assertStatus(201);

        $order = Order::where('user_id', $user->id)->latest()->first();
        $order->update(['payment_status' => Order::PAYMENT_PAID]);

        $stockAfterPurchase = $product->fresh()->stock_quantity;

        $response = $this->postJson("/api/v1/prescriptions/{$script->id}/review", [
            'action' => 'reject',
            'reason' => 'Prescriber could not be verified',
        ], $this->tokenFor($admin));

        $response->assertOk()->assertJsonPath('refund_due', true);

        $order->refresh();

        $this->assertSame(Order::STATUS_CANCELLED, $order->status);
        $this->assertNotNull($order->cancelled_at);
        $this->assertStringContainsString('prescription rejected', $order->notes);
        $this->assertSame($stockAfterPurchase + 3, $product->fresh()->stock_quantity);
    }

    public function test_the_confirmation_page_explains_the_hold(): void
    {
        $user = $this->makeUser();
        $product = $this->rxProduct();
        $script = $this->scriptFor($user);

        $created = $this->postJson('/api/v1/orders/buy-now', [
            'product_id' => $product->id,
            'quantity' => 1,
            'prescription_id' => $script->id,
            'shipping_address' => $this->address(),
            'billing_address' => $this->address(),
            'payment_method' => 'cash_on_delivery',
        ], $this->tokenFor($user));

        $orderId = $created->json('data.id');

        $this->getJson("/api/v1/orders/{$orderId}/confirmation", $this->tokenFor($user))
            ->assertOk()
            ->assertJsonPath('data.awaiting_prescription_review', true)
            ->assertJsonPath('data.prescription_status', 'pending')
            ->assertJsonFragment(['requires_prescription' => true]);
    }
}
