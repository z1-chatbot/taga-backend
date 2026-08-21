<?php

namespace Tests\Feature;

use App\Models\AgentEarning;
use App\Models\AgentPayout;
use App\Models\DeliveryAgent;
use App\Models\DeliverySetting;
use App\Models\LogisticsCompany;
use App\Models\Order;
use App\Models\OrderShipment;
use App\Models\Store;
use App\Support\ApiToken;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

/**
 * Every screen the two courier portals actually open.
 *
 * The admin dashboard has tests; these two portals had none, and they are the
 * ones handling money and other people's customers. Each test here stands for a
 * page: if it fails, that page is broken for a real courier.
 *
 * The isolation tests matter most. Two logistics companies share one instance,
 * and until now nothing proved that one cannot read the other's shipments,
 * riders or balances.
 */
class CourierPortalTest extends TestCase
{
    private LogisticsCompany $company;

    private LogisticsCompany $rival;

    private DeliveryAgent $rider;

    private Store $store;

    protected function setUp(): void
    {
        parent::setUp();

        $this->store = Store::create([
            'owner_id' => $this->makeUser(['role' => 'store_owner'])->id,
            'name' => 'Portal Pharmacy',
            'slug' => 'portal-pharmacy-'.uniqid(),
            'email' => 'shop'.uniqid().'@stores.test',
            'phone' => '08099999999',
            'address' => '3 Test Road',
            'city' => 'Ikeja',
            'state' => 'Lagos',
            'status' => 'active',
            'verification_status' => Store::VERIFICATION_APPROVED,
        ]);

        $this->company = $this->makeCompany('Swift Logistics');
        $this->rival = $this->makeCompany('Rival Logistics');

        $this->rider = $this->makeRider($this->company);

        DeliverySetting::setValue('earnings_hold_period_hours', 0);
        DeliverySetting::setValue('minimum_payout_amount', 5000);
    }

    private function makeCompany(string $name): LogisticsCompany
    {
        return LogisticsCompany::create([
            'name' => $name,
            'code' => 'PC'.random_int(1000, 9999).random_int(10, 99),
            'contact_email' => 'contact'.uniqid().'@logistics.test',
            'admin_email' => 'ops'.uniqid().'@logistics.test',
            'admin_password' => Hash::make('Passw0rd!23'),
            'service_areas' => [['state' => 'Lagos', 'cities' => ['Ikeja']]],
            'commission_percentage' => 85,
            'is_active' => true,
        ]);
    }

    private function makeRider(?LogisticsCompany $company): DeliveryAgent
    {
        return DeliveryAgent::create([
            'logistics_company_id' => $company?->id,
            'name' => 'Rider '.uniqid(),
            'email' => 'rider'.uniqid().'@agents.test',
            'password' => Hash::make('Passw0rd!23'),
            'phone' => '0801'.random_int(1000000, 9999999),
            'service_areas' => [['state' => 'Lagos', 'cities' => ['Ikeja']]],
            'status' => 'available',
            'is_verified' => true,
        ]);
    }

    private function companyHeaders(?LogisticsCompany $company = null): array
    {
        $company ??= $this->company;

        return [
            'Authorization' => 'Bearer '.ApiToken::issue(ApiToken::TYPE_COMPANY, $company->id, $company->admin_email),
            'Accept' => 'application/json',
        ];
    }

    private function riderHeaders(?DeliveryAgent $rider = null): array
    {
        $rider ??= $this->rider;

        return [
            'Authorization' => 'Bearer '.ApiToken::issue(ApiToken::TYPE_AGENT, $rider->id, $rider->email),
            'Accept' => 'application/json',
        ];
    }

