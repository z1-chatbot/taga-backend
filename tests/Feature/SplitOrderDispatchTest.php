<?php

namespace Tests\Feature;

use App\Models\AgentEarning;
use App\Models\DeliveryAgent;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\OrderShipment;
use App\Models\Product;
use App\Models\Role;
use App\Models\Store;
use App\Models\User;
use App\Support\ApiToken;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

/**
 * Dispatching an order that ships from more than one pharmacy.
 *
 * Checkout has always split such an order into a shipment per pharmacy — own
 * tracking number, own shipping fee, own delivery estimate. Nothing downstream
 * used the split. The admin console offered one "assign delivery" control for
 * the whole order, which took the lowest shipment id and left the second
 * pharmacy's parcel sitting at 'pending' with nobody coming for it, while the
 * order itself read as handled. One rider confirming their own delivery marked
 * the whole order delivered and settled a cash-on-delivery payment. One code
 * released both parcels. One earning was written per order, so the second rider
 * hit a unique index and was paid nothing.
 *
 * Every test here has a single-parcel counterpart asserting the ordinary case
 * is untouched — that is most orders, and none of this is worth a regression
 * there.
 */
class SplitOrderDispatchTest extends TestCase
{
    private array $address = [
        'firstName' => 'Ada', 'lastName' => 'Obi', 'email' => 'ada@example.test',
        'phone' => '08000000000', 'address' => '1 Test Road',
        'city' => 'Ikeja', 'state' => 'Lagos', 'country' => 'Nigeria',
    ];

    private function admin(): User
    {
        $this->seed(\Database\Seeders\RolesAndPermissionsSeeder::class);

        return $this->makeUser([
            'role' => 'admin',
            'role_id' => Role::where('name', 'admin')->value('id'),
        ]);
    }

    private function order(): Order
    {
        return Order::create([
            'user_id' => $this->makeUser()->id,
            'order_number' => 'TEST-SPLIT-'.uniqid(),
            'status' => Order::STATUS_READY_FOR_PICKUP,
            'payment_status' => Order::PAYMENT_PAID,
            'subtotal' => 12000,
            'shipping_amount' => 2000,
            'total_amount' => 14000,
            'shipping_address' => $this->address,
            'billing_address' => $this->address,
        ]);
    }

    private function pharmacy(string $name, string $state = 'Lagos', string $city = 'Ikeja'): Store
    {
        return Store::create([
            'owner_id' => $this->makeUser(['role' => 'store_owner'])->id,
            'name' => $name,
            'slug' => 'sp-'.uniqid(),
            'email' => 'shop'.uniqid().'@pharmacy.test',
            'state' => $state,
            'city' => $city,
            'status' => 'active',
            'verification_status' => Store::VERIFICATION_APPROVED,
        ]);
    }

    /** A parcel from one pharmacy, with a line of stock on it. */
    private function parcel(Order $order, Store $store, float $amount): OrderShipment
    {
        $product = Product::factory()->create(['store_id' => $store->id]);

        OrderItem::create([
            'order_id' => $order->id,
            'product_id' => $product->id,
            'quantity' => 1,
            'price' => $amount,
            'total' => $amount,
            'product_snapshot' => ['name' => $product->name, 'sku' => 'SP-'.$amount],
        ]);

        return OrderShipment::create([
            'order_id' => $order->id,
            'store_id' => $store->id,
            'tracking_number' => OrderShipment::generateTrackingNumber(),
            'status' => 'pending',
            'shipping_fee' => 1000,
            'items' => [],
            'estimated_delivery_days' => 2,
        ]);
    }

    private function rider(string $name, string $state = 'Lagos', string $city = 'Ikeja'): DeliveryAgent
    {
        return DeliveryAgent::create([
            'name' => $name,
            'email' => 'rider'.uniqid().'@courier.test',
            'phone' => '0801'.random_int(1000000, 9999999),
            'password' => bcrypt('secret123'),
            'status' => 'available',
            'is_active' => true,
            'service_areas' => [['state' => $state, 'cities' => [$city]]],
        ]);
    }

