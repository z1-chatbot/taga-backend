<?php

namespace Tests\Feature;

use App\Exceptions\PrescriptionNotClearedException;
use App\Models\DeliveryAgent;
use App\Models\LogisticsCompany;
use App\Models\Order;
use App\Models\OrderShipment;
use App\Models\Prescription;
use App\Models\Product;
use App\Models\Store;
use App\Models\User;
use App\Support\ApiToken;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

/**
 * The order lifecycle from checkout to the customer's door, across every actor
 * that can move it: admin, store owner, delivery agent, logistics company, and
 * the token-link delivery portal.
 *
 * The thing under test is the dispatch gate. An order carrying prescription
 * medicine can be paid for while a pharmacist is still reviewing the script, so
 * the gate is the only thing standing between an unapproved script and medicine
 * on a doorstep. It lives in Order::booted() rather than in a controller
 * precisely because there are this many ways to advance an order — each test
 * below drives a different one.
 */
class OrderLifecycleTest extends TestCase
{
    private Store $store;

    private LogisticsCompany $company;

    private DeliveryAgent $agent;

    protected function setUp(): void
    {
        parent::setUp();

        // Checkout now refuses a delivery to a state no zone covers.
        $this->serviceableZone('Lagos', 'Lagos');

        $owner = $this->makeUser(['role' => 'store_owner']);

        $this->store = Store::create([
            'owner_id' => $owner->id,
            'name' => 'Test Pharmacy',
            'slug' => 'test-pharmacy-'.uniqid(),
            'state' => 'Lagos',
            'city' => 'Ikeja',
            'status' => 'active',
            // Nothing a pharmacy lists is purchasable until its licence is
            // approved, so a fixture that sells has to be a verified shop.
            'verification_status' => \App\Models\Store::VERIFICATION_APPROVED,
        ]);

        $this->company = LogisticsCompany::create([
            'name' => 'Test Logistics',
            'code' => 'TL'.random_int(1000, 9999),
            'admin_email' => 'ops'.uniqid().'@logistics.test',
            'admin_password' => Hash::make('Passw0rd!23'),
            'service_areas' => [['state' => 'Lagos', 'cities' => ['Ikeja']]],
            'is_active' => true,
        ]);

        $this->agent = DeliveryAgent::create([
            'logistics_company_id' => $this->company->id,
            'name' => 'Test Rider',
            'email' => 'rider'.uniqid().'@agents.test',
            'password' => Hash::make('Passw0rd!23'),
            'phone' => '08000000000',
            'service_areas' => [['state' => 'Lagos', 'cities' => ['Ikeja']]],
            'status' => 'available',
            'is_verified' => true,
        ]);
    }

    // ---- fixtures ---------------------------------------------------------

    private function heldOrder(array $overrides = []): Order
    {
        return Order::factory()->create(array_merge([
            'status' => Order::STATUS_PENDING,
            'payment_status' => Order::PAYMENT_PAID,
            'requires_prescription' => true,
            'prescription_status' => 'pending',
            'store_id' => $this->store->id,
        ], $overrides));
    }

    private function shipmentFor(Order $order): OrderShipment
    {
        return OrderShipment::create([
            'order_id' => $order->id,
            'store_id' => $this->store->id,
            'tracking_number' => 'TRK'.uniqid(),
            'logistics_company_id' => $this->company->id,
            'delivery_agent_id' => $this->agent->id,
            'status' => 'assigned_to_agent',
            'shipping_fee' => 1500,
        ]);
    }

    private function agentHeaders(): array
    {
        return ['Authorization' => 'Bearer '.ApiToken::issue(
            ApiToken::TYPE_AGENT,
            $this->agent->id,
            $this->agent->email
        )];
    }

    private function companyHeaders(): array
    {
        return ['Authorization' => 'Bearer '.ApiToken::issue(
            ApiToken::TYPE_COMPANY,
            $this->company->id,
            $this->company->admin_email
        )];
    }

    private function adminHeaders(): array
    {
        return $this->tokenFor($this->makeUser(['role' => 'admin']));
    }

    // ---- the gate, path by path ------------------------------------------

    public function test_admin_cannot_advance_a_held_order(): void
    {
        $order = $this->heldOrder();
        $admin = $this->adminHeaders();

        foreach (['ready_for_pickup', 'shipped', 'delivered'] as $status) {
            $this->putJson("/api/v1/admin/orders/{$order->id}/status", ['status' => $status], $admin)
                ->assertStatus(422)
                ->assertJsonPath('code', 'prescription_not_cleared');
        }

        $this->assertSame(Order::STATUS_PENDING, $order->fresh()->status);
    }