    /**
     * A shipment as the real assignment paths leave it.
     *
     * Both of them — the admin assigning an order and a company assigning one of
     * its riders — write the courier onto the order as well as the shipment, and
     * the earnings service reads the order. A fixture that set only the shipment
     * would be testing a state the application never produces.
     */
    private function makeShipment(LogisticsCompany $company, ?DeliveryAgent $rider = null, string $status = 'pending'): OrderShipment
    {
        $order = Order::factory()->create([
            'status' => Order::STATUS_PROCESSING,
            'payment_status' => Order::PAYMENT_PAID,
            'store_id' => $this->store->id,
            'logistics_company_id' => $company->id,
            'delivery_agent_id' => $rider?->id,
            'shipping_amount' => 2000,
            'shipping_address' => [
                'firstName' => 'Ada', 'lastName' => 'Obi', 'email' => 'ada@example.test',
                'address' => '1 Test Street', 'city' => 'Ikeja', 'state' => 'Lagos',
                'country' => 'Nigeria', 'phone' => '08012345678',
            ],
        ]);

        return OrderShipment::create([
            'order_id' => $order->id,
            'store_id' => $this->store->id,
            'tracking_number' => 'TRK'.uniqid(),
            'logistics_company_id' => $company->id,
            'delivery_agent_id' => $rider?->id,
            'status' => $status,
            'shipping_fee' => 2000,
        ]);
    }

    // ---------------------------------------------------------------- login

    public function test_a_company_signs_in_with_its_admin_email(): void
    {
        $this->postJson('/api/v1/delivery/logistics/login', [
            'email' => $this->company->admin_email,
            'password' => 'Passw0rd!23',
        ])->assertOk()->assertJsonPath('success', true)
            ->assertJsonStructure(['data' => ['token', 'company']]);
    }

    public function test_a_wrong_company_password_is_refused(): void
    {
        $this->postJson('/api/v1/delivery/logistics/login', [
            'email' => $this->company->admin_email,
            'password' => 'not-the-password',
        ])->assertStatus(401);
    }

    public function test_a_rider_signs_in_and_gets_a_token(): void
    {
        $this->postJson('/api/v1/delivery/agent/login', [
            'email' => $this->rider->email,
            'password' => 'Passw0rd!23',
        ])->assertOk()->assertJsonStructure(['data' => ['token', 'agent']]);
    }

    public function test_the_portals_are_shut_without_a_token(): void
    {
        $this->getJson('/api/v1/delivery/logistics/dashboard')->assertStatus(401);
        $this->getJson('/api/v1/delivery/agent/dashboard')->assertStatus(401);
    }

    public function test_a_rider_token_does_not_open_the_company_portal(): void
    {
        $this->getJson('/api/v1/delivery/logistics/dashboard', $this->riderHeaders())
            ->assertStatus(401);
    }

    public function test_a_company_token_does_not_open_the_rider_portal(): void
    {
        $this->getJson('/api/v1/delivery/agent/dashboard', $this->companyHeaders())
            ->assertStatus(401);
    }

    // ------------------------------------------------------------ dashboard

    public function test_the_company_dashboard_loads(): void
    {
        $this->makeShipment($this->company, $this->rider);

        $this->getJson('/api/v1/delivery/logistics/dashboard', $this->companyHeaders())
            ->assertOk()->assertJsonPath('success', true);
    }

    public function test_the_rider_dashboard_loads(): void
    {
        $this->makeShipment($this->company, $this->rider);

        $this->getJson('/api/v1/delivery/agent/dashboard', $this->riderHeaders())
            ->assertOk()->assertJsonPath('success', true);
    }

    // --------------------------------------------------------------- agents

    public function test_inviting_a_rider_creates_an_account_under_that_company(): void
    {
        $email = 'newrider'.uniqid().'@agents.test';

        $this->postJson('/api/v1/delivery/logistics/agents/invite', [
            'name' => 'New Rider',
            'email' => $email,
            'phone' => '08055555555',
            'service_areas' => [['state' => 'Lagos', 'cities' => ['Ikeja']]],
        ], $this->companyHeaders())->assertOk()->assertJsonPath('success', true);

        $created = DeliveryAgent::where('email', $email)->first();

        $this->assertNotNull($created);
        $this->assertSame($this->company->id, $created->logistics_company_id);
    }

    public function test_the_same_rider_cannot_be_invited_twice(): void
    {
        $this->postJson('/api/v1/delivery/logistics/agents/invite', [
            'name' => 'Existing',
            'email' => $this->rider->email,
            'phone' => '08055555555',
        ], $this->companyHeaders())->assertStatus(422);
    }

