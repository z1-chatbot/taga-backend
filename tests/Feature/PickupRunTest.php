<?php

namespace Tests\Feature;

use App\Models\AgentEarning;
use App\Models\DeliveryAgent;
use App\Models\LogisticsCompany;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\OrderShipment;
use App\Models\Product;
use App\Models\Role;
use App\Models\ShippingRate;
use App\Models\Store;
use App\Mail\DeliveryAssignmentEmail;
use App\Services\DeliveryEarningsService;
use Tests\TestCase;

/**
 * Pharmacies a single rider collects from in one round.
 *
 * Two shops in the same city are one journey — one collection round, one drive
 * to the customer — so the shopper is charged once for them. That price is only
 * honest if the round is genuinely made as one round, which takes three things
 * agreeing:
 *
 *  - the parcels are priced as one journey (DeliveryFeeBreakdownTest);
 *  - assigning either parcel assigns all of them, so two couriers cannot each
 *    take half of a fee that only paid for one;
 *  - the round settles as a single earning, at the whole round's fee.
 *
 * The middle one is the load-bearing one. Charging once while letting an
 * operator hand the two parcels to two couriers pays two agreed rates out of
 * one fee — the exact hole that pricing per journey was meant to close.
 */
class PickupRunTest extends TestCase
{
    private array $address = [
        'firstName' => 'Ada', 'lastName' => 'Obi', 'email' => 'ada@example.test',
        'phone' => '08000000000', 'address' => '1 Test Road',
        'city' => 'Ikeja', 'state' => 'Lagos', 'country' => 'Nigeria',
    ];

    private function order(float $shipping = 1500): Order
    {
        return Order::create([
            'user_id' => $this->makeUser()->id,
            'order_number' => 'TEST-PR-'.uniqid(),
            'status' => Order::STATUS_READY_FOR_PICKUP,
            'payment_status' => Order::PAYMENT_PAID,
            'subtotal' => 10000,
            'shipping_amount' => $shipping,
            'total_amount' => 10000 + $shipping,
            'shipping_address' => $this->address,
            'billing_address' => $this->address,
        ]);
    }

    private function parcel(Order $order, string $state, string $city, float $fee): OrderShipment
    {
        $store = Store::create([
            'owner_id' => $this->makeUser(['role' => 'store_owner'])->id,
            'name' => $city.' Pharmacy '.uniqid(),
            'slug' => 'pr-'.uniqid(),
            'email' => 'shop'.uniqid().'@pharmacy.test',
            'state' => $state,
            'city' => $city,
            'status' => 'active',
            'verification_status' => Store::VERIFICATION_APPROVED,
        ]);

        $product = Product::factory()->create(['store_id' => $store->id]);

        OrderItem::create([
            'order_id' => $order->id,
            'product_id' => $product->id,
            'quantity' => 1,
            'price' => 5000,
            'total' => 5000,
            'product_snapshot' => ['name' => $product->name, 'sku' => 'PR-'.uniqid()],
        ]);

        return OrderShipment::create([
            'order_id' => $order->id,
            'store_id' => $store->id,
            'pickup_group' => OrderShipment::pickupGroupFor($store),
            'tracking_number' => OrderShipment::generateTrackingNumber(),
            'status' => 'pending',
            'shipping_fee' => $fee,
            'items' => [],
            'estimated_delivery_days' => 2,
        ]);
    }

    private function rider(string $state, string $city): DeliveryAgent
    {
        return DeliveryAgent::create([
            'name' => 'Rider '.uniqid(),
            'email' => 'rider'.uniqid().'@courier.test',
            'phone' => '08020000000',
            'password' => bcrypt('secret123'),
            'status' => 'available',
            'is_active' => true,
            'service_areas' => [['state' => $state, 'cities' => [$city]]],
        ]);
    }

    private function company(string $state, string $city): LogisticsCompany
    {
        return LogisticsCompany::create([
            'name' => 'Courier Co '.uniqid(),
            'code' => 'CO'.strtoupper(substr(uniqid(), -6)),
            'admin_email' => 'co'.uniqid().'@courier.test',
            'admin_password' => bcrypt('secret123'),
            'contact_phone' => '08010000000',
            'is_active' => true,
            'service_areas' => [['state' => $state, 'cities' => [$city]]],
        ]);
    }

    private function admin(): array
    {
        $this->seed(\Database\Seeders\RolesAndPermissionsSeeder::class);

        return $this->tokenFor($this->makeUser([
            'role' => 'admin',
            'role_id' => Role::where('name', 'admin')->value('id'),
        ]));
    }

