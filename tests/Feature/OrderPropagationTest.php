<?php

namespace Tests\Feature;

use App\Exceptions\InvalidStatusTransitionException;
use App\Models\DeliveryAgent;
use App\Models\DeliveryTrackingEvent;
use App\Models\LogisticsCompany;
use App\Models\Order;
use App\Models\OrderShipment;
use App\Models\Product;
use App\Models\Store;
use App\Support\ApiToken;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

/**
 * When an order moves, everything attached to it has to move with it.
 *
 * The order row is only one of the records describing a delivery. The rider's
 * portal and the logistics dashboard read the shipment; the customer's tracking
 * page reads tracking events; stock and rider availability are their own state.
 * Several of those were being left behind.
 */
class OrderPropagationTest extends TestCase
{
    private Store $store;

    private LogisticsCompany $company;

    private DeliveryAgent $agent;

    protected function setUp(): void
    {
        parent::setUp();

        $owner = $this->makeUser(['role' => 'store_owner']);

        $this->store = Store::create([
            'owner_id' => $owner->id,
            'name' => 'Test Pharmacy',
            'slug' => 'prop-'.uniqid(),
            'state' => 'Lagos',
            'city' => 'Ikeja',
            'status' => 'active',
            // Nothing a pharmacy lists is purchasable until its licence is
            // approved, so a fixture that sells has to be a verified shop.
            'verification_status' => \App\Models\Store::VERIFICATION_APPROVED,
        ]);

        $this->company = LogisticsCompany::create([
            'name' => 'Test Logistics',
            'code' => 'PL'.random_int(1000, 9999),
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

    private function adminHeaders(): array
    {
        return $this->tokenFor($this->makeUser(['role' => 'admin']));
    }

    private function agentHeaders(): array
    {
        return ['Authorization' => 'Bearer '.ApiToken::issue(
            ApiToken::TYPE_AGENT, $this->agent->id, $this->agent->email
        )];
    }

    private function liveOrder(array $overrides = []): Order
    {
        return Order::factory()->create(array_merge([
            'status' => Order::STATUS_PROCESSING,
            'payment_status' => Order::PAYMENT_PAID,
            'store_id' => $this->store->id,
        ], $overrides));
    }

    private function shipmentFor(Order $order, string $status = 'assigned_to_agent'): OrderShipment
    {
        return OrderShipment::create([
            'order_id' => $order->id,
            'store_id' => $this->store->id,
            'tracking_number' => 'TRK'.uniqid(),
            'logistics_company_id' => $this->company->id,
            'delivery_agent_id' => $this->agent->id,
            'status' => $status,
            'shipping_fee' => 1500,
        ]);
    }

    // ---- shipment follows the order --------------------------------------

    public function test_the_shipment_follows_when_an_admin_moves_the_order(): void
    {
        $order = $this->liveOrder(['status' => Order::STATUS_ASSIGNED_TO_AGENT]);
        $shipment = $this->shipmentFor($order);

        $this->putJson("/api/v1/admin/orders/{$order->id}/status", [
            'status' => Order::STATUS_SHIPPED,
        ], $this->adminHeaders())->assertOk();

        // 'shipped' is the console's word for the physical event the shipment
        // records as 'picked_up'.
        $this->assertSame('picked_up', $shipment->fresh()->status);
        $this->assertNotNull($shipment->fresh()->picked_up_at);
    }

    public function test_marking_an_order_delivered_delivers_its_shipment(): void
    {
        $order = $this->liveOrder(['status' => Order::STATUS_ASSIGNED_TO_AGENT]);
        $shipment = $this->shipmentFor($order);

        $this->putJson("/api/v1/admin/orders/{$order->id}/status", [
            'status' => Order::STATUS_DELIVERED,
        ], $this->adminHeaders())->assertOk();

        $this->assertSame('delivered', $shipment->fresh()->status);
    }

    public function test_a_delivered_shipment_is_never_dragged_backwards(): void
    {
        $order = $this->liveOrder(['status' => Order::STATUS_ASSIGNED_TO_AGENT]);
        $shipment = $this->shipmentFor($order, 'delivered');

        $this->putJson("/api/v1/admin/orders/{$order->id}/status", [
            'status' => Order::STATUS_SHIPPED,
        ], $this->adminHeaders())->assertOk();

        $this->assertSame('delivered', $shipment->fresh()->status);
    }

    // ---- forward-only progression -----------------------------------------

    public function test_an_order_cannot_move_backwards(): void
    {
        $order = $this->liveOrder(['status' => Order::STATUS_SHIPPED]);

        foreach ([Order::STATUS_PENDING, Order::STATUS_PROCESSING, Order::STATUS_READY_FOR_PICKUP] as $backwards) {
            $this->putJson("/api/v1/admin/orders/{$order->id}/status", [
                'status' => $backwards,
            ], $this->adminHeaders())
                ->assertStatus(422)
                ->assertJsonPath('code', 'invalid_status_transition');
        }

        $this->assertSame(Order::STATUS_SHIPPED, $order->fresh()->status);
    }

    public function test_a_riders_pickup_cannot_drag_a_shipped_order_backwards(): void
    {
        $order = $this->liveOrder(['status' => Order::STATUS_SHIPPED]);
        $shipment = $this->shipmentFor($order, 'assigned_to_agent');

        // 'shipped' and 'picked_up' are the same rank, so this is a no-op
        // rather than a regression — the order must not drop back.
        $this->putJson("/api/v1/delivery/agent/shipments/{$shipment->id}/status", [
            'status' => 'picked_up',
        ], $this->agentHeaders());

        $this->assertContains($order->fresh()->status, [Order::STATUS_SHIPPED, 'picked_up']);
        $this->assertSame(
            Order::STATUS_RANK[Order::STATUS_SHIPPED],
            Order::STATUS_RANK[$order->fresh()->status],
            'the order must not fall behind where it already was'
        );
    }

    public function test_a_delivered_order_cannot_be_cancelled_only_refunded(): void
    {
        $order = $this->liveOrder(['status' => Order::STATUS_DELIVERED]);

        $this->putJson("/api/v1/admin/orders/{$order->id}/status", [
            'status' => Order::STATUS_CANCELLED,
        ], $this->adminHeaders())
            ->assertStatus(422)
            ->assertJsonPath('code', 'invalid_status_transition');

        $this->putJson("/api/v1/admin/orders/{$order->id}/status", [
            'status' => Order::STATUS_REFUNDED,
        ], $this->adminHeaders())->assertOk();

        $this->assertSame(Order::STATUS_REFUNDED, $order->fresh()->status);
    }

    public function test_a_cancelled_order_cannot_be_revived_by_accident(): void
    {
        $order = $this->liveOrder(['status' => Order::STATUS_CANCELLED]);

        $this->putJson("/api/v1/admin/orders/{$order->id}/status", [
            'status' => Order::STATUS_PROCESSING,
        ], $this->adminHeaders())
            ->assertStatus(422)
            ->assertJsonPath('code', 'invalid_status_transition');
    }

    public function test_a_deliberate_correction_is_still_possible(): void
    {
        $order = $this->liveOrder(['status' => Order::STATUS_SHIPPED]);

        $order->allowStatusRegression()->update(['status' => Order::STATUS_PROCESSING]);

        $this->assertSame(Order::STATUS_PROCESSING, $order->fresh()->status);

        // The permission does not survive into the next save.
        $this->expectException(InvalidStatusTransitionException::class);
        $order->update(['status' => Order::STATUS_PENDING]);
    }

    // ---- cancellation releases what it reserved ---------------------------

    public function test_cancelling_restores_stock_and_frees_the_rider(): void
    {
        $product = Product::factory()->create([
            'store_id' => $this->store->id,
            'stock_quantity' => 10,
            'requires_prescription' => false,
        ]);

        $order = $this->liveOrder(['status' => Order::STATUS_ASSIGNED_TO_AGENT]);
        $order->update(['delivery_agent_id' => $this->agent->id]);
        $order->items()->create([
            'product_id' => $product->id,
            'quantity' => 3,
            'price' => 1000,
            'total' => 3000,
        ]);

        $this->agent->update(['status' => 'busy']);

        $this->putJson("/api/v1/admin/orders/{$order->id}/status", [
            'status' => Order::STATUS_CANCELLED,
        ], $this->adminHeaders())->assertOk();

        $this->assertSame(13, $product->fresh()->stock_quantity, 'stock should go back on the shelf');
        $this->assertSame('available', $this->agent->fresh()->status, 'the rider should be released');
    }

    public function test_stock_is_not_restored_twice(): void
    {
        $product = Product::factory()->create([
            'store_id' => $this->store->id,
            'stock_quantity' => 10,
            'requires_prescription' => false,
        ]);

        $order = $this->liveOrder(['status' => Order::STATUS_PROCESSING]);
        $order->items()->create([
            'product_id' => $product->id,
            'quantity' => 2,
            'price' => 1000,
            'total' => 2000,
        ]);

        $this->putJson("/api/v1/admin/orders/{$order->id}/status", [
            'status' => Order::STATUS_CANCELLED,
        ], $this->adminHeaders())->assertOk();

        $this->assertSame(12, $product->fresh()->stock_quantity);

        // Cancelled to refunded is a move between terminal states, not a second
        // cancellation.
        $this->putJson("/api/v1/admin/orders/{$order->id}/status", [
            'status' => Order::STATUS_REFUNDED,
        ], $this->adminHeaders())->assertOk();

        $this->assertSame(12, $product->fresh()->stock_quantity, 'stock must not be credited twice');
    }

    // ---- the parties actually get told ------------------------------------

    public function test_the_rider_relationship_resolves_for_notifications(): void
    {
        $order = $this->liveOrder();
        $order->update(['delivery_agent_id' => $this->agent->id]);

        // OrderNotificationService reached for $order->delivery_agent, which is
        // not a relationship — it silently returned null, so every rider
        // notification was skipped.
        $this->assertNotNull($order->fresh()->deliveryAgent);
        $this->assertSame($this->agent->id, $order->fresh()->deliveryAgent->id);
    }

    public function test_assignment_is_a_status_the_notifier_recognises(): void
    {
        // The notifier switched on 'assigned', a value this application never
        // writes; the real status is 'assigned_to_agent'.
        $this->assertArrayHasKey(Order::STATUS_ASSIGNED_TO_AGENT, Order::STATUS_RANK);

        $source = file_get_contents(app_path('Services/OrderNotificationService.php'));

        $this->assertStringContainsString(
            "case 'assigned_to_agent':",
            $source,
            'the notifier must handle the status the application actually sets'
        );
    }

    public function test_every_status_change_leaves_a_tracking_event(): void
    {
        $order = $this->liveOrder(['status' => Order::STATUS_ASSIGNED_TO_AGENT]);
        $this->shipmentFor($order);

        $before = DeliveryTrackingEvent::where('order_id', $order->id)->count();

        $this->putJson("/api/v1/admin/orders/{$order->id}/status", [
            'status' => Order::STATUS_SHIPPED,
        ], $this->adminHeaders())->assertOk();

        $this->assertGreaterThan(
            $before,
            DeliveryTrackingEvent::where('order_id', $order->id)->count(),
            'the customer tracking page is built from these'
        );
    }
}