    public function test_the_agents_list_shows_only_that_companys_riders(): void
    {
        $theirs = $this->makeRider($this->rival);

        $response = $this->getJson('/api/v1/delivery/logistics/agents', $this->companyHeaders())
            ->assertOk();

        $emails = collect($response->json('data.data'))->pluck('email');

        $this->assertTrue($emails->contains($this->rider->email));
        $this->assertFalse($emails->contains($theirs->email), 'a rival\'s rider must not appear');
    }

    public function test_a_company_cannot_edit_another_companys_rider(): void
    {
        $theirs = $this->makeRider($this->rival);

        $this->putJson("/api/v1/delivery/logistics/agents/{$theirs->id}", [
            'name' => 'Hijacked',
        ], $this->companyHeaders())->assertStatus(404);

        $this->assertNotSame('Hijacked', $theirs->fresh()->name);
    }

    public function test_a_company_cannot_delete_another_companys_rider(): void
    {
        $theirs = $this->makeRider($this->rival);

        $this->deleteJson("/api/v1/delivery/logistics/agents/{$theirs->id}", [], $this->companyHeaders())
            ->assertStatus(404);

        $this->assertNotNull(DeliveryAgent::find($theirs->id));
    }

    // --------------------------------------------------------------- orders

    public function test_the_orders_list_shows_only_that_companys_shipments(): void
    {
        $mine = $this->makeShipment($this->company, $this->rider);
        $theirs = $this->makeShipment($this->rival);

        $response = $this->getJson('/api/v1/delivery/logistics/orders', $this->companyHeaders())->assertOk();

        $ids = collect($response->json('data.data'))->pluck('id');

        $this->assertTrue($ids->contains($mine->id));
        $this->assertFalse($ids->contains($theirs->id), 'a rival\'s shipment must not appear');
    }

    public function test_a_company_cannot_open_another_companys_shipment(): void
    {
        $theirs = $this->makeShipment($this->rival);

        $this->getJson("/api/v1/delivery/logistics/orders/{$theirs->id}", $this->companyHeaders())
            ->assertStatus(404);
    }

    public function test_a_company_cannot_move_another_companys_shipment(): void
    {
        $theirs = $this->makeShipment($this->rival);

        $this->postJson("/api/v1/delivery/logistics/orders/{$theirs->id}/status", [
            'status' => 'picked_up',
        ], $this->companyHeaders())->assertStatus(404);

        $this->assertSame('pending', $theirs->fresh()->status);
    }

    public function test_a_company_cannot_put_its_rider_on_another_companys_shipment(): void
    {
        $theirs = $this->makeShipment($this->rival);

        $this->postJson("/api/v1/delivery/logistics/orders/{$theirs->id}/assign-agent", [
            'agent_id' => $this->rider->id,
        ], $this->companyHeaders())->assertStatus(404);

        $this->assertNull($theirs->fresh()->delivery_agent_id);
    }

    public function test_a_company_cannot_assign_a_rider_it_does_not_employ(): void
    {
        $mine = $this->makeShipment($this->company);
        $theirs = $this->makeRider($this->rival);

        $response = $this->postJson("/api/v1/delivery/logistics/orders/{$mine->id}/assign-agent", [
            'agent_id' => $theirs->id,
        ], $this->companyHeaders());

        $this->assertContains($response->status(), [403, 404, 422],
            'assigning an outside rider must be refused');
        $this->assertNull($mine->fresh()->delivery_agent_id);
    }

    public function test_a_rider_sees_only_their_own_shipments(): void
    {
        $mine = $this->makeShipment($this->company, $this->rider, 'out_for_delivery');
        $someone_else = $this->makeShipment($this->company, $this->makeRider($this->company), 'out_for_delivery');

        $response = $this->getJson('/api/v1/delivery/agent/shipments', $this->riderHeaders())->assertOk();

        $ids = collect(data_get($response->json(), 'data.data') ?? $response->json('data'))->pluck('id');

        $this->assertTrue($ids->contains($mine->id));
        $this->assertFalse($ids->contains($someone_else->id));
    }