    private function riderHeaders(DeliveryAgent $agent): array
    {
        return [
            'Authorization' => 'Bearer '.ApiToken::issue(ApiToken::TYPE_AGENT, $agent->id, $agent->email),
            'Accept' => 'application/json',
        ];
    }

    /** An order from two pharmacies: [order, parcelA, parcelB]. */
    private function splitOrder(): array
    {
        $order = $this->order();

        return [
            $order,
            $this->parcel($order, $this->pharmacy('Alpha Pharmacy'), 3000),
            $this->parcel($order, $this->pharmacy('Beta Pharmacy'), 9000),
        ];
    }

    // --- Assignment -------------------------------------------------------

    public function test_dispatching_a_split_order_without_naming_a_parcel_is_refused(): void
    {
        [$order] = $this->splitOrder();
        $rider = $this->rider('Musa');

        $response = $this->postJson("/api/v1/admin/orders/{$order->id}/assign-delivery", [
            'delivery_agent_id' => $rider->id,
            'type' => 'agent',
        ], $this->tokenFor($this->admin()));

        // Refused rather than guessed. Silently taking the lowest shipment id
        // is exactly what stranded the other pharmacy.
        $response->assertStatus(422)->assertJsonPath('code', 'shipment_required');
        $this->assertCount(2, $response->json('data.shipments'));
    }

    public function test_a_single_pharmacy_order_still_dispatches_in_one_step(): void
    {
        $order = $this->order();
        $parcel = $this->parcel($order, $this->pharmacy('Solo Pharmacy'), 12000);
        $rider = $this->rider('Musa');

        $this->postJson("/api/v1/admin/orders/{$order->id}/assign-delivery", [
            'delivery_agent_id' => $rider->id,
            'type' => 'agent',
        ], $this->tokenFor($this->admin()))->assertOk();

        $this->assertSame($rider->id, $parcel->fresh()->delivery_agent_id);
        $this->assertSame('assigned_to_agent', $order->fresh()->status);
    }

    public function test_each_parcel_is_assigned_on_its_own(): void
    {
        [$order, $alpha, $beta] = $this->splitOrder();
        $musa = $this->rider('Musa');
        $bola = $this->rider('Bola');
        $headers = $this->tokenFor($this->admin());

        $this->postJson("/api/v1/admin/orders/{$order->id}/assign-delivery", [
            'delivery_agent_id' => $musa->id,
            'type' => 'agent',
            'shipment_id' => $alpha->id,
        ], $headers)->assertOk();

        $this->assertSame($musa->id, $alpha->fresh()->delivery_agent_id);

        // The other pharmacy's parcel is untouched, and — the part that was
        // broken — the order does not yet claim to be assigned.
        $this->assertNull($beta->fresh()->delivery_agent_id);
        $this->assertSame('pending', $beta->fresh()->status);
        $this->assertNotSame('assigned_to_agent', $order->fresh()->status);

        $this->postJson("/api/v1/admin/orders/{$order->id}/assign-delivery", [
            'delivery_agent_id' => $bola->id,
            'type' => 'agent',
            'shipment_id' => $beta->id,
        ], $headers)->assertOk();

        $this->assertSame($bola->id, $beta->fresh()->delivery_agent_id);
        $this->assertSame('assigned_to_agent', $order->fresh()->status);
    }

    public function test_the_courier_list_is_filtered_by_the_parcels_own_pharmacy(): void
    {
        $order = $this->order();
        $lagos = $this->parcel($order, $this->pharmacy('Lagos Pharmacy', 'Lagos', 'Ikeja'), 3000);
        $abuja = $this->parcel($order, $this->pharmacy('Abuja Pharmacy', 'FCT', 'Garki'), 9000);

        $headers = $this->tokenFor($this->admin());

        $lagosLeg = $this->getJson(
            "/api/v1/admin/orders/{$order->id}/available-agents?shipment_id={$lagos->id}",
            $headers
        )->assertOk();

        $abujaLeg = $this->getJson(
            "/api/v1/admin/orders/{$order->id}/available-agents?shipment_id={$abuja->id}",
            $headers
        )->assertOk();

        // Lagos to Lagos is a local run; FCT to Lagos crosses states. The
        // classification decides whether the console offers independent riders
        // or logistics companies, and it was being made for both legs from
        // whichever pharmacy the order happened to name.
        $lagosLeg->assertJsonPath('meta.origin_state', 'Lagos')
                 ->assertJsonPath('meta.delivery_type', 'intrastate');

        $abujaLeg->assertJsonPath('meta.origin_state', 'FCT')
                 ->assertJsonPath('meta.delivery_type', 'interstate');

        // And without naming a parcel it still answers for the order as a
        // whole, which is what a single-pharmacy order wants.
        $this->getJson("/api/v1/admin/orders/{$order->id}/available-agents", $headers)
             ->assertOk()
             ->assertJsonStructure(['meta' => ['delivery_type', 'origin_state']]);
    }