    public function test_admin_cannot_assign_a_rider_to_a_held_order(): void
    {
        $order = $this->heldOrder();

        $this->postJson("/api/v1/admin/orders/{$order->id}/assign-delivery", [
            'delivery_agent_id' => $this->agent->id,
            'type' => 'agent',
        ], $this->adminHeaders())->assertStatus(422);

        $order->refresh();

        $this->assertSame(Order::STATUS_PENDING, $order->status);
        $this->assertNull($order->delivery_agent_id, 'the rider must not be attached');
        $this->assertSame(0, OrderShipment::where('order_id', $order->id)->count(),
            'no shipment should exist for an order that was never released');
    }

    public function test_a_rider_cannot_advance_a_held_order(): void
    {
        $order = $this->heldOrder();
        $shipment = $this->shipmentFor($order);

        foreach (['picked_up', 'out_for_delivery'] as $status) {
            $this->putJson("/api/v1/delivery/agent/shipments/{$shipment->id}/status", [
                'status' => $status,
            ], $this->agentHeaders())->assertStatus(422);
        }

        $this->assertSame(Order::STATUS_PENDING, $order->fresh()->status);
    }

    public function test_a_rider_cannot_mark_a_held_order_delivered_even_with_the_right_code(): void
    {
        $order = $this->heldOrder();
        $code = $order->generateDeliveryCode();
        $shipment = $this->shipmentFor($order);

        $this->putJson("/api/v1/delivery/agent/shipments/{$shipment->id}/status", [
            'status' => 'delivered',
            'delivery_code' => $code,
        ], $this->agentHeaders())->assertStatus(422);

        $this->assertNotSame('delivered', $order->fresh()->status);
    }

    public function test_a_logistics_company_cannot_advance_a_held_order(): void
    {
        $order = $this->heldOrder();
        $shipment = $this->shipmentFor($order);

        foreach (['picked_up', 'in_transit', 'out_for_delivery'] as $status) {
            $this->postJson("/api/v1/delivery/logistics/orders/{$shipment->id}/status", [
                'status' => $status,
            ], $this->companyHeaders())->assertStatus(422);
        }

        $this->assertSame(Order::STATUS_PENDING, $order->fresh()->status);
    }

    public function test_the_token_link_portal_cannot_advance_a_held_order(): void
    {
        $order = $this->heldOrder();
        $order->update(['delivery_access_token' => $token = str_repeat('a', 64)]);

        foreach (['picked_up', 'out_for_delivery', 'delivered'] as $status) {
            $this->postJson("/api/v1/delivery/order/{$order->id}/update-status", [
                'token' => $token,
                'status' => $status,
            ])->assertStatus(422);
        }

        $this->assertSame(Order::STATUS_PENDING, $order->fresh()->status);
    }

    /**
     * A refused move must leave nothing half-applied.
     *
     * The shipment used to be advanced before the order, so a blocked dispatch
     * left the rider's app showing a collected parcel against an order still
     * marked pending.
     */
    public function test_a_blocked_dispatch_leaves_the_shipment_where_it_was(): void
    {
        $order = $this->heldOrder();
        $shipment = $this->shipmentFor($order);

        $this->putJson("/api/v1/delivery/agent/shipments/{$shipment->id}/status", [
            'status' => 'picked_up',
        ], $this->agentHeaders())->assertStatus(422);

        $this->assertSame('assigned_to_agent', $shipment->fresh()->status);
        $this->assertSame(Order::STATUS_PENDING, $order->fresh()->status);
    }

    public function test_a_blocked_logistics_move_leaves_the_shipment_where_it_was(): void
    {
        $order = $this->heldOrder();
        $shipment = $this->shipmentFor($order);

        $this->postJson("/api/v1/delivery/logistics/orders/{$shipment->id}/status", [
            'status' => 'in_transit',
        ], $this->companyHeaders())->assertStatus(422);

        $this->assertSame('assigned_to_agent', $shipment->fresh()->status);
        $this->assertSame(Order::STATUS_PENDING, $order->fresh()->status);
    }

