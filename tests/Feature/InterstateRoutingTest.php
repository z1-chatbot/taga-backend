<?php

namespace Tests\Feature;

use App\Models\DeliveryAgent;
use App\Models\LogisticsCompany;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\OrderShipment;
use App\Models\Product;
use App\Models\Role;
use App\Models\Store;
use App\Models\User;
use Tests\TestCase;

/**
 * Who is allowed to carry a parcel, and how far.
 *
 * An independent rider works inside one state. Crossing state lines is a relay
 * — collected at the origin, run to a hub, handed to a second rider for the
 * final mile — and only a logistics company has people at both ends to do it.
 *
 * None of that was enforced. Independent riders were offered for every leg and
 * filtered by the delivery address alone, so a rider covering Lagos was listed,
 * and accepted, for a parcel sitting in a pharmacy in Enugu: no way to collect
 * it, and no hub to hand it on at.
 *
 * On a split order the question is per parcel, not per order — one leg can be
 * a local run and another interstate, and each is judged on its own.
 */
class InterstateRoutingTest extends TestCase
{
    private array $address = [
        'firstName' => 'Ada', 'lastName' => 'Obi', 'email' => 'ada@example.test',
        'phone' => '08000000000', 'address' => '1 Test Road',
        'city' => 'Ikeja', 'state' => 'Lagos', 'country' => 'Nigeria',
    ];

    private function admin(): array
    {
        $this->seed(\Database\Seeders\RolesAndPermissionsSeeder::class);

        return $this->tokenFor($this->makeUser([
            'role' => 'admin',
            'role_id' => Role::where('name', 'admin')->value('id'),
        ]));
    }

    private function order(): Order
    {
        return Order::create([
            'user_id' => $this->makeUser()->id,
            'order_number' => 'TEST-IS-'.uniqid(),
            'status' => Order::STATUS_READY_FOR_PICKUP,
            'payment_status' => Order::PAYMENT_PAID,
            'subtotal' => 5000,
            'shipping_amount' => 2000,
            'total_amount' => 7000,
            'shipping_address' => $this->address,
            'billing_address' => $this->address,
        ]);
    }

