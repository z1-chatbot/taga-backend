<?php

namespace Tests\Feature;

use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Product;
use App\Models\Store;
use App\Services\OrderShipmentService;
use Tests\TestCase;

/**
 * How one shipping fee is divided between the pharmacies shipping an order.
 *
 * The customer is charged for every leg, so the order's shipping total is what
 * there is to divide, and couriers are paid out of the shares — the split
 * decides what each rider earns.
 *
 * Splitting by route makes the shares land on each parcel's own price now that
 * the total is the sum of those prices. That is the arithmetic working out, not
 * a coincidence to lean on: an order whose fee was overridden by hand still has
 * to divide sensibly, which is why the proportional split stays.
 *
 * It used to be `(shipping / total items) * items on this parcel`: a delivery
 * priced by how many boxes are in it. A pharmacy sending one item from Enugu to
 * Lagos was allocated a tenth of the fee while a nine-item parcel crossing the
 * same city took the rest. Item count is not what makes a delivery expensive.
 */
class SplitShippingFeeTest extends TestCase
{
    private array $address = [
        'firstName' => 'Ada', 'lastName' => 'Obi', 'email' => 'ada@example.test',
        'phone' => '08000000000', 'address' => '1 Test Road',
        'city' => 'Ikeja', 'state' => 'Lagos', 'country' => 'Nigeria',
    ];

    private function order(float $shipping = 4000): Order
    {
        return Order::create([
            'user_id' => $this->makeUser()->id,
            'order_number' => 'TEST-SF-'.uniqid(),
            'status' => Order::STATUS_PROCESSING,
            'payment_status' => Order::PAYMENT_PAID,
            'subtotal' => 12000,
            'shipping_amount' => $shipping,
            'total_amount' => 12000 + $shipping,
            'shipping_address' => $this->address,
            'billing_address' => $this->address,
        ]);
    }

    private function pharmacy(string $state, string $city): Store
    {
        return Store::create([
            'owner_id' => $this->makeUser(['role' => 'store_owner'])->id,
            'name' => $state.' Pharmacy',
            'slug' => 'sf-'.uniqid(),
            'email' => 'shop'.uniqid().'@pharmacy.test',
            'state' => $state,
            'city' => $city,
            'status' => 'active',
            'verification_status' => Store::VERIFICATION_APPROVED,
        ]);
    }

    /** Adds `$quantity` lines from one pharmacy. */
    private function lines(Order $order, Store $store, int $quantity): void
    {
        foreach (range(1, $quantity) as $ignored) {
            $product = Product::factory()->create(['store_id' => $store->id]);

            OrderItem::create([
                'order_id' => $order->id,
                'product_id' => $product->id,
                'quantity' => 1,
                'price' => 1000,
                'total' => 1000,
                'product_snapshot' => ['name' => $product->name, 'sku' => 'SF-'.uniqid()],
            ]);
        }
    }

    /** Every parcel's fee, smallest first — for baskets whose states collide. */
    private function amounts(Order $order): array
    {
        return collect($this->shipments($order, byState: false))->sort()->values()->all();
    }

    /**
     * Fees per parcel. Keyed by pharmacy state where the test needs to name a
     * leg; keyed by shipment id otherwise, since two pharmacies in one state
     * would collapse into a single entry and quietly hide a parcel.
     */
    private function shipments(Order $order, bool $byState = true): array
    {
        (new OrderShipmentService(app(\App\Services\ShippingCalculator::class)))
            ->createShipmentsForOrder($order);

        return $order->fresh()->shipments()->with('store')->get()
            ->mapWithKeys(fn ($shipment) => [
                ($byState ? $shipment->store->state : $shipment->id) => round((float) $shipment->shipping_fee, 2),
            ])->all();
    }

    public function test_one_pharmacy_is_charged_the_whole_fee_once(): void
    {
        $order = $this->order(4000);
        $this->lines($order, $this->pharmacy('Lagos', 'Ikeja'), 5);

        $fees = $this->shipments($order);

        // Five items, one journey, one fee. Not five.
        $this->assertSame(['Lagos' => 4000.0], $fees);
    }

    public function test_two_pharmacies_on_the_same_route_split_it_evenly(): void
    {
        $this->serviceableZone('Lagos', 'Lagos', 4000);

        $order = $this->order(4000);

        // Nine items from one, one from the other. By item count this was a
        // 90/10 split for two identical journeys across the same city.
        $this->lines($order, $this->pharmacy('Lagos', 'Ikeja'), 9);
        $this->lines($order, $this->pharmacy('Lagos', 'Yaba'), 1);

        $this->assertSame([2000.0, 2000.0], $this->amounts($order));
    }

    public function test_the_longer_journey_takes_the_larger_share(): void
    {
        // Enugu to Lagos costs three times what Lagos to Lagos does.
        $this->serviceableZone('Lagos', 'Lagos', 1000);
        $this->serviceableZone('Lagos', 'Enugu', 3000);

        $order = $this->order(3000);

        // The distant pharmacy sends one item; the local one sends nine. Item
        // count would have paid the long-haul rider ₦300.
        $this->lines($order, $this->pharmacy('Enugu', 'Enugu'), 1);
        $this->lines($order, $this->pharmacy('Lagos', 'Ikeja'), 9);

        $fees = $this->shipments($order);

        $this->assertSame(2250.0, $fees['Enugu']);
        $this->assertSame(750.0, $fees['Lagos']);

        // And the shares still add back to what the customer actually paid —
        // couriers are paid out of these, so a split that does not reconcile
        // quietly over- or under-pays on every split order.
        $this->assertSame(3000.0, array_sum($fees));
    }

    public function test_the_shares_always_add_back_to_the_fee_charged(): void
    {
        // A fee that does not divide cleanly: 1000 / 3 leaves a kobo over.
        $this->serviceableZone('Lagos', 'Lagos', 1000);
        $this->serviceableZone('Lagos', 'Enugu', 1000);
        $this->serviceableZone('Lagos', 'FCT', 1000);

        $order = $this->order(1000);

        $this->lines($order, $this->pharmacy('Lagos', 'Ikeja'), 1);
        $this->lines($order, $this->pharmacy('Enugu', 'Enugu'), 1);
        $this->lines($order, $this->pharmacy('FCT', 'Garki'), 1);

        $this->assertSame(1000.0, array_sum($this->shipments($order)));
    }

    public function test_an_unpriced_route_still_splits_rather_than_stranding_a_leg(): void
    {
        // No zones at all. Weighting by route is impossible, so it shares out
        // evenly — anything else leaves a rider carrying a parcel for nothing.
        $order = $this->order(2000);

        $this->lines($order, $this->pharmacy('Lagos', 'Ikeja'), 7);
        $this->lines($order, $this->pharmacy('Enugu', 'Enugu'), 1);

        $this->assertSame([1000.0, 1000.0], $this->amounts($order));
    }

    public function test_free_shipping_leaves_every_parcel_at_zero(): void
    {
        $order = $this->order(0);

        $this->lines($order, $this->pharmacy('Lagos', 'Ikeja'), 2);
        $this->lines($order, $this->pharmacy('Enugu', 'Enugu'), 2);

        $this->assertSame([0.0, 0.0], $this->amounts($order));
    }
}
