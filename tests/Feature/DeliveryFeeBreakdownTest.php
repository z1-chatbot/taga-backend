<?php

namespace Tests\Feature;

use App\Models\Cart;
use App\Models\Product;
use App\Models\ShippingZone;
use App\Models\Store;
use App\Models\SystemSetting;
use App\Models\User;
use Tests\TestCase;

/**
 * Where the delivery fee on the checkout page came from.
 *
 * The fee arrived as a single number with nothing behind it. For a basket
 * spanning two states that is the one question a shopper will ask — is ₦5,000
 * the long leg, the short one, or both added together? — and neither the
 * storefront nor an operator could answer it without reading the log.
 *
 * The rule: one journey per *pickup run* — the pharmacies a single rider
 * collects from in one round, which is the ones in the same city — each priced
 * on its own route, and the customer pays for each run. These tests pin down
 * both the arithmetic and that the quote shows its working.
 */
class DeliveryFeeBreakdownTest extends TestCase
{
    private function pharmacy(string $state, string $city): Store
    {
        return Store::create([
            'owner_id' => $this->makeUser(['role' => 'store_owner'])->id,
            'name' => $state.' Pharmacy',
            'slug' => 'fb-'.uniqid(),
            'email' => 'shop'.uniqid().'@pharmacy.test',
            'state' => $state,
            'city' => $city,
            'status' => 'active',
            'verification_status' => Store::VERIFICATION_APPROVED,
        ]);
    }

    private function zone(?string $from, string $to, float $fee): ShippingZone
    {
        return ShippingZone::create([
            'name' => ($from ?? 'Anywhere').' - '.$to,
            'origin_state' => $from,
            'state' => $to,
            'shipping_fee' => $fee,
            'type' => $from === $to ? 'intrastate' : 'interstate',
            'is_active' => true,
            'estimated_delivery_days' => 3,
        ]);
    }

    private function basketFrom(User $shopper, Store ...$stores): void
    {
        foreach ($stores as $store) {
            $product = Product::factory()->create(['store_id' => $store->id, 'price' => 2000]);

            Cart::create([
                'user_id' => $shopper->id,
                'product_id' => $product->id,
                'quantity' => 1,
                'price' => 2000,
            ]);
        }
    }

    protected function setUp(): void
    {
        parent::setUp();

        // Otherwise a two-item basket can cross the free-delivery line and the
        // quote under test is never reached.
        SystemSetting::setValue(SystemSetting::CATEGORY_GENERAL, 'free_shipping_threshold', 500000);
    }

    public function test_a_basket_from_two_states_is_charged_for_both_journeys(): void
    {
        $shopper = $this->makeUser();

        $far = $this->pharmacy('Lagos', 'Ikeja');
        $near = $this->pharmacy('Rivers', 'Port Harcourt');

        $this->zone('Lagos', 'Rivers', 5000);
        $this->zone('Rivers', 'Rivers', 1500);

        $this->basketFrom($shopper, $far, $near);

        $data = $this->postJson('/api/v1/orders/estimate-shipping', [
            'state' => 'Rivers',
            'city' => 'Port Harcourt',
            'subtotal' => 4000,
        ], $this->tokenFor($shopper))->assertOk()->json('data');

        // Port Harcourt shopper, one medication from Lagos and one from a
        // pharmacy in their own city: 5,000 for the long run plus 1,500 for the
        // local one.
        $this->assertEquals(6500, $data['shipping_fee']);

        $legs = collect($data['breakdown']);
        $this->assertCount(2, $legs);

        // The rows have to add up to the total, or the breakdown is decoration
        // rather than an explanation.
        $this->assertEquals(6500, $legs->sum('fee'));
        $this->assertTrue($legs->every(fn ($leg) => $leg['charged'] === true));

        $this->assertEquals(5000, $legs->firstWhere('store', 'Lagos Pharmacy')['fee']);
        $this->assertEquals(1500, $legs->firstWhere('store', 'Rivers Pharmacy')['fee']);
    }

    public function test_each_leg_names_the_route_and_the_zone_that_priced_it(): void
    {
        $shopper = $this->makeUser();

        $lagos = $this->pharmacy('Lagos', 'Ikeja');
        $this->zone('Lagos', 'Rivers', 5000);
        $this->basketFrom($shopper, $lagos, $this->pharmacy('Rivers', 'Port Harcourt'));
        $this->zone('Rivers', 'Rivers', 1500);

        $legs = collect($this->postJson('/api/v1/orders/estimate-shipping', [
            'state' => 'Rivers',
            'city' => 'Port Harcourt',
            'subtotal' => 4000,
        ], $this->tokenFor($shopper))->assertOk()->json('data.breakdown'));

        $leg = $legs->firstWhere('store', 'Lagos Pharmacy');

        // An operator asking why a fee is what it is needs the row of
        // configuration behind it, not just the amount — and the origin names
        // the city, because the city is what decides which parcels travel
        // together.
        $this->assertSame('Ikeja, Lagos', $leg['from']);
        $this->assertSame('Rivers', $leg['to']);
        $this->assertSame('Lagos - Rivers', $leg['zone']);
    }