    /**
     * Checkout already creates a shipment per store, so assignment must claim
     * that one rather than adding a second. Two shipments meant the rider's
     * portal and the tracking page were reading different rows, and the
     * shipping fee was recorded twice.
     */
    public function test_assigning_a_rider_does_not_create_a_second_shipment(): void
    {
        $customer = $this->makeUser();
        $product = Product::factory()->create([
            'store_id' => $this->store->id,
            'stock_quantity' => 20,
            'requires_prescription' => false,
        ]);

        $created = $this->postJson('/api/v1/orders/buy-now', [
            'product_id' => $product->id,
            'quantity' => 1,
            'shipping_address' => $this->shippingAddress(),
            'billing_address' => $this->shippingAddress(),
            'payment_method' => 'paystack',
        ], $this->tokenFor($customer));

        $created->assertStatus(201);

        $order = Order::find($created->json('data.id'));
        $order->update(['payment_status' => Order::PAYMENT_PAID, 'store_id' => $this->store->id]);

        $before = OrderShipment::where('order_id', $order->id)->count();

        $this->postJson("/api/v1/admin/orders/{$order->id}/assign-delivery", [
            'delivery_agent_id' => $this->agent->id,
            'type' => 'agent',
        ], $this->adminHeaders())->assertOk();

        $shipments = OrderShipment::where('order_id', $order->id)->get();

        $this->assertCount(max($before, 1), $shipments, 'assignment should reuse the checkout shipment');
        $this->assertSame($this->agent->id, $shipments->first()->delivery_agent_id);
        $this->assertSame('assigned_to_agent', $shipments->first()->status);
    }

    public function test_a_rejected_script_holds_the_order_just_as_firmly(): void
    {
        $order = $this->heldOrder(['prescription_status' => 'rejected']);

        $this->putJson("/api/v1/admin/orders/{$order->id}/status", [
            'status' => 'shipped',
        ], $this->adminHeaders())->assertStatus(422);

        $this->assertSame(Order::STATUS_PENDING, $order->fresh()->status);
    }

    public function test_the_gate_never_touches_an_order_without_prescription_items(): void
    {
        $order = Order::factory()->create([
            'status' => Order::STATUS_PENDING,
            'payment_status' => Order::PAYMENT_PAID,
            'requires_prescription' => false,
            'prescription_status' => 'not_required',
            'store_id' => $this->store->id,
        ]);

        $this->putJson("/api/v1/admin/orders/{$order->id}/status", [
            'status' => 'shipped',
        ], $this->adminHeaders())->assertOk();

        $this->assertSame(Order::STATUS_SHIPPED, $order->fresh()->status);
    }

    /**
     * Bank transfers and cash still have to be recordable.
     *
     * adminConfirmPayment writes gateway = 'manual', which the column's enum
     * did not allow, so every manual confirmation died on "Data truncated for
     * column 'gateway'" and no such order could be marked paid.
     */
    public function test_an_admin_can_confirm_a_payment_taken_outside_the_gateway(): void
    {
        $order = Order::factory()->create([
            'status' => Order::STATUS_PENDING,
            'payment_status' => Order::PAYMENT_PENDING,
            'store_id' => $this->store->id,
        ]);

        $this->postJson("/api/v1/admin/orders/{$order->id}/confirm-payment", [
            'payment_method' => 'bank_transfer',
            'transaction_id' => 'TRF-12345',
            'notes' => 'Transfer seen on the statement',
        ], $this->adminHeaders())->assertOk();

        $order->refresh();

        $this->assertSame(Order::PAYMENT_PAID, $order->payment_status);
        $this->assertDatabaseHas('payment_transactions', [
            'order_id' => $order->id,
            'gateway' => 'manual',
            'status' => 'success',
        ]);
    }

    /**
     * Confirming payment must not quietly release a held order — it sets the
     * order to 'processing', which is the store preparing it, not dispatch.
     */
    public function test_confirming_payment_does_not_release_a_held_order(): void
    {
        $order = $this->heldOrder(['payment_status' => Order::PAYMENT_PENDING]);

        $this->postJson("/api/v1/admin/orders/{$order->id}/confirm-payment", [
            'payment_method' => 'bank_transfer',
        ], $this->adminHeaders())->assertOk();

        $order->refresh();

        $this->assertSame(Order::PAYMENT_PAID, $order->payment_status);
        $this->assertNotContains($order->status, Order::DISPATCH_STATUSES);
        $this->assertFalse($order->isClearedForDispatch());
    }

    // ---- the full journey -------------------------------------------------