    public function test_a_rider_cannot_move_a_shipment_that_is_not_theirs(): void
    {
        $someone_else = $this->makeShipment($this->company, $this->makeRider($this->company), 'out_for_delivery');

        $response = $this->putJson("/api/v1/delivery/agent/shipments/{$someone_else->id}/status", [
            'status' => 'picked_up',
        ], $this->riderHeaders());

        $this->assertContains($response->status(), [403, 404]);
        $this->assertSame('out_for_delivery', $someone_else->fresh()->status);
    }

    // ------------------------------------------------------------- earnings

    public function test_an_independent_rider_sees_their_own_balances(): void
    {
        $solo = $this->makeRider(null);
        $solo->update(['available_balance' => 20000, 'pending_balance' => 3000, 'total_earned' => 23000]);

        $this->getJson('/api/v1/delivery/agent/earnings', $this->riderHeaders($solo))
            ->assertOk()
            ->assertJsonPath('data.settled_by_company', false)
            ->assertJsonPath('data.available_balance', fn ($v) => (float) $v === 20000.0)
            ->assertJsonPath('data.pending_balance', fn ($v) => (float) $v === 3000.0)
            ->assertJsonPath('data.minimum_payout', fn ($v) => (float) $v === 5000.0);
    }

    public function test_a_company_rider_is_told_their_company_settles_with_them(): void
    {
        $this->rider->update(['available_balance' => 20000]);

        $this->getJson('/api/v1/delivery/agent/earnings', $this->riderHeaders())
            ->assertOk()
            ->assertJsonPath('data.settled_by_company', true)
            ->assertJsonPath('data.company_name', $this->company->name)
            ->assertJsonMissingPath('data.available_balance');
    }

    public function test_a_company_rider_cannot_request_a_payout(): void
    {
        $this->rider->update(['available_balance' => 50000]);

        $this->postJson('/api/v1/delivery/agent/payouts/request', [
            'amount' => 10000,
        ], $this->riderHeaders())->assertStatus(400);

        $this->assertSame(0, AgentPayout::where('delivery_agent_id', $this->rider->id)->count());
    }

    public function test_the_company_financials_page_loads(): void
    {
        $this->company->update(['available_balance' => 40000, 'pending_balance' => 5000, 'total_earned' => 45000]);

        $this->getJson('/api/v1/delivery/logistics/financials', $this->companyHeaders())
            ->assertOk()->assertJsonPath('success', true);
    }

    public function test_a_company_can_withdraw_what_is_available(): void
    {
        $this->company->update(['available_balance' => 40000]);

        $this->postJson('/api/v1/delivery/logistics/payouts/request', [
            'amount' => 30000,
        ], $this->companyHeaders())->assertOk();

        $this->assertEqualsWithDelta(10000, (float) $this->company->fresh()->available_balance, 0.01,
            'the requested amount leaves the available balance while it is pending');
    }

    public function test_a_company_cannot_withdraw_more_than_it_has(): void
    {
        $this->company->update(['available_balance' => 4000]);

        $response = $this->postJson('/api/v1/delivery/logistics/payouts/request', [
            'amount' => 900000,
        ], $this->companyHeaders());

        $this->assertContains($response->status(), [400, 422]);
        $this->assertEqualsWithDelta(4000, (float) $this->company->fresh()->available_balance, 0.01);
    }

    public function test_money_on_hold_cannot_be_withdrawn(): void
    {
        // pending_balance is earned but still inside the admin's hold period.
        // Treating it as spendable is what the old portal labels implied.
        $this->company->update(['available_balance' => 0, 'pending_balance' => 50000]);

        $response = $this->postJson('/api/v1/delivery/logistics/payouts/request', [
            'amount' => 20000,
        ], $this->companyHeaders());

        $this->assertContains($response->status(), [400, 422]);
        $this->assertEqualsWithDelta(50000, (float) $this->company->fresh()->pending_balance, 0.01);
    }

