<?php

namespace Tests\Feature;

use App\Models\DeliveryAgent;
use App\Models\LogisticsCompany;
use App\Models\Order;
use App\Models\Role;
use App\Models\ShippingRate;
use App\Services\DeliveryEarningsService;
use Tests\TestCase;

/**
 * A delivery rate agreed with one rider.
 *
 * Rates were owned by a logistics company or by nobody — and "nobody" means
 * every courier on the route gets it. There was no way to agree terms with an
 * individual independent rider, which is precisely the courier with no company
 * negotiating for them. The only levers on their pay were a rate everybody else
 * also got, or a percentage of the customer's shipping fee.
 *
 * The lookup takes the most specific rate that applies: the rider's own, then
 * their company's, then the global one.
 */
class RiderRateTest extends TestCase
{
    private array $address = [
        'firstName' => 'Ada', 'lastName' => 'Obi', 'email' => 'ada@example.test',
        'phone' => '08000000000', 'address' => '1 Test Road',
        'city' => 'Ikeja', 'state' => 'Lagos', 'country' => 'Nigeria',
    ];

    /**
     * A real order carrying one parcel out of a Lagos pharmacy.
     *
     * The route is read off the parcel's own pharmacy, so the order has to
     * actually have one — an unsaved order has no origin and every rate lookup
     * returns null regardless of what is on file.
     */
    private function orderWithParcel(float $shipping = 1200): array
    {
        $order = Order::create([
            'user_id' => $this->makeUser()->id,
            'order_number' => 'TEST-RR-'.uniqid(),
            'status' => Order::STATUS_READY_FOR_PICKUP,
            'payment_status' => Order::PAYMENT_PAID,
            'subtotal' => 5000,
            'shipping_amount' => $shipping,
            'total_amount' => 5000 + $shipping,
            'shipping_address' => $this->address,
            'billing_address' => $this->address,
        ]);

        $store = \App\Models\Store::create([
            'owner_id' => $this->makeUser(['role' => 'store_owner'])->id,
            'name' => 'Lagos Pharmacy',
            'slug' => 'rr-'.uniqid(),
            'email' => 'shop'.uniqid().'@pharmacy.test',
            'state' => 'Lagos',
            'city' => 'Ikeja',
            'status' => 'active',
            'verification_status' => \App\Models\Store::VERIFICATION_APPROVED,
        ]);

        $parcel = \App\Models\OrderShipment::create([
            'order_id' => $order->id,
            'store_id' => $store->id,
            'tracking_number' => \App\Models\OrderShipment::generateTrackingNumber(),
            'status' => 'pending',
            'shipping_fee' => $shipping,
            'items' => [],
            'estimated_delivery_days' => 2,
        ]);

        return [$order, $parcel];
    }

    private function rider(?LogisticsCompany $company = null): DeliveryAgent
    {
        return DeliveryAgent::create([
            'name' => 'Rider '.uniqid(),
            'email' => 'rider'.uniqid().'@courier.test',
            'phone' => '0801'.random_int(1000000, 9999999),
            'password' => bcrypt('secret123'),
            'status' => 'available',
            'is_active' => true,
            'logistics_company_id' => $company?->id,
            'service_areas' => [['state' => 'Lagos', 'cities' => ['Ikeja']]],
        ]);
    }

    private function company(): LogisticsCompany
    {
        return LogisticsCompany::create([
            'name' => 'Courier Co '.uniqid(),
            'code' => 'CO'.strtoupper(substr(uniqid(), -6)),
            'admin_email' => 'co'.uniqid().'@courier.test',
            'admin_password' => bcrypt('secret123'),
            'contact_phone' => '08010000000',
            'is_active' => true,
            'service_areas' => [['state' => 'Lagos', 'cities' => ['Ikeja']]],
        ]);
    }

    private function rate(array $attributes): ShippingRate
    {
        return ShippingRate::create(array_merge([
            'from_state' => 'Lagos',
            'to_state' => 'Lagos',
            'is_active' => true,
        ], $attributes));
    }