    public function test_an_approved_order_runs_the_whole_way_to_delivered(): void
    {
        $customer = $this->makeUser();
        $product = Product::factory()->prescriptionOnly()->create([
            'store_id' => $this->store->id,
            'stock_quantity' => 20,
        ]);
        $script = Prescription::factory()->create([
            'user_id' => $customer->id,
            'status' => Prescription::STATUS_PENDING,
        ]);

        // 1. Buy while the script is still being reviewed.
        $created = $this->postJson('/api/v1/orders/buy-now', [
            'product_id' => $product->id,
            'quantity' => 1,
            'prescription_id' => $script->id,
            'shipping_address' => $this->shippingAddress(),
            'billing_address' => $this->shippingAddress(),
            'payment_method' => 'paystack',
        ], $this->tokenFor($customer));

        $created->assertStatus(201);

        $order = Order::find($created->json('data.id'));
        $order->update(['payment_status' => Order::PAYMENT_PAID, 'store_id' => $this->store->id]);

        $this->assertSame('pending', $order->fresh()->prescription_status);
        $this->assertFalse($order->fresh()->isClearedForDispatch());

        // 2. Held: nothing moves.
        $this->putJson("/api/v1/admin/orders/{$order->id}/status", ['status' => 'shipped'], $this->adminHeaders())
            ->assertStatus(422);

        // 3. The pharmacist approves.
        $this->postJson("/api/v1/prescriptions/{$script->id}/review", [
            'action' => 'approve',
        ], $this->adminHeaders())->assertOk();

        $order->refresh();

        $this->assertSame('approved', $order->prescription_status);
        $this->assertTrue($order->isClearedForDispatch());

        // 4. Store prepares, admin assigns a rider.
        $this->putJson("/api/v1/admin/orders/{$order->id}/status", ['status' => 'processing'], $this->adminHeaders())
            ->assertOk();

        $this->postJson("/api/v1/admin/orders/{$order->id}/assign-delivery", [
            'delivery_agent_id' => $this->agent->id,
            'type' => 'agent',
        ], $this->adminHeaders())->assertOk();

        $order->refresh();

        $this->assertSame('assigned_to_agent', $order->status);
        $this->assertSame($this->agent->id, $order->delivery_agent_id);

        $shipment = OrderShipment::where('order_id', $order->id)->firstOrFail();

        // 5. The rider collects and delivers, confirming with the customer's code.
        $code = $order->delivery_code ?: $order->generateDeliveryCode();

        $this->putJson("/api/v1/delivery/agent/shipments/{$shipment->id}/status", [
            'status' => 'picked_up',
        ], $this->agentHeaders())->assertOk();

        $this->assertSame('picked_up', $order->fresh()->status);

        $this->putJson("/api/v1/delivery/agent/shipments/{$shipment->id}/status", [
            'status' => 'out_for_delivery',
        ], $this->agentHeaders())->assertOk();

        $this->assertSame('out_for_delivery', $order->fresh()->status);

        $this->putJson("/api/v1/delivery/agent/shipments/{$shipment->id}/status", [
            'status' => 'delivered',
            'delivery_code' => $code,
        ], $this->agentHeaders())->assertOk();

        $order->refresh();

        $this->assertSame(Order::STATUS_DELIVERED, $order->status);
        $this->assertNotNull($order->delivered_at);
    }

    public function test_the_wrong_delivery_code_does_not_complete_the_delivery(): void
    {
        $order = $this->heldOrder([
            'prescription_status' => 'approved',
            'status' => 'out_for_delivery',
        ]);
        $order->generateDeliveryCode();
        $shipment = $this->shipmentFor($order);

        $this->putJson("/api/v1/delivery/agent/shipments/{$shipment->id}/status", [
            'status' => 'delivered',
            'delivery_code' => '000000',
        ], $this->agentHeaders())->assertStatus(422);

        $this->assertNotSame(Order::STATUS_DELIVERED, $order->fresh()->status);
    }

    public function test_a_rider_cannot_touch_a_shipment_that_is_not_theirs(): void
    {
        $order = $this->heldOrder(['prescription_status' => 'approved']);

        $otherAgent = DeliveryAgent::create([
            'logistics_company_id' => null,
            'name' => 'Someone Else',
            'email' => 'other'.uniqid().'@agents.test',
            'password' => Hash::make('Passw0rd!23'),
            'phone' => '08000000001',
            'status' => 'available',
            'is_verified' => true,
        ]);

        $shipment = $this->shipmentFor($order);
        $shipment->update(['delivery_agent_id' => $otherAgent->id]);

        $this->putJson("/api/v1/delivery/agent/shipments/{$shipment->id}/status", [
            'status' => 'picked_up',
        ], $this->agentHeaders())->assertStatus(404);
    }

    private function shippingAddress(): array
    {
        return [
            'firstName' => 'Ada', 'lastName' => 'Obi', 'email' => 'ada@example.test',
            'address' => '1 Test Street', 'city' => 'Ikeja', 'state' => 'Lagos',
            'country' => 'Nigeria', 'phone' => '08012345678',
        ];
    }
}