    // --- Delivery codes ---------------------------------------------------

    public function test_each_parcel_gets_its_own_delivery_code(): void
    {
        [$order, $alpha, $beta] = $this->splitOrder();

        $order->ensureDeliveryCode();

        $alphaCode = $alpha->fresh()->delivery_code;
        $betaCode = $beta->fresh()->delivery_code;

        $this->assertMatchesRegularExpression('/^\d{6}$/', $alphaCode);
        $this->assertMatchesRegularExpression('/^\d{6}$/', $betaCode);

        // Two riders arriving on two days. A shared code lets either close out
        // the other's delivery, which is the one thing the code exists to stop.
        $this->assertNotSame($alphaCode, $betaCode);
    }

    public function test_a_single_parcel_keeps_the_orders_own_code(): void
    {
        $order = $this->order();
        $parcel = $this->parcel($order, $this->pharmacy('Solo Pharmacy'), 12000);

        $code = $order->ensureDeliveryCode();

        // Nothing changes for the ordinary order: one code, one email, one
        // rider — and the customer's existing code still opens the parcel.
        $this->assertSame($code, $parcel->fresh()->delivery_code);
    }

    public function test_a_riders_code_does_not_release_the_other_pharmacys_parcel(): void
    {
        Mail::fake();

        [$order, $alpha, $beta] = $this->splitOrder();
        $order->ensureDeliveryCode();

        $musa = $this->rider('Musa');
        $alpha->update(['delivery_agent_id' => $musa->id, 'status' => 'out_for_delivery']);

        $this->putJson("/api/v1/delivery/agent/shipments/{$alpha->id}/status", [
            'status' => 'delivered',
            'delivery_code' => $beta->fresh()->delivery_code,
        ], $this->riderHeaders($musa))->assertStatus(422);

        $this->assertNotSame('delivered', $alpha->fresh()->status);

        $this->putJson("/api/v1/delivery/agent/shipments/{$alpha->id}/status", [
            'status' => 'delivered',
            'delivery_code' => $alpha->fresh()->delivery_code,
        ], $this->riderHeaders($musa))->assertOk();

        $this->assertSame('delivered', $alpha->fresh()->status);
    }

    // --- Order status -----------------------------------------------------

    public function test_one_parcel_delivered_does_not_deliver_the_order(): void
    {
        Mail::fake();

        [$order, $alpha, $beta] = $this->splitOrder();
        $order->update(['payment_status' => Order::PAYMENT_PENDING]);
        $order->ensureDeliveryCode();

        $musa = $this->rider('Musa');
        $alpha->update(['delivery_agent_id' => $musa->id, 'status' => 'out_for_delivery']);

        $this->putJson("/api/v1/delivery/agent/shipments/{$alpha->id}/status", [
            'status' => 'delivered',
            'delivery_code' => $alpha->fresh()->delivery_code,
        ], $this->riderHeaders($musa))->assertOk();

        $order->refresh();

        // The customer has one of two parcels. Marking the order delivered told
        // them it had all arrived and closed the window to report a problem
        // with the parcel still in transit.
        $this->assertNotSame('delivered', $order->status);
        $this->assertSame(Order::PAYMENT_PENDING, $order->payment_status);
        $this->assertSame('delivered', $alpha->fresh()->status);
    }