    public function test_parcels_from_one_city_share_a_run_and_two_cities_do_not(): void
    {
        $order = $this->order();

        $ikejaOne = $this->parcel($order, 'Lagos', 'Ikeja', 750);
        $ikejaTwo = $this->parcel($order, 'Lagos', 'Ikeja', 750);
        $epe = $this->parcel($order, 'Lagos', 'Epe', 1500);

        $this->assertSame($ikejaOne->pickup_group, $ikejaTwo->pickup_group);

        // Same state, same shipping zone, same price — and still not the same
        // round, because nobody collects from Ikeja and Epe on one trip.
        $this->assertNotSame($ikejaOne->pickup_group, $epe->pickup_group);
    }

    public function test_a_shop_with_no_city_is_a_run_of_one(): void
    {
        $order = $this->order();
        $parcel = $this->parcel($order, 'Lagos', 'Ikeja', 750);

        $orphan = OrderShipment::create([
            'order_id' => $order->id,
            'store_id' => $parcel->store_id,
            'pickup_group' => null,
            'tracking_number' => OrderShipment::generateTrackingNumber(),
            'status' => 'pending',
            'shipping_fee' => 750,
            'items' => [],
        ]);

        // Never "everything else that is also ungrouped" — that would sweep
        // every addressless pharmacy in the basket into one imaginary round.
        $this->assertSame([$orphan->id], $orphan->run()->pluck('id')->all());
    }

    public function test_assigning_one_parcel_of_a_run_assigns_the_whole_run(): void
    {
        $order = $this->order();

        $first = $this->parcel($order, 'Lagos', 'Ikeja', 750);
        $second = $this->parcel($order, 'Lagos', 'Ikeja', 750);

        $rider = $this->rider('Lagos', 'Ikeja');

        $this->postJson("/api/v1/admin/orders/{$order->id}/assign-delivery", [
            'delivery_agent_id' => $rider->id,
            'type' => 'agent',
            'shipment_id' => $first->id,
        ], $this->admin())->assertOk();

        // Both, or the second pharmacy has nobody coming for it while the
        // customer has already paid for one round that covers both.
        $this->assertSame($rider->id, $first->fresh()->delivery_agent_id);
        $this->assertSame($rider->id, $second->fresh()->delivery_agent_id);
        $this->assertSame('assigned_to_agent', $second->fresh()->status);
    }

    public function test_a_parcel_on_a_different_run_is_left_alone(): void
    {
        $order = $this->order(3000);

        $ikeja = $this->parcel($order, 'Lagos', 'Ikeja', 1500);
        $epe = $this->parcel($order, 'Lagos', 'Epe', 1500);

        $rider = $this->rider('Lagos', 'Ikeja');

        $this->postJson("/api/v1/admin/orders/{$order->id}/assign-delivery", [
            'delivery_agent_id' => $rider->id,
            'type' => 'agent',
            'shipment_id' => $ikeja->id,
        ], $this->admin())->assertOk();

        // Epe is a separate journey the customer paid separately for, and it
        // gets its own courier.
        $this->assertNull($epe->fresh()->delivery_agent_id);
        $this->assertSame('pending', $epe->fresh()->status);
    }

    public function test_a_run_is_paid_once_at_the_whole_run_s_fee(): void
    {
        $order = $this->order();

        $first = $this->parcel($order, 'Lagos', 'Ikeja', 750);
        $second = $this->parcel($order, 'Lagos', 'Ikeja', 750);

        $rider = $this->rider('Lagos', 'Ikeja');

        foreach ([$first, $second] as $parcel) {
            $parcel->update(['delivery_agent_id' => $rider->id, 'status' => 'delivered']);
        }

        $earnings = app(DeliveryEarningsService::class);

        $earnings->creditForDelivery($order->fresh(), $first->fresh());
        $earnings->creditForDelivery($order->fresh(), $second->fresh());

        // One journey, one payment — for the whole ₦1,500 the customer paid,
        // not one parcel's ₦750 share of it.
        $this->assertSame(1, AgentEarning::where('order_id', $order->id)->count());
        $this->assertEquals(
            1500.0,
            (float) AgentEarning::where('order_id', $order->id)->value('delivery_fee')
        );
    }

