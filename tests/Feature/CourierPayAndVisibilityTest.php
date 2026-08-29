<?php

namespace Tests\Feature;

use App\Mail\DeliveryAssignmentEmail;
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
use App\Services\DeliveryEarningsService;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

/**
 * What a courier is promised, and what they are paid.
 *
 * These have to be the same number. The assignment email and the earning are
 * both produced by DeliveryEarningsService for exactly that reason — but the
 * email asked for the *order's* quote while the credit is per parcel, so on a
 * split order a courier was promised the whole basket's fee for carrying part
 * of it, and their agreed rate was looked up against the wrong route.
 *
 * Also covers what the email shows: an order filled from two pharmacies listed
 * the whole basket to whoever was assigned either parcel, and never named the
 * pharmacy to collect from at all.
 */
class CourierPayAndVisibilityTest extends TestCase
{
    private array $address = [
        'firstName' => 'Ada', 'lastName' => 'Obi', 'email' => 'ada@example.test',
        'phone' => '08000000000', 'address' => '1 Test Road',
        'city' => 'Ikeja', 'state' => 'Lagos', 'country' => 'Nigeria',
    ];

    private function order(float $shipping = 3000, ?string $status = null): Order
    {
        return Order::create([
            'user_id' => $this->makeUser()->id,
            'order_number' => 'TEST-CP-'.uniqid(),
            'status' => $status ?? Order::STATUS_READY_FOR_PICKUP,
            'payment_status' => Order::PAYMENT_PAID,
            'subtotal' => 10000,
            'shipping_amount' => $shipping,
            'total_amount' => 10000 + $shipping,
            'shipping_address' => $this->address,
            'billing_address' => $this->address,
        ]);
    }

    private function parcel(Order $order, string $state, string $city, float $fee, string $itemName): OrderShipment
    {
        $store = Store::create([
            'owner_id' => $this->makeUser(['role' => 'store_owner'])->id,
            'name' => $state.' Pharmacy',
            'slug' => 'cp-'.uniqid(),
            'email' => 'shop'.uniqid().'@pharmacy.test',
            'state' => $state,
            'city' => $city,
            'status' => 'active',
            'verification_status' => Store::VERIFICATION_APPROVED,
        ]);

        $product = Product::factory()->create(['store_id' => $store->id, 'name' => $itemName]);

        OrderItem::create([
            'order_id' => $order->id,
            'product_id' => $product->id,
            'quantity' => 1,
            'price' => 5000,
            'total' => 5000,
            'product_snapshot' => ['name' => $itemName, 'sku' => 'CP-'.uniqid()],
        ]);

        return OrderShipment::create([
            'order_id' => $order->id,
            'store_id' => $store->id,
            'tracking_number' => OrderShipment::generateTrackingNumber(),
            'status' => 'pending',
            'shipping_fee' => $fee,
            'items' => [],
            'estimated_delivery_days' => 2,
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
            'service_areas' => array_map(fn ($a) => ['state' => $a[0], 'cities' => [$a[1]]], $areas),
        ]);
    }

    // --- agreed rates -----------------------------------------------------

    public function test_an_agreed_rate_with_a_company_is_what_they_are_paid(): void
    {
        $company = $this->company([['Enugu', 'Enugu'], ['Lagos', 'Ikeja']]);

        // The rate on file is a contract: it is what the courier is owed
        // whether or not it matches what the customer happened to pay.
        ShippingRate::create([
            'logistics_company_id' => $company->id,
            'from_state' => 'Enugu',
            'to_state' => 'Lagos',
            'base_rate' => 2600,
            'is_active' => true,
        ]);

        $order = $this->order(3000);
        $parcel = $this->parcel($order, 'Enugu', 'Enugu', 1800, 'Enugu Item');
        $parcel->update(['logistics_company_id' => $company->id]);

        $quote = app(DeliveryEarningsService::class)->quote($order, $company, null, $parcel->fresh());

        $this->assertSame('agreed_rate', $quote['basis']);
        $this->assertSame(2600.0, $quote['courier']);
    }

    public function test_the_agreed_rate_is_looked_up_against_the_parcels_own_route(): void
    {
        $company = $this->company([['Enugu', 'Enugu'], ['Lagos', 'Ikeja']]);

        // Two rates. Only the Enugu one should apply to the Enugu parcel, and
        // the order's own origin is not a reliable guide to which is which.
        ShippingRate::create([
            'logistics_company_id' => $company->id,
            'from_state' => 'Enugu', 'to_state' => 'Lagos',
            'base_rate' => 2600, 'is_active' => true,
        ]);
        ShippingRate::create([
            'logistics_company_id' => $company->id,
            'from_state' => 'Lagos', 'to_state' => 'Lagos',
            'base_rate' => 700, 'is_active' => true,
        ]);

        $order = $this->order(3000);
        $local = $this->parcel($order, 'Lagos', 'Ikeja', 1200, 'Lagos Item');
        $distant = $this->parcel($order, 'Enugu', 'Enugu', 1800, 'Enugu Item');

        $service = app(DeliveryEarningsService::class);

        $this->assertSame(700.0, $service->quote($order, $company, null, $local)['courier']);
        $this->assertSame(2600.0, $service->quote($order, $company, null, $distant)['courier']);
    }

    public function test_with_no_rate_on_file_the_courier_share_comes_from_the_parcels_fee(): void
    {
        $company = $this->company([['Lagos', 'Ikeja']]);

        $order = $this->order(3000);
        $parcel = $this->parcel($order, 'Lagos', 'Ikeja', 1200, 'Lagos Item');

        $quote = app(DeliveryEarningsService::class)->quote($order, $company, null, $parcel);

        // Their parcel's share of the shipping, not the whole order's 3,000.
        $this->assertSame('commission_percentage', $quote['basis']);
        $this->assertSame(1200.0, $quote['customer_fee']);
    }