    private function quoteFor(DeliveryAgent $agent, ?LogisticsCompany $company = null): array
    {
        // Lagos to Lagos: the pharmacy and the customer are both in Ikeja, so
        // the rates defined below are the ones on this route.
        [$order, $parcel] = $this->orderWithParcel();

        return app(DeliveryEarningsService::class)->quote($order, $company, $agent, $parcel);
    }

    public function test_a_rider_earns_their_own_agreed_rate(): void
    {
        $rider = $this->rider();
        $this->rate(['delivery_agent_id' => $rider->id, 'base_rate' => 900]);

        $quote = $this->quoteFor($rider);

        $this->assertSame('agreed_rate', $quote['basis']);
        $this->assertSame(900.0, $quote['courier']);
    }

    public function test_a_riders_rate_beats_the_global_one(): void
    {
        $rider = $this->rider();

        $this->rate(['base_rate' => 500]);                                   // global
        $this->rate(['delivery_agent_id' => $rider->id, 'base_rate' => 900]); // theirs

        // Terms agreed with one courier are exactly that. The reason to record
        // them is that they differ from what everyone else gets.
        $this->assertSame(900.0, $this->quoteFor($rider)['courier']);
    }

    public function test_another_rider_on_the_same_route_is_unaffected(): void
    {
        $paid = $this->rider();
        $other = $this->rider();

        $this->rate(['base_rate' => 500]);
        $this->rate(['delivery_agent_id' => $paid->id, 'base_rate' => 900]);

        $this->assertSame(900.0, $this->quoteFor($paid)['courier']);
        $this->assertSame(500.0, $this->quoteFor($other)['courier']);
    }

    public function test_a_global_rate_still_applies_to_a_rider_without_one(): void
    {
        $rider = $this->rider();
        $this->rate(['base_rate' => 500]);

        $this->assertSame(500.0, $this->quoteFor($rider)['courier']);
    }

    public function test_a_company_rider_is_paid_on_the_companys_terms(): void
    {
        $company = $this->company();
        $rider = $this->rider($company);

        $this->rate(['logistics_company_id' => $company->id, 'base_rate' => 1500]);
        $this->rate(['delivery_agent_id' => $rider->id, 'base_rate' => 900]);

        // The company is the payee for its own rider, so it is the company's
        // terms that apply. A rider's personal rate would otherwise decide what
        // somebody else is paid.
        $this->assertSame(1500.0, $this->quoteFor($rider, $company)['courier']);
    }

    public function test_with_no_rate_at_all_it_falls_back_to_the_shipping_fee(): void
    {
        $rider = $this->rider();

        $quote = $this->quoteFor($rider);

        // Unchanged behaviour for anyone with no terms on file.
        $this->assertSame('commission_percentage', $quote['basis']);
        $this->assertSame(1200.0, $quote['courier']);
    }

    // --- managing them ----------------------------------------------------

    private function admin(): array
    {
        $this->seed(\Database\Seeders\RolesAndPermissionsSeeder::class);

        return $this->tokenFor($this->makeUser([
            'role' => 'admin',
            'role_id' => Role::where('name', 'admin')->value('id'),
        ]));
    }

    public function test_an_admin_can_set_a_rate_for_one_rider(): void
    {
        $rider = $this->rider();

        $this->postJson('/api/v1/admin/delivery/shipping-rates', [
            'from_state' => 'Lagos',
            'to_state' => 'Lagos',
            'base_rate' => 900,
            'delivery_agent_id' => $rider->id,
        ], $this->admin())->assertOk()->assertJsonPath('data.delivery_agent.name', $rider->name);

        $this->assertSame(900.0, $this->quoteFor($rider->fresh())['courier']);
    }

    public function test_a_rate_cannot_belong_to_a_company_and_a_rider_at_once(): void
    {
        $company = $this->company();
        $rider = $this->rider();

        // The lookup prefers the rider, so a company on the same row would be
        // decoration that reads like a restriction.
        $this->postJson('/api/v1/admin/delivery/shipping-rates', [
            'from_state' => 'Lagos',
            'to_state' => 'Lagos',
            'base_rate' => 900,
            'logistics_company_id' => $company->id,
            'delivery_agent_id' => $rider->id,
        ], $this->admin())->assertStatus(422);
    }