    public function test_an_agreed_rate_is_paid_once_for_the_round_not_once_per_shop(): void
    {
        $order = $this->order();

        $first = $this->parcel($order, 'Lagos', 'Ikeja', 750);
        $second = $this->parcel($order, 'Lagos', 'Ikeja', 750);

        $rider = $this->rider('Lagos', 'Ikeja');

        // A rate on file is what the courier is owed regardless of the fee, so
        // this is where paying per parcel used to double the payout — ₦3,000
        // out of a ₦1,500 fee.
        ShippingRate::create([
            'from_state' => 'Lagos',
            'to_state' => 'Lagos',
            'delivery_agent_id' => $rider->id,
            'base_rate' => 1500,
            'is_active' => true,
        ]);

        foreach ([$first, $second] as $parcel) {
            $parcel->update(['delivery_agent_id' => $rider->id, 'status' => 'delivered']);
        }

        $earnings = app(DeliveryEarningsService::class);
        $earnings->creditForDelivery($order->fresh(), $first->fresh());
        $earnings->creditForDelivery($order->fresh(), $second->fresh());

        $this->assertEquals(
            1500.0,
            (float) AgentEarning::where('order_id', $order->id)->sum('agent_commission')
        );
    }

    public function test_two_separate_runs_are_still_paid_separately(): void
    {
        $order = $this->order(3000);

        $ikeja = $this->parcel($order, 'Lagos', 'Ikeja', 1500);
        $epe = $this->parcel($order, 'Lagos', 'Epe', 1500);

        $rider = $this->rider('Lagos', 'Ikeja');

        foreach ([$ikeja, $epe] as $parcel) {
            $parcel->update(['delivery_agent_id' => $rider->id, 'status' => 'delivered']);
        }

        $earnings = app(DeliveryEarningsService::class);
        $earnings->creditForDelivery($order->fresh(), $ikeja->fresh());
        $earnings->creditForDelivery($order->fresh(), $epe->fresh());

        // Two journeys the customer paid for twice, so two payments. Collapsing
        // these would underpay a courier who really did make two trips.
        $this->assertSame(2, AgentEarning::where('order_id', $order->id)->count());
    }

    public function test_a_single_pharmacy_order_is_unaffected(): void
    {
        $order = $this->order();
        $only = $this->parcel($order, 'Lagos', 'Ikeja', 1500);

        $rider = $this->rider('Lagos', 'Ikeja');

        $this->postJson("/api/v1/admin/orders/{$order->id}/assign-delivery", [
            'delivery_agent_id' => $rider->id,
            'type' => 'agent',
        ], $this->admin())->assertOk();

        // No shipment_id needed, because there is only one run to choose from.
        $this->assertSame($rider->id, $only->fresh()->delivery_agent_id);
    }

    public function test_a_run_needs_naming_only_when_there_is_more_than_one(): void
    {
        $order = $this->order();

        $this->parcel($order, 'Lagos', 'Ikeja', 750);
        $this->parcel($order, 'Lagos', 'Ikeja', 750);

        $rider = $this->rider('Lagos', 'Ikeja');

        // Two parcels but one round, so there is nothing for an operator to
        // choose between and no reason to make them pick.
        $this->postJson("/api/v1/admin/orders/{$order->id}/assign-delivery", [
            'delivery_agent_id' => $rider->id,
            'type' => 'agent',
        ], $this->admin())->assertOk();

        $this->assertSame(2, $order->shipments()->where('delivery_agent_id', $rider->id)->count());
    }

    public function test_a_logistics_company_takes_a_whole_run_too(): void
    {
        $order = $this->order();

        $first = $this->parcel($order, 'Lagos', 'Ikeja', 750);
        $second = $this->parcel($order, 'Lagos', 'Ikeja', 750);

        // A company delivers within a state as well as across them, through its
        // own in-city dispatch riders. None of the run behaviour is specific to
        // independent riders, and this is the test that keeps it that way.
        $company = $this->company('Lagos', 'Ikeja');

        $this->postJson("/api/v1/admin/orders/{$order->id}/assign-delivery", [
            'delivery_agent_id' => $company->id,
            'type' => 'company',
            'shipment_id' => $first->id,
        ], $this->admin())->assertOk();

        $this->assertSame($company->id, $first->fresh()->logistics_company_id);
        $this->assertSame($company->id, $second->fresh()->logistics_company_id);
        $this->assertSame('assigned_to_agent', $second->fresh()->status);
    }

    public function test_a_company_is_paid_once_for_the_round(): void
    {
        $order = $this->order();

        $first = $this->parcel($order, 'Lagos', 'Ikeja', 750);
        $second = $this->parcel($order, 'Lagos', 'Ikeja', 750);

        $company = $this->company('Lagos', 'Ikeja');

        // An agreed rate with a company is per journey, exactly as it is for a
        // rider — so paying per parcel would hand over 3,000 for a 1,500 fee.
        ShippingRate::create([
            'from_state' => 'Lagos',
            'to_state' => 'Lagos',
            'logistics_company_id' => $company->id,
            'base_rate' => 1500,
            'is_active' => true,
        ]);

        foreach ([$first, $second] as $parcel) {
            $parcel->update(['logistics_company_id' => $company->id, 'status' => 'delivered']);
        }

        $earnings = app(DeliveryEarningsService::class);
        $earnings->creditForDelivery($order->fresh(), $first->fresh());
        $earnings->creditForDelivery($order->fresh(), $second->fresh());

        $this->assertSame(1, AgentEarning::where('order_id', $order->id)->count());
        $this->assertEquals(
            1500.0,
            (float) AgentEarning::where('order_id', $order->id)->sum('agent_commission')
        );

        // And it lands on the company's balance, not a rider's.
        $this->assertEquals(1500.0, (float) $company->fresh()->available_balance);
    }