    // --- promised equals paid ---------------------------------------------

    public function test_the_email_quotes_what_the_earning_credits(): void
    {
        Mail::fake();
        $this->seed(\Database\Seeders\RolesAndPermissionsSeeder::class);

        $admin = $this->tokenFor($this->makeUser([
            'role' => 'admin',
            'role_id' => Role::where('name', 'admin')->value('id'),
        ]));

        $company = $this->company([['Enugu', 'Enugu'], ['Lagos', 'Ikeja']]);

        $order = $this->order(3000);
        $this->parcel($order, 'Lagos', 'Ikeja', 1200, 'Lagos Item');
        $distant = $this->parcel($order, 'Enugu', 'Enugu', 1800, 'Enugu Item');

        $this->postJson("/api/v1/admin/orders/{$order->id}/assign-delivery", [
            'delivery_agent_id' => $company->id,
            'type' => 'company',
            'shipment_id' => $distant->id,
        ], $admin)->assertOk();

        Mail::assertSent(DeliveryAssignmentEmail::class, function ($mail) use ($distant) {
            // The parcel's fee, not the order's 3,000 — a promise the credit
            // on delivery can actually keep.
            $this->assertSame(1800.0, round((float) $mail->shippingFeeAfterCommission, 2));

            // And this parcel's tracking number, not whichever one the order
            // happened to record first.
            $this->assertSame($distant->fresh()->tracking_number, $mail->trackingNumber);

            return true;
        });
    }

    public function test_the_email_lists_only_this_parcels_items_and_names_the_pharmacy(): void
    {
        Mail::fake();
        $this->seed(\Database\Seeders\RolesAndPermissionsSeeder::class);

        $admin = $this->tokenFor($this->makeUser([
            'role' => 'admin',
            'role_id' => Role::where('name', 'admin')->value('id'),
        ]));

        $company = $this->company([['Enugu', 'Enugu'], ['Lagos', 'Ikeja']]);

        $order = $this->order(3000);
        $this->parcel($order, 'Lagos', 'Ikeja', 1200, 'Somebody Elses Medicine');
        $distant = $this->parcel($order, 'Enugu', 'Enugu', 1800, 'The Carried Medicine');

        $this->postJson("/api/v1/admin/orders/{$order->id}/assign-delivery", [
            'delivery_agent_id' => $company->id,
            'type' => 'company',
            'shipment_id' => $distant->id,
        ], $admin)->assertOk();

        Mail::assertSent(DeliveryAssignmentEmail::class, function ($mail) {
            $html = $mail->render();

            $this->assertStringContainsString('The Carried Medicine', $html);

            // A manifest listing another pharmacy's stock gives the courier no
            // way to tell which boxes are theirs.
            $this->assertStringNotContainsString('Somebody Elses Medicine', $html);

            // And the one fact they need first: where to collect it.
            $this->assertStringContainsString('Collect from', $html);
            $this->assertStringContainsString('Enugu Pharmacy', $html);

            return true;
        });
    }

    // --- an order that is over --------------------------------------------

    public function test_cancelling_an_order_calls_off_its_parcels(): void
    {
        $order = $this->order(3000, Order::STATUS_PROCESSING);
        $parcel = $this->parcel($order, 'Lagos', 'Ikeja', 3000, 'Lagos Item');

        $this->putJson("/api/v1/orders/{$order->id}/cancel", [], $this->tokenFor($order->user))
            ->assertOk();

        // The rider's portal reads the shipment, so a parcel left at 'pending'
        // stayed on somebody's job list for an order that no longer exists.
        $this->assertSame('cancelled', $parcel->fresh()->status);
    }

    public function test_a_cancelled_order_cannot_be_dispatched(): void
    {
        $this->seed(\Database\Seeders\RolesAndPermissionsSeeder::class);

        $admin = $this->tokenFor($this->makeUser([
            'role' => 'admin',
            'role_id' => Role::where('name', 'admin')->value('id'),
        ]));

        $order = $this->order(3000);
        $parcel = $this->parcel($order, 'Lagos', 'Ikeja', 3000, 'Lagos Item');
        $order->update(['status' => Order::STATUS_CANCELLED, 'cancelled_at' => now()]);

        $rider = DeliveryAgent::create([
            'name' => 'Musa', 'email' => 'r'.uniqid().'@courier.test',
            'phone' => '0801'.random_int(1000000, 9999999),
            'password' => bcrypt('secret123'), 'status' => 'available', 'is_active' => true,
            'service_areas' => [['state' => 'Lagos', 'cities' => ['Ikeja']]],
        ]);

        $this->postJson("/api/v1/admin/orders/{$order->id}/assign-delivery", [
            'delivery_agent_id' => $rider->id,
            'type' => 'agent',
            'shipment_id' => $parcel->id,
        ], $admin)->assertStatus(422)->assertJsonPath('code', 'order_closed');
    }

    public function test_a_cancelled_parcel_does_not_hold_the_order_open(): void
    {
        $order = $this->order(3000);
        $alive = $this->parcel($order, 'Lagos', 'Ikeja', 1500, 'Lagos Item');
        $dead = $this->parcel($order, 'Enugu', 'Enugu', 1500, 'Enugu Item');

        $dead->update(['status' => 'cancelled']);

        // A parcel that was called off is finished with, not pending. Waiting
        // on it would hold the order open for ever.
        $this->assertTrue($order->fresh()->allShipmentsReached('pending'));

        $alive->update(['status' => 'delivered']);
        $this->assertTrue($order->fresh()->allShipmentsReached('delivered'));
    }
}