    private function parcelFrom(Order $order, string $state, string $city): OrderShipment
    {
        $store = Store::create([
            'owner_id' => $this->makeUser(['role' => 'store_owner'])->id,
            'name' => $state.' Pharmacy',
            'slug' => 'is-'.uniqid(),
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
            'price' => 2500,
            'total' => 2500,
            'product_snapshot' => ['name' => $product->name, 'sku' => 'IS-'.uniqid()],
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

    /** @param  array<int, array{0: string, 1: string}>  $areas  [state, city] pairs */
    private function rider(array $areas, ?LogisticsCompany $company = null): DeliveryAgent
    {
        return DeliveryAgent::create([
            'name' => 'Rider '.uniqid(),
            'email' => 'rider'.uniqid().'@courier.test',
            'phone' => '0801'.random_int(1000000, 9999999),
            'password' => bcrypt('secret123'),
            'status' => 'available',
            'is_active' => true,
            'logistics_company_id' => $company?->id,
            'service_areas' => array_map(
                fn ($area) => ['state' => $area[0], 'cities' => [$area[1]]],
                $areas
            ),
        ]);
    }

    private function company(array $areas): LogisticsCompany
    {
        return LogisticsCompany::create([
            'name' => 'Courier Co '.uniqid(),
            'code' => 'CO'.strtoupper(substr(uniqid(), -6)),
            'admin_email' => 'co'.uniqid().'@courier.test',
            'admin_password' => bcrypt('secret123'),
            'contact_phone' => '08010000000',
            'is_active' => true,
            'service_areas' => array_map(
                fn ($area) => ['state' => $area[0], 'cities' => [$area[1]]],
                $areas
            ),
        ]);
    }

    // --- the courier list -------------------------------------------------

    public function test_an_interstate_leg_is_offered_companies_only(): void
    {
        $order = $this->order();
        $parcel = $this->parcelFrom($order, 'Enugu', 'Enugu');

        $this->company([['Enugu', 'Enugu'], ['Lagos', 'Ikeja']]);
        $this->rider([['Lagos', 'Ikeja']]);

        $options = $this->getJson(
            "/api/v1/admin/orders/{$order->id}/available-agents?shipment_id={$parcel->id}",
            $this->admin()
        )->assertOk()->assertJsonPath('meta.delivery_type', 'interstate')->json('data');

        $types = array_unique(array_column($options, 'type'));

        // The lone rider covers where it is going but has no way to collect it
        // from Enugu, and no hub to hand it on at.
        $this->assertSame(['company'], $types);
    }

    public function test_a_local_leg_is_offered_independent_riders(): void
    {
        $order = $this->order();
        $parcel = $this->parcelFrom($order, 'Lagos', 'Ikeja');

        $this->rider([['Lagos', 'Ikeja']]);

        $options = $this->getJson(
            "/api/v1/admin/orders/{$order->id}/available-agents?shipment_id={$parcel->id}",
            $this->admin()
        )->assertOk()->assertJsonPath('meta.delivery_type', 'intrastate')->json('data');

        $this->assertContains('agent', array_column($options, 'type'));
    }

    public function test_a_rider_who_cannot_reach_the_pharmacy_is_not_offered(): void
    {
        $order = $this->order();

        // Same state, different city: the parcel is in Epe, the rider works Ikeja.
        $parcel = $this->parcelFrom($order, 'Lagos', 'Epe');

        $this->rider([['Lagos', 'Ikeja']]);

        $options = $this->getJson(
            "/api/v1/admin/orders/{$order->id}/available-agents?shipment_id={$parcel->id}",
            $this->admin()
        )->assertOk()->json('data');

        // Lagos is one state, but a rider working Ikeja cannot collect from Epe.
        $this->assertNotContains('agent', array_column($options, 'type'));
    }

    // --- the assignment gate ----------------------------------------------

    public function test_an_independent_rider_is_refused_an_interstate_parcel(): void
    {
        $order = $this->order();
        $parcel = $this->parcelFrom($order, 'Enugu', 'Enugu');

        $rider = $this->rider([['Lagos', 'Ikeja'], ['Enugu', 'Enugu']]);

        // Even covering both ends, one rider is not a relay — and the console
        // is not the only way in, so the rule is enforced here too.
        $this->postJson("/api/v1/admin/orders/{$order->id}/assign-delivery", [
            'delivery_agent_id' => $rider->id,
            'type' => 'agent',
            'shipment_id' => $parcel->id,
        ], $this->admin())
            ->assertStatus(422)
            ->assertJsonPath('code', 'interstate_needs_company');

        $this->assertNull($parcel->fresh()->delivery_agent_id);
    }

    public function test_a_company_takes_the_interstate_parcel(): void
    {
        $order = $this->order();
        $parcel = $this->parcelFrom($order, 'Enugu', 'Enugu');

        $company = $this->company([['Enugu', 'Enugu'], ['Lagos', 'Ikeja']]);

        $this->postJson("/api/v1/admin/orders/{$order->id}/assign-delivery", [
            'delivery_agent_id' => $company->id,
            'type' => 'company',
            'shipment_id' => $parcel->id,
        ], $this->admin())->assertOk();

        $this->assertSame($company->id, $parcel->fresh()->logistics_company_id);
    }

    public function test_an_independent_rider_takes_a_local_parcel(): void
    {
        $order = $this->order();
        $parcel = $this->parcelFrom($order, 'Lagos', 'Ikeja');

        $rider = $this->rider([['Lagos', 'Ikeja']]);

        $this->postJson("/api/v1/admin/orders/{$order->id}/assign-delivery", [
            'delivery_agent_id' => $rider->id,
            'type' => 'agent',
            'shipment_id' => $parcel->id,
        ], $this->admin())->assertOk();

        $this->assertSame($rider->id, $parcel->fresh()->delivery_agent_id);
    }

    public function test_a_rider_who_cannot_collect_is_refused(): void
    {
        $order = $this->order();
        $parcel = $this->parcelFrom($order, 'Lagos', 'Epe');

        $rider = $this->rider([['Lagos', 'Ikeja']]);

        $this->postJson("/api/v1/admin/orders/{$order->id}/assign-delivery", [
            'delivery_agent_id' => $rider->id,
            'type' => 'agent',
            'shipment_id' => $parcel->id,
        ], $this->admin())
            ->assertStatus(422)
            ->assertJsonPath('code', 'agent_cannot_collect');
    }

    // --- the two rules together on one order ------------------------------

    public function test_one_order_can_be_local_on_one_leg_and_interstate_on_the_other(): void
    {
        $order = $this->order();
        $local = $this->parcelFrom($order, 'Lagos', 'Ikeja');
        $distant = $this->parcelFrom($order, 'Enugu', 'Enugu');

        $rider = $this->rider([['Lagos', 'Ikeja']]);
        $company = $this->company([['Enugu', 'Enugu'], ['Lagos', 'Ikeja']]);
        $admin = $this->admin();

        // The local parcel goes to the independent rider...
        $this->postJson("/api/v1/admin/orders/{$order->id}/assign-delivery", [
            'delivery_agent_id' => $rider->id,
            'type' => 'agent',
            'shipment_id' => $local->id,
        ], $admin)->assertOk();

        // ...and the same rider is refused the distant one on the same order.
        $this->postJson("/api/v1/admin/orders/{$order->id}/assign-delivery", [
            'delivery_agent_id' => $rider->id,
            'type' => 'agent',
            'shipment_id' => $distant->id,
        ], $admin)->assertStatus(422)->assertJsonPath('code', 'interstate_needs_company');

        $this->postJson("/api/v1/admin/orders/{$order->id}/assign-delivery", [
            'delivery_agent_id' => $company->id,
            'type' => 'company',
            'shipment_id' => $distant->id,
        ], $admin)->assertOk();

        $this->assertSame($rider->id, $local->fresh()->delivery_agent_id);
        $this->assertSame($company->id, $distant->fresh()->logistics_company_id);
        $this->assertSame('assigned_to_agent', $order->fresh()->status);
    }
}
