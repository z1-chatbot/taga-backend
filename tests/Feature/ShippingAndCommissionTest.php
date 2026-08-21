<?php

namespace Tests\Feature;

use App\Models\AgentEarning;
use App\Models\Cart;
use App\Models\DeliveryAgent;
use App\Models\DeliverySetting;
use App\Models\LogisticsCompany;
use App\Models\Order;
use App\Models\OrderShipment;
use App\Models\Product;
use App\Models\ShippingRate;
use App\Models\ShippingZone;
use App\Models\Store;
use App\Support\ApiToken;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

/**
 * What the customer pays for delivery, and what the platform keeps of it.
 *
 * These two are one subject. The shipping zone decides the customer's fee; the
 * courier's rate or commission share decides how much of that fee leaves the
 * platform again. Both sides were leaking: an address no zone covered shipped
 * free, and a delivery confirmed in two portals was paid for twice.
 */
class ShippingAndCommissionTest extends TestCase
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
            'slug' => 'ship-'.uniqid(),
            'state' => 'Lagos',
            'city' => 'Ikeja',
            'status' => 'active',
            // Nothing a pharmacy lists is purchasable until its licence is
            // approved, so a fixture that sells has to be a verified shop.
            'verification_status' => \App\Models\Store::VERIFICATION_APPROVED,
        ]);

        $this->company = LogisticsCompany::create([
            'name' => 'Test Logistics',
            'code' => 'SC'.random_int(1000, 9999),
            'admin_email' => 'ops'.uniqid().'@logistics.test',
            'admin_password' => Hash::make('Passw0rd!23'),
            'service_areas' => [['state' => 'Lagos', 'cities' => ['Ikeja']]],
            'commission_percentage' => 85,
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

        DeliverySetting::setValue('earnings_hold_period_hours', 0);
        DeliverySetting::setValue('enable_commission_system', 'false');
    }

    private function address(string $state = 'Lagos'): array
    {
        return [
            'firstName' => 'Ada', 'lastName' => 'Obi', 'email' => 'ada@example.test',
            'address' => '1 Test Street', 'city' => 'Ikeja', 'state' => $state,
            'country' => 'Nigeria', 'phone' => '08012345678',
        ];
    }

    private function basket(string $guest, float $price = 2000): Product
    {
        $product = Product::factory()->create([
            'store_id' => $this->store->id,
            'price' => $price,
            'stock_quantity' => 20,
            'requires_prescription' => false,
        ]);

        Cart::create([
            'session_id' => $guest,
            'product_id' => $product->id,
            'quantity' => 1,
            'price' => $price,
        ]);

        return $product;
    }

    private function checkout(string $guest, string $state = 'Lagos')
    {
        return $this->postJson('/api/v1/orders', [
            'shipping_address' => $this->address($state),
            'payment_method' => 'online',
            'delivery_type' => 'home_delivery',
        ], $this->guestHeaders($guest));
    }

    // ---- the customer's fee ------------------------------------------------

    public function test_an_address_no_zone_covers_cannot_be_checked_out(): void
    {
        $guest = 'zone-'.uniqid();
        $this->basket($guest);

        // No zone at all — which is exactly the state this database is in.
        $this->checkout($guest, 'Kano')
            ->assertStatus(422)
            ->assertJsonPath('code', 'shipping_not_covered');

        $this->assertSame(0, Order::where('session_id', $guest)->count(),
            'no order should exist for a delivery we cannot make');
    }

    public function test_store_pickup_is_allowed_where_delivery_is_not(): void
    {
        $guest = 'pickup-'.uniqid();
        $this->basket($guest);

        $this->postJson('/api/v1/orders', [
            'shipping_address' => $this->address('Kano'),
            'payment_method' => 'online',
            'delivery_type' => 'store_pickup',
        ], $this->guestHeaders($guest))->assertStatus(201);
    }

    public function test_a_covered_route_charges_the_zone_fee(): void
    {
        $this->serviceableZone('Lagos', 'Lagos', 1500);

        $guest = 'covered-'.uniqid();
        $this->basket($guest);

        $response = $this->checkout($guest)->assertStatus(201);

        $order = Order::find($response->json('data.id'));

        $this->assertSame('1500.00', $order->shipping_amount);
    }

    public function test_the_estimate_admits_when_a_route_is_not_covered(): void
    {
        $guest = 'estimate-'.uniqid();
        $this->basket($guest);

        $this->postJson('/api/v1/orders/estimate-shipping', [
            'state' => 'Kano',
            'subtotal' => 2000,
        ], $this->guestHeaders($guest))
            ->assertOk()
            ->assertJsonPath('data.is_covered', false)
            // A ₦0 fee for an unreachable address must not read as a free
            // delivery — that is what the checkout page was showing.
            ->assertJsonPath('data.is_free_shipping', false);
    }

    public function test_a_free_shipping_basket_is_still_a_covered_one(): void
    {
        $this->serviceableZone('Lagos', 'Lagos', 1500);

        $guest = 'free-'.uniqid();
        $this->basket($guest, 60000); // above the 50,000 threshold

        $this->postJson('/api/v1/orders/estimate-shipping', [
            'state' => 'Lagos',
            'city' => 'Ikeja',
            'subtotal' => 60000,
        ], $this->guestHeaders($guest))
            ->assertOk()
            ->assertJsonPath('data.is_covered', true)
            ->assertJsonPath('data.shipping_fee', 0)
            ->assertJsonPath('data.is_free_shipping', true);
    }

    public function test_overlapping_zones_resolve_to_the_cheaper_one(): void
    {
        $this->serviceableZone('Lagos', 'Lagos', 3000);
        $this->serviceableZone('Lagos', 'Lagos', 1200);

        // Nothing stops an admin creating both. Whichever row came back first
        // used to set the price, so the same basket could cost either amount.
        for ($i = 0; $i < 3; $i++) {
            $zone = ShippingZone::findByRoute('Lagos', 'Lagos', 'Ikeja');
            $this->assertSame('1200.00', $zone->shipping_fee);
        }
    }

    // ---- what leaves the platform again ------------------------------------

    private function deliveredOrder(float $shippingFee = 2000): Order
    {
        $order = Order::factory()->create([
            'status' => Order::STATUS_ASSIGNED_TO_AGENT,
            'payment_status' => Order::PAYMENT_PAID,
            'store_id' => $this->store->id,
            'shipping_amount' => $shippingFee,
            'shipping_address' => $this->address(),
            'delivery_agent_id' => $this->agent->id,
            'logistics_company_id' => $this->company->id,
        ]);

        return $order;
    }

    private function agentHeaders(): array
    {
        return ['Authorization' => 'Bearer '.ApiToken::issue(
            ApiToken::TYPE_AGENT, $this->agent->id, $this->agent->email
        )];
    }

    public function test_a_delivery_is_paid_for_once_however_many_portals_confirm_it(): void
    {
        $order = $this->deliveredOrder();

        $shipment = OrderShipment::create([
            'order_id' => $order->id,
            'store_id' => $this->store->id,
            'tracking_number' => 'TRK'.uniqid(),
            'logistics_company_id' => $this->company->id,
            'delivery_agent_id' => $this->agent->id,
            'status' => 'out_for_delivery',
            'shipping_fee' => 2000,
        ]);

        // The rider confirms in their app...
        $this->putJson("/api/v1/delivery/agent/shipments/{$shipment->id}/status", [
            'status' => 'delivered',
        ], $this->agentHeaders());

        // ...and an admin, not seeing it, marks the order delivered too.
        $this->putJson("/api/v1/admin/orders/{$order->id}/status", [
            'status' => Order::STATUS_DELIVERED,
        ], $this->tokenFor($this->makeUser(['role' => 'admin'])));

        $this->assertSame(1, AgentEarning::where('order_id', $order->id)->count(),
            'one journey, one earning');

        $this->assertEqualsWithDelta(
            2000,
            (float) $this->company->fresh()->total_earned,
            0.01,
            'the courier must not be paid twice for one delivery'
        );
    }

    public function test_only_one_balance_moves_for_one_delivery(): void
    {
        $order = $this->deliveredOrder();

        $order->update(['status' => Order::STATUS_DELIVERED]);

        // The rider works for a company. Both used to be credited the full
        // amount, and both can request a payout against their own balance.
        $this->assertEqualsWithDelta(2000, (float) $this->company->fresh()->available_balance, 0.01);
        $this->assertEqualsWithDelta(0, (float) $this->agent->fresh()->available_balance, 0.01);
    }

    public function test_a_standalone_rider_is_paid_directly(): void
    {
        $rider = DeliveryAgent::create([
            'name' => 'Solo Rider',
            'email' => 'solo'.uniqid().'@agents.test',
            'password' => Hash::make('Passw0rd!23'),
            'phone' => '08011111111',
            'service_areas' => [['state' => 'Lagos', 'cities' => ['Ikeja']]],
            'status' => 'available',
            'is_verified' => true,
        ]);

        $order = Order::factory()->create([
            'status' => Order::STATUS_ASSIGNED_TO_AGENT,
            'payment_status' => Order::PAYMENT_PAID,
            'store_id' => $this->store->id,
            'shipping_amount' => 1800,
            'shipping_address' => $this->address(),
            'delivery_agent_id' => $rider->id,
        ]);

        $order->update(['status' => Order::STATUS_DELIVERED]);

        $this->assertEqualsWithDelta(1800, (float) $rider->fresh()->available_balance, 0.01);
    }

    public function test_an_agreed_rate_is_what_the_courier_is_paid(): void
    {
        ShippingRate::create([
            'logistics_company_id' => $this->company->id,
            'from_state' => 'Lagos',
            'to_state' => 'Lagos',
            'base_rate' => 1200,
            'is_active' => true,
        ]);

        $order = $this->deliveredOrder(2000);
        $order->update(['status' => Order::STATUS_DELIVERED]);

        $earning = AgentEarning::where('order_id', $order->id)->first();

        $this->assertSame('1200.00', $earning->agent_commission);
        $this->assertSame('800.00', $earning->platform_commission, 'the platform keeps the difference');
    }

    public function test_without_an_agreed_rate_the_configured_share_applies(): void
    {
        DeliverySetting::setValue('enable_commission_system', 'true');

        // No ShippingRate on file for this route. The courier used to take the
        // entire fee here and the commission settings did nothing at all.
        $order = $this->deliveredOrder(2000);
        $order->update(['status' => Order::STATUS_DELIVERED]);

        $earning = AgentEarning::where('order_id', $order->id)->first();

        $this->assertSame('1700.00', $earning->agent_commission, '85% of ₦2,000');
        $this->assertSame('300.00', $earning->platform_commission);
    }

    public function test_with_commissions_switched_off_the_courier_keeps_the_fee(): void
    {
        DeliverySetting::setValue('enable_commission_system', 'false');

        $order = $this->deliveredOrder(2000);
        $order->update(['status' => Order::STATUS_DELIVERED]);

        $earning = AgentEarning::where('order_id', $order->id)->first();

        $this->assertSame('2000.00', $earning->agent_commission);
        $this->assertSame('0.00', $earning->platform_commission);
    }

    public function test_the_admin_console_also_credits_the_courier(): void
    {
        $order = $this->deliveredOrder(2000);

        // Two admin routes marked an order delivered and paid nobody, so a
        // delivery confirmed from the console left the courier unpaid.
        $this->putJson("/api/v1/admin/orders/{$order->id}/status", [
            'status' => Order::STATUS_DELIVERED,
        ], $this->tokenFor($this->makeUser(['role' => 'admin'])))->assertOk();

        $this->assertSame(1, AgentEarning::where('order_id', $order->id)->count());
    }

    public function test_the_quote_a_courier_is_shown_is_the_one_they_are_paid(): void
    {
        DeliverySetting::setValue('enable_commission_system', 'true');

        $order = $this->deliveredOrder(2000);

        $quoted = (new \App\Mail\DeliveryAssignmentEmail(
            $order, 'company', $this->company->name
        ))->shippingFeeAfterCommission;

        $order->update(['status' => Order::STATUS_DELIVERED]);

        $paid = (float) AgentEarning::where('order_id', $order->id)->first()->agent_commission;

        $this->assertEqualsWithDelta($quoted, $paid, 0.01,
            'the assignment email promised one figure and the ledger paid another');
    }

    // ---- who may withdraw, and what the balances mean ----------------------

    public function test_a_company_rider_has_no_earnings_of_their_own(): void
    {
        $order = $this->deliveredOrder();
        $order->update(['status' => Order::STATUS_DELIVERED]);

        $this->getJson('/api/v1/delivery/agent/earnings', $this->agentHeaders())
            ->assertOk()
            ->assertJsonPath('data.settled_by_company', true)
            ->assertJsonPath('data.company_name', $this->company->name)
            ->assertJsonMissingPath('data.available_balance');
    }

    public function test_a_company_rider_cannot_request_a_payout(): void
    {
        $this->agent->update(['available_balance' => 50000]);

        $this->postJson('/api/v1/delivery/agent/payouts/request', [
            'amount' => 10000,
        ], $this->agentHeaders())->assertStatus(400);
    }

    public function test_an_independent_rider_sees_and_withdraws_their_own(): void
    {
        $rider = DeliveryAgent::create([
            'name' => 'Solo Rider',
            'email' => 'solo'.uniqid().'@agents.test',
            'password' => Hash::make('Passw0rd!23'),
            'phone' => '08011111111',
            'service_areas' => [['state' => 'Lagos', 'cities' => ['Ikeja']]],
            'status' => 'available',
            'is_verified' => true,
            'available_balance' => 20000,
        ]);

        $headers = ['Authorization' => 'Bearer '.ApiToken::issue(
            ApiToken::TYPE_AGENT, $rider->id, $rider->email
        )];

        $this->getJson('/api/v1/delivery/agent/earnings', $headers)
            ->assertOk()
            ->assertJsonPath('data.settled_by_company', false)
            ->assertJsonPath('data.available_balance', fn ($value) => (float) $value === 20000.0);

        $this->postJson('/api/v1/delivery/agent/payouts/request', [
            'amount' => 10000,
        ], $headers)->assertOk();

        $this->assertEqualsWithDelta(10000, (float) $rider->fresh()->available_balance, 0.01,
            'the claimed amount leaves the withdrawable balance straight away');

        $this->assertEqualsWithDelta(0, (float) $rider->fresh()->pending_balance, 0.01,
            'a requested payout is not an earning on hold');
    }

    public function test_a_request_below_the_admins_minimum_is_refused(): void
    {
        DeliverySetting::setValue('minimum_payout_amount', 5000);

        $rider = DeliveryAgent::create([
            'name' => 'Solo Rider',
            'email' => 'solo'.uniqid().'@agents.test',
            'password' => Hash::make('Passw0rd!23'),
            'phone' => '08011111111',
            'service_areas' => [['state' => 'Lagos', 'cities' => ['Ikeja']]],
            'status' => 'available',
            'is_verified' => true,
            'available_balance' => 20000,
        ]);

        $this->postJson('/api/v1/delivery/agent/payouts/request', [
            'amount' => 1000,
        ], ['Authorization' => 'Bearer '.ApiToken::issue(
            ApiToken::TYPE_AGENT, $rider->id, $rider->email
        )])->assertStatus(400);
    }

    public function test_held_earnings_are_released_to_whoever_was_credited(): void
    {
        DeliverySetting::setValue('earnings_hold_period_hours', 24);

        $order = $this->deliveredOrder(2000);
        $order->update(['status' => Order::STATUS_DELIVERED]);

        $earning = AgentEarning::where('order_id', $order->id)->first();

        $this->assertSame('pending', $earning->status);
        $this->assertEqualsWithDelta(2000, (float) $this->company->fresh()->pending_balance, 0.01);

        // The hold elapses and the scheduled job releases it.
        $earning->update(['available_at' => now()->subMinute()]);
        $earning->fresh()->makeAvailable();

        $company = $this->company->fresh();

        $this->assertEqualsWithDelta(0, (float) $company->pending_balance, 0.01);
        $this->assertEqualsWithDelta(2000, (float) $company->available_balance, 0.01,
            'the release must move the same balance the delivery credited');
    }

    public function test_a_declined_payout_gives_the_money_back(): void
    {
        $this->company->update(['available_balance' => 0]);

        $payout = \App\Models\AgentPayout::create([
            'logistics_company_id' => $this->company->id,
            'payout_type' => 'logistics_company',
            'amount' => 12000,
            'status' => 'pending',
        ]);

        $this->postJson("/api/v1/admin/delivery/payouts/{$payout->id}/reject", [
            'reason' => 'Bank details do not match the registered account.',
        ], $this->tokenFor($this->makeUser(['role' => 'admin'])))->assertOk();

        $this->assertEqualsWithDelta(12000, (float) $this->company->fresh()->available_balance, 0.01,
            'a declined request must not strand the money');
    }

    public function test_approving_a_payout_does_not_touch_held_earnings(): void
    {
        $this->company->update(['available_balance' => 0, 'pending_balance' => 8000]);

        $payout = \App\Models\AgentPayout::create([
            'logistics_company_id' => $this->company->id,
            'payout_type' => 'logistics_company',
            'amount' => 5000,
            'status' => 'pending',
        ]);

        $this->postJson("/api/v1/admin/delivery/payouts/{$payout->id}/approve", [],
            $this->tokenFor($this->makeUser(['role' => 'admin'])))->assertOk();

        $company = $this->company->fresh();

        $this->assertEqualsWithDelta(8000, (float) $company->pending_balance, 0.01,
            'earnings still on hold are nothing to do with a payout being approved');
        $this->assertEqualsWithDelta(5000, (float) $company->total_paid_out, 0.01);
    }

    // ---- what the pharmacy is owed -----------------------------------------

    public function test_store_revenue_does_not_move_when_a_price_changes_later(): void
    {
        $this->serviceableZone('Lagos', 'Lagos', 1500);

        $guest = 'revenue-'.uniqid();
        $product = $this->basket($guest, 5000);

        $response = $this->checkout($guest)->assertStatus(201);

        $order = Order::find($response->json('data.id'));
        $item = $order->items()->first();

        $atSale = $item->store_revenue;
        $this->assertGreaterThan(0, $atSale);

        // The pharmacy starts a promotion after the order has been delivered.
        $product->update(['sale_price' => 1000]);

        $this->assertEqualsWithDelta(
            $atSale,
            $order->items()->first()->store_revenue,
            0.01,
            'money already earned cannot be revalued by a later price change'
        );
    }
}