    public function test_a_company_is_offered_for_an_intrastate_leg(): void
    {
        $order = $this->order();
        $parcel = $this->parcel($order, 'Lagos', 'Ikeja', 1500);

        $company = $this->company('Lagos', 'Ikeja');

        $options = $this->getJson(
            "/api/v1/admin/orders/{$order->id}/available-agents?shipment_id={$parcel->id}",
            $this->admin()
        )->assertOk()->json('data');

        // Not interstate-only. A company running in-city deliveries has to
        // appear in the console for an in-city parcel or it can never be given
        // one.
        $this->assertContains(
            $company->id,
            collect($options)->where('type', 'company')->pluck('id')->all()
        );
    }

    public function test_the_assignment_email_lists_every_pharmacy_on_the_run(): void
    {
        $order = $this->order();

        $first = $this->parcel($order, 'Lagos', 'Ikeja', 750);
        $second = $this->parcel($order, 'Lagos', 'Ikeja', 750);

        $rider = $this->rider('Lagos', 'Ikeja');

        $first->update(['delivery_agent_id' => $rider->id]);
        $second->update(['delivery_agent_id' => $rider->id]);

        $rendered = (new DeliveryAssignmentEmail(
            $order->fresh(),
            'agent',
            $rider->name,
            $first->tracking_number,
            $first->fresh()
        ))->render();

        /*
         * Both shops, or the rider collects from one and never learns about the
         * other — which they have already been paid to collect, because the
         * round is charged and settled as one journey.
         */
        $this->assertStringContainsString($first->store->name, $rendered);
        $this->assertStringContainsString($second->store->name, $rendered);
    }

    public function test_the_assignment_email_still_hides_another_couriers_parcel(): void
    {
        $order = $this->order(3000);

        $mine = $this->parcel($order, 'Lagos', 'Ikeja', 1500);
        $theirs = $this->parcel($order, 'Lagos', 'Epe', 1500);

        $rider = $this->rider('Lagos', 'Ikeja');
        $mine->update(['delivery_agent_id' => $rider->id]);

        $rendered = (new DeliveryAssignmentEmail(
            $order->fresh(),
            'agent',
            $rider->name,
            $mine->tracking_number,
            $mine->fresh()
        ))->render();

        // A different round is a different courier's stock. Widening the
        // manifest to the run must not widen it to the whole order.
        $this->assertStringContainsString($mine->store->name, $rendered);
        $this->assertStringNotContainsString($theirs->store->name, $rendered);
    }

    public function test_a_single_pharmacy_assignment_email_is_unchanged(): void
    {
        $order = $this->order();
        $only = $this->parcel($order, 'Lagos', 'Ikeja', 1500);

        $rider = $this->rider('Lagos', 'Ikeja');
        $only->update(['delivery_agent_id' => $rider->id]);

        $rendered = (new DeliveryAssignmentEmail(
            $order->fresh(),
            'agent',
            $rider->name,
            $only->tracking_number,
            $only->fresh()
        ))->render();

        $this->assertStringContainsString($only->store->name, $rendered);

        // No "another courier is carrying the rest" on an order nobody else is
        // touching, and no multi-pickup preamble for one shop.
        $this->assertStringNotContainsString('another courier', $rendered);
        $this->assertStringNotContainsString('pharmacies to collect from', $rendered);
    }

    public function test_two_runs_still_have_to_be_named(): void
    {
        $order = $this->order(3000);

        $this->parcel($order, 'Lagos', 'Ikeja', 1500);
        $this->parcel($order, 'Lagos', 'Epe', 1500);

        $rider = $this->rider('Lagos', 'Ikeja');

        // Silently picking one is how the second pharmacy's parcel ended up
        // with nobody coming for it.
        $this->postJson("/api/v1/admin/orders/{$order->id}/assign-delivery", [
            'delivery_agent_id' => $rider->id,
            'type' => 'agent',
        ], $this->admin())->assertStatus(422)->assertJsonPath('code', 'shipment_required');
    }
}