    public function test_the_order_is_delivered_when_the_last_parcel_lands(): void
    {
        Mail::fake();

        [$order, $alpha, $beta] = $this->splitOrder();
        $order->update(['payment_status' => Order::PAYMENT_PENDING]);
        $order->ensureDeliveryCode();

        foreach ([[$alpha, 'Musa'], [$beta, 'Bola']] as [$parcel, $name]) {
            $rider = $this->rider($name);
            $parcel->update(['delivery_agent_id' => $rider->id, 'status' => 'out_for_delivery']);

            $this->putJson("/api/v1/delivery/agent/shipments/{$parcel->id}/status", [
                'status' => 'delivered',
                'delivery_code' => $parcel->fresh()->delivery_code,
            ], $this->riderHeaders($rider))->assertOk();
        }

        $order->refresh();

        $this->assertSame('delivered', $order->status);

        // Delivering a parcel does not settle an order. It used to, on the
        // cash-on-delivery path: the last rider to arrive marked the whole
        // order paid. Payment now moves only when the gateway says so.
        $this->assertSame(Order::PAYMENT_PENDING, $order->payment_status);
    }

    // --- Earnings ---------------------------------------------------------

    public function test_both_riders_are_paid_for_the_leg_they_carried(): void
    {
        Mail::fake();

        [$order, $alpha, $beta] = $this->splitOrder();
        $order->ensureDeliveryCode();

        $riders = [];

        foreach ([[$alpha, 'Musa'], [$beta, 'Bola']] as [$parcel, $name]) {
            $rider = $this->rider($name);
            $riders[] = $rider;
            $parcel->update(['delivery_agent_id' => $rider->id, 'status' => 'out_for_delivery']);

            $this->putJson("/api/v1/delivery/agent/shipments/{$parcel->id}/status", [
                'status' => 'delivered',
                'delivery_code' => $parcel->fresh()->delivery_code,
            ], $this->riderHeaders($rider))->assertOk();
        }

        // Two journeys, two earnings. Keyed on the order, the first rider to
        // confirm took the credit and the second was silently paid nothing.
        $this->assertSame(2, AgentEarning::where('order_id', $order->id)->count());

        foreach ($riders as $rider) {
            $this->assertDatabaseHas('agent_earnings', [
                'order_id' => $order->id,
                'delivery_agent_id' => $rider->id,
            ]);
        }
    }

    public function test_confirming_the_same_parcel_twice_still_pays_once(): void
    {
        Mail::fake();

        $order = $this->order();
        $parcel = $this->parcel($order, $this->pharmacy('Solo Pharmacy'), 12000);
        $order->ensureDeliveryCode();

        $rider = $this->rider('Musa');
        $parcel->update(['delivery_agent_id' => $rider->id, 'status' => 'out_for_delivery']);

        foreach (range(1, 2) as $ignored) {
            $this->putJson("/api/v1/delivery/agent/shipments/{$parcel->id}/status", [
                'status' => 'delivered',
                'delivery_code' => $parcel->fresh()->delivery_code,
            ], $this->riderHeaders($rider));
        }

        // Splitting the ledger per parcel must not have reopened the double-pay
        // hole the order-level key was closing.
        $this->assertSame(1, AgentEarning::where('order_id', $order->id)->count());
    }

    // --- Privacy ----------------------------------------------------------

    public function test_a_pharmacy_sees_only_its_own_parcel_on_the_order(): void
    {
        $this->seed(\Database\Seeders\RolesAndPermissionsSeeder::class);

        [$order, $alpha, $beta] = $this->splitOrder();

        $owner = User::find($alpha->store->owner_id);
        $owner->update(['role_id' => Role::where('name', 'store_owner')->value('id')]);

        $shipments = $this->getJson("/api/v1/admin/orders/{$order->id}", $this->tokenFor($owner))
            ->assertOk()
            ->json('data.shipments');

        // The other pharmacy's tracking number, rider and delivery window are
        // not this pharmacy's business — the same scoping already applied to
        // the order's line items.
        $this->assertCount(1, $shipments);
        $this->assertSame($alpha->id, $shipments[0]['id']);
    }
}
