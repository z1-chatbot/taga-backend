<?php

namespace Tests\Feature;

use App\Models\Cart;
use App\Models\Product;
use App\Models\Store;
use App\Models\User;
use Tests\TestCase;

/**
 * What a basket costs to ship when it is drawn from more than one pharmacy.
 *
 * Two rules decide it, and both are worth pinning because both are easy to
 * break by accident:
 *
 *  - the fee follows journeys, never items and not shops either: the
 *    pharmacies a rider collects from in one round are one journey, priced
 *    once, and the customer pays once per round;
 *  - a destination nothing serves is refused outright rather than shipped for
 *    nothing.
 *
 * The second rule is basket-wide on purpose. ShippingZone::findByRoute falls
 * back to any zone serving the destination when no zone names the origin, so a
 * pharmacy in an unrated state is still priced rather than stranded — see
 * test_an_unrated_origin_falls_back_to_the_destination_rate, which documents
 * the consequence of that fallback as much as it protects it.
 */
class PartialShippingCoverageTest extends TestCase
{
    private array $address = [
        'firstName' => 'Ada', 'lastName' => 'Obi', 'email' => 'ada@example.test',
        'phone' => '08000000000', 'address' => '1 Test Road',
        'city' => 'Ikeja', 'state' => 'Lagos', 'country' => 'Nigeria',
    ];

    private function pharmacy(string $name, string $state): Store
    {
        return Store::create([
            'owner_id' => $this->makeUser(['role' => 'store_owner'])->id,
            'name' => $name,
            'slug' => 'pc-'.uniqid(),
            'email' => 'shop'.uniqid().'@pharmacy.test',
            'state' => $state,
            'city' => 'Ikeja',
            'status' => 'active',
            'verification_status' => Store::VERIFICATION_APPROVED,
        ]);
    }

    private function basket(User $user, Store ...$stores): void
    {
        foreach ($stores as $store) {
            $product = Product::factory()->create(['store_id' => $store->id, 'price' => 1000]);

            Cart::create([
                'user_id' => $user->id,
                'product_id' => $product->id,
                'quantity' => 1,
                'price' => 1000,
            ]);
        }
    }

    private function checkout(User $user)
    {
        return $this->postJson('/api/v1/orders', [
            'shipping_address' => $this->address,
            'billing_address' => $this->address,
            'payment_method' => 'paystack',
            'delivery_type' => 'home_delivery',
        ], $this->tokenFor($user));
    }

    public function test_each_pharmacy_is_charged_for_its_own_journey(): void
    {
        $this->serviceableZone('Lagos', 'Lagos', 1500);
        $this->serviceableZone('Lagos', 'Enugu', 4000);

        $user = $this->makeUser();

        $this->basket(
            $user,
            $this->pharmacy('Lagos Pharmacy', 'Lagos'),
            $this->pharmacy('Enugu Pharmacy', 'Enugu'),
        );

        $response = $this->checkout($user)->assertCreated();

        /*
         * 1,500 for the Lagos run and 4,000 for the Enugu one.
         *
         * This used to be 4,000: the dearest leg alone, with the other riding
         * along free. Couriers are settled per leg at their own agreed rate, so
         * that undercharge came straight out of the platform on every split
         * order, and grew with distance.
         */
        $this->assertSame(5500.0, round((float) $response->json('data.shipping_amount'), 2));
    }

    public function test_two_items_from_one_pharmacy_are_charged_one_fee(): void
    {
        $this->serviceableZone('Lagos', 'Lagos', 1500);

        $user = $this->makeUser();
        $store = $this->pharmacy('Solo Pharmacy', 'Lagos');

        // Two lines, one shop, one journey.
        $this->basket($user, $store, $store);

        $response = $this->checkout($user)->assertCreated();

        $this->assertSame(1500.0, round((float) $response->json('data.shipping_amount'), 2));
    }

    public function test_two_pharmacies_in_the_same_area_are_one_pickup_run(): void
    {
        $this->serviceableZone('Lagos', 'Lagos', 1500);

        $user = $this->makeUser();

        $this->basket(
            $user,
            $this->pharmacy('First Lagos Pharmacy', 'Lagos'),
            $this->pharmacy('Second Lagos Pharmacy', 'Lagos'),
        );

        $response = $this->checkout($user)->assertCreated();

        /*
         * Two shops in one city are one rider's round: collected together,
         * driven together, charged once.
         *
         * This is safe only because the round is also *dispatched* together —
         * assigning either parcel assigns both, and the pair settles as a
         * single earning. Charging once while letting an operator hand the two
         * parcels to two couriers would pay two agreed rates out of one fee.
         */
        $this->assertSame(1500.0, round((float) $response->json('data.shipping_amount'), 2));

        // One journey, so one parcel per pharmacy but a single charge across
        // them — and both stamped with the same run.
        $order = \App\Models\Order::latest('id')->first();

        $this->assertSame(1, $order->shipments()->distinct()->count('pickup_group'));
        $this->assertSame(2, $order->shipments()->count());
    }

    public function test_an_unrated_origin_falls_back_to_the_destination_rate(): void
    {
        // Only Lagos-to-Lagos is rated. Nothing names Kano as an origin.
        $this->serviceableZone('Lagos', 'Lagos', 1500);

        $user = $this->makeUser();

        $this->basket(
            $user,
            $this->pharmacy('Lagos Pharmacy', 'Lagos'),
            $this->pharmacy('Kano Pharmacy', 'Kano'),
        );

        $response = $this->checkout($user)->assertCreated();

        /*
         * The basket is accepted, and the Kano leg is priced at the Lagos rate:
         * findByRoute falls back to any zone serving the destination when none
         * names the origin.
         *
         * That fallback is deliberate — without it an unrated route prices at
         * zero and the order is refused, which would strand a pharmacy the
         * moment an admin forgot a rate. The cost of it is that a long journey
         * can be sold at a short one's price until the route is rated, and
         * since couriers are now paid out of this fee, that rider is paid the
         * short journey's rate too.
         *
         * Pinned rather than corrected: adding the missing rate is an admin
         * action, and changing the fallback would start refusing baskets that
         * check out today.
         *
         * 3,000 rather than 1,500 because each round is now charged: the Lagos
         * one at its own rate, and the Kano one at the borrowed rate. Two
         * states are unarguably two rounds. The borrowing is the flaw here,
         * not the addition.
         */
        $this->assertSame(3000.0, round((float) $response->json('data.shipping_amount'), 2));
    }

    public function test_a_destination_nothing_serves_is_refused(): void
    {
        // A zone exists, but not to where this customer lives.
        $this->serviceableZone('Enugu', 'Enugu', 1500);

        $user = $this->makeUser();
        $this->basket($user, $this->pharmacy('Enugu Pharmacy', 'Enugu'));

        // Refused rather than shipped at ₦0, which left the platform paying the
        // courier out of its own pocket.
        $this->checkout($user)
            ->assertStatus(422)
            ->assertJsonPath('code', 'shipping_not_covered');
    }
}