    public function test_the_payout_history_shows_only_that_companys_payouts(): void
    {
        $mine = AgentPayout::create([
            'logistics_company_id' => $this->company->id,
            'amount' => 10000,
            'payout_type' => 'logistics_company',
            'status' => 'pending',
            'reference' => 'PO'.uniqid(),
        ]);
        $theirs = AgentPayout::create([
            'logistics_company_id' => $this->rival->id,
            'amount' => 90000,
            'payout_type' => 'logistics_company',
            'status' => 'pending',
            'reference' => 'PO'.uniqid(),
        ]);

        $response = $this->getJson('/api/v1/delivery/logistics/payouts', $this->companyHeaders())->assertOk();

        $ids = collect(data_get($response->json(), 'data.data') ?? $response->json('data'))->pluck('id');

        $this->assertTrue($ids->contains($mine->id));
        $this->assertFalse($ids->contains($theirs->id));
    }

    // ------------------------------------------------------- rates, profile

    public function test_the_shipping_rates_page_loads(): void
    {
        $this->getJson('/api/v1/delivery/logistics/shipping-rates', $this->companyHeaders())
            ->assertOk()->assertJsonPath('success', true);
    }

    public function test_a_company_can_read_its_profile(): void
    {
        $this->getJson('/api/v1/delivery/logistics/profile', $this->companyHeaders())
            ->assertOk()->assertJsonPath('success', true);
    }

    public function test_a_rider_can_read_and_update_their_profile(): void
    {
        $this->getJson('/api/v1/delivery/agent/profile', $this->riderHeaders())->assertOk();

        $this->putJson('/api/v1/delivery/agent/profile', [
            'phone' => '08077777777',
        ], $this->riderHeaders())->assertOk();

        $this->assertSame('08077777777', $this->rider->fresh()->phone);
    }

    public function test_a_rider_can_go_off_duty(): void
    {
        $this->putJson('/api/v1/delivery/agent/status', [
            'status' => 'offline',
        ], $this->riderHeaders())->assertOk();

        $this->assertSame('offline', $this->rider->fresh()->status);
    }

    public function test_a_company_changes_its_password_and_the_old_one_stops_working(): void
    {
        $this->postJson('/api/v1/delivery/logistics/change-password', [
            'current_password' => 'Passw0rd!23',
            'new_password' => 'Brandnew!45',
            'new_password_confirmation' => 'Brandnew!45',
        ], $this->companyHeaders())->assertOk();

        $this->postJson('/api/v1/delivery/logistics/login', [
            'email' => $this->company->admin_email,
            'password' => 'Passw0rd!23',
        ])->assertStatus(401);

        $this->postJson('/api/v1/delivery/logistics/login', [
            'email' => $this->company->admin_email,
            'password' => 'Brandnew!45',
        ])->assertOk();
    }

    public function test_a_company_cannot_change_its_password_without_the_current_one(): void
    {
        $response = $this->postJson('/api/v1/delivery/logistics/change-password', [
            'current_password' => 'wrong-password',
            'new_password' => 'Brandnew!45',
            'new_password_confirmation' => 'Brandnew!45',
        ], $this->companyHeaders());

        $this->assertContains($response->status(), [400, 401, 422]);
    }

    // ------------------------------------------------------------- delivery

    public function test_a_rider_confirming_delivery_pays_the_company_once(): void
    {
        $shipment = $this->makeShipment($this->company, $this->rider, 'out_for_delivery');
        $code = $shipment->order->generateDeliveryCode();

        $this->putJson("/api/v1/delivery/agent/shipments/{$shipment->id}/status", [
            'status' => 'delivered',
            'delivery_code' => $code,
        ], $this->riderHeaders())->assertOk();

        $this->assertSame(1, AgentEarning::where('order_id', $shipment->order_id)->count());

        // The company employs this rider, so the company is paid, not the rider.
        $this->assertGreaterThan(0, (float) $this->company->fresh()->available_balance);
        $this->assertEqualsWithDelta(0, (float) $this->rider->fresh()->available_balance, 0.01);
    }
}