    public function test_two_pharmacies_in_one_city_are_one_run_and_one_fee(): void
    {
        $shopper = $this->makeUser();

        // A rider collects from both and drives one route. Charging twice bills
        // for a journey nobody makes.
        $this->basketFrom(
            $shopper,
            $this->pharmacy('Rivers', 'Port Harcourt'),
            $this->pharmacy('Rivers', 'Port Harcourt')
        );

        $this->zone('Rivers', 'Rivers', 1500);

        $data = $this->postJson('/api/v1/orders/estimate-shipping', [
            'state' => 'Rivers',
            'city' => 'Port Harcourt',
            'subtotal' => 4000,
        ], $this->tokenFor($shopper))->assertOk()->json('data');

        $this->assertEquals(1500, $data['shipping_fee']);
        $this->assertCount(1, $data['breakdown']);

        // Both shops are named on the one line, so a shopper seeing a single
        // fee against two pharmacies can see why it is single.
        $this->assertCount(2, $data['breakdown'][0]['stores']);
    }

    public function test_two_cities_in_one_state_are_two_runs(): void
    {
        $shopper = $this->makeUser();

        // Same state, same zone, same price — but Ikeja and Epe are a hundred
        // kilometres apart and nobody collects from both on one round.
        $this->basketFrom(
            $shopper,
            $this->pharmacy('Rivers', 'Port Harcourt'),
            $this->pharmacy('Rivers', 'Bonny')
        );

        $this->zone('Rivers', 'Rivers', 1500);

        $data = $this->postJson('/api/v1/orders/estimate-shipping', [
            'state' => 'Rivers',
            'city' => 'Port Harcourt',
            'subtotal' => 4000,
        ], $this->tokenFor($shopper))->assertOk()->json('data');

        $this->assertEquals(3000, $data['shipping_fee']);
        $this->assertCount(2, $data['breakdown']);
    }

    public function test_several_items_from_one_pharmacy_are_one_journey(): void
    {
        $shopper = $this->makeUser();
        $store = $this->pharmacy('Rivers', 'Port Harcourt');

        // The rule that matters most, and the one easiest to break by accident:
        // a fee follows deliveries, never items. Three boxes in one parcel is
        // one journey.
        $this->basketFrom($shopper, $store, $store, $store);

        $this->zone('Rivers', 'Rivers', 1500);

        $data = $this->postJson('/api/v1/orders/estimate-shipping', [
            'state' => 'Rivers',
            'city' => 'Port Harcourt',
            'subtotal' => 6000,
        ], $this->tokenFor($shopper))->assertOk()->json('data');

        $this->assertEquals(1500, $data['shipping_fee']);
        $this->assertCount(1, $data['breakdown']);
    }

    public function test_free_delivery_says_it_was_the_threshold(): void
    {
        SystemSetting::setValue(SystemSetting::CATEGORY_GENERAL, 'free_shipping_threshold', 50000);

        $shopper = $this->makeUser();
        $this->basketFrom($shopper, $this->pharmacy('Rivers', 'Port Harcourt'));
        $this->zone('Rivers', 'Rivers', 1500);

        $data = $this->postJson('/api/v1/orders/estimate-shipping', [
            'state' => 'Rivers',
            'city' => 'Port Harcourt',
            'subtotal' => 90000,
        ], $this->tokenFor($shopper))->assertOk()->json('data');

        $this->assertEquals(0, $data['shipping_fee']);

        // "Free" with no reason reads as a bug. This one was a threshold, and
        // the page can say which.
        $reason = $data['breakdown'][0];
        $this->assertSame('free_shipping_threshold', $reason['reason']);
        $this->assertEquals(50000, $reason['threshold']);
    }

    public function test_a_single_pharmacy_still_reports_its_one_journey(): void
    {
        $shopper = $this->makeUser();
        $this->basketFrom($shopper, $this->pharmacy('Rivers', 'Port Harcourt'));
        $this->zone('Rivers', 'Rivers', 1500);

        $data = $this->postJson('/api/v1/orders/estimate-shipping', [
            'state' => 'Rivers',
            'city' => 'Port Harcourt',
            'subtotal' => 2000,
        ], $this->tokenFor($shopper))->assertOk()->json('data');

        $this->assertEquals(1500, $data['shipping_fee']);
        $this->assertCount(1, $data['breakdown']);
        $this->assertTrue($data['breakdown'][0]['charged']);
    }
}