    public function test_rates_can_be_listed_for_one_rider(): void
    {
        $rider = $this->rider();

        $this->rate(['base_rate' => 500]);
        $this->rate(['delivery_agent_id' => $rider->id, 'base_rate' => 900]);

        $rates = $this->getJson(
            '/api/v1/admin/delivery/shipping-rates?delivery_agent_id='.$rider->id,
            $this->admin()
        )->assertOk()->json('data');

        $this->assertCount(1, $rates);
        $this->assertSame('900.00', (string) $rates[0]['base_rate']);
    }

    /*
     * A rider under a company is not a payee.
     *
     * Their company settles with them directly: they have no earnings of their
     * own in this system, cannot request a payout, and so cannot hold a rate —
     * it would be a number nobody would ever be paid against.
     */

    public function test_a_company_rider_cannot_be_given_a_rate(): void
    {
        $company = $this->company();
        $rider = $this->rider($company);

        $response = $this->postJson('/api/v1/admin/delivery/shipping-rates', [
            'from_state' => 'Lagos',
            'to_state' => 'Lagos',
            'base_rate' => 900,
            'delivery_agent_id' => $rider->id,
        ], $this->admin())->assertStatus(422);

        // The console only offers independent riders, but the console is not
        // the only way to reach this endpoint.
        $this->assertStringContainsString($company->name, $response->json('message'));
        $this->assertSame(0, ShippingRate::where('delivery_agent_id', $rider->id)->count());
    }

    public function test_a_company_rider_sees_no_earnings_of_their_own(): void
    {
        $company = $this->company();
        $rider = $this->rider($company);

        $data = $this->getJson('/api/v1/delivery/agent/earnings', [
            'Authorization' => 'Bearer '.\App\Support\ApiToken::issue(
                \App\Support\ApiToken::TYPE_AGENT, $rider->id, $rider->email
            ),
            'Accept' => 'application/json',
        ])->assertOk()->json('data');

        $this->assertTrue($data['settled_by_company']);
        $this->assertSame($company->name, $data['company_name']);
        $this->assertArrayNotHasKey('available_balance', $data);
    }

    public function test_a_company_rider_cannot_request_a_payout(): void
    {
        $company = $this->company();
        $rider = $this->rider($company);
        $rider->update(['available_balance' => 50000]);

        $this->postJson('/api/v1/delivery/agent/payouts/request', ['amount' => 10000], [
            'Authorization' => 'Bearer '.\App\Support\ApiToken::issue(
                \App\Support\ApiToken::TYPE_AGENT, $rider->id, $rider->email
            ),
            'Accept' => 'application/json',
        ])->assertStatus(400);

        // Refused before the balance is touched — a held amount against a
        // payout that was never going to happen is money the rider cannot see
        // and nobody will release.
        $this->assertSame(50000.0, (float) $rider->fresh()->available_balance);
        $this->assertSame(0, \App\Models\AgentPayout::where('delivery_agent_id', $rider->id)->count());
    }

    public function test_a_rider_joining_a_company_gives_up_their_rate(): void
    {
        $rider = $this->rider();
        $rate = $this->rate(['delivery_agent_id' => $rider->id, 'base_rate' => 900]);

        $rider->update(['logistics_company_id' => $this->company()->id]);

        // Left behind, it would be terms nobody is paid against — and it would
        // silently come back into force if they went independent again, on an
        // arrangement agreed for a different time.
        $this->assertNull(ShippingRate::find($rate->id));
    }

    public function test_deleting_a_rider_takes_their_rates_with_them(): void
    {
        $rider = $this->rider();
        $rate = $this->rate(['delivery_agent_id' => $rider->id, 'base_rate' => 900]);

        $rider->delete();

        // Otherwise the row is orphaned terms nobody can read or reach.
        $this->assertNull(ShippingRate::find($rate->id));
    }
}
