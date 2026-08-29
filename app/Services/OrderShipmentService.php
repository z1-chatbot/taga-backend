<?php

namespace App\Services;

use App\Models\Order;
use App\Models\OrderShipment;
use App\Models\DeliveryTrackingEvent;
use App\Models\ShippingZone;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;

class OrderShipmentService
{
    protected $shippingCalculator;

    public function __construct(ShippingCalculator $shippingCalculator)
    {
        $this->shippingCalculator = $shippingCalculator;
    }

    public function createShipmentsForOrder(Order $order)
    {
        DB::beginTransaction();
        try {
            $itemsByStore = $order->items()->with('product.store')->get()->groupBy('product.store_id');

            // Worked out for the whole basket at once: the split depends on how
            // the legs compare with each other, which no single leg can know.
            $fees = $this->allocateShippingFee($order, $itemsByStore);

            $shipments = [];

            foreach ($itemsByStore as $storeId => $items) {
                $store = $items->first()->product->store;
                $estimatedDays = $this->shippingCalculator->getEstimatedDays(
                    $store->state ?? 'Lagos',
                    $order->shipping_address['state'] ?? 'Lagos'
                );

                $shipment = OrderShipment::create([
                    'order_id' => $order->id,
                    'store_id' => $storeId,
                    // Which round this parcel is collected on. Stamped now
                    // rather than derived later: a pharmacy that moves city
                    // must not re-group parcels already in flight.
                    'pickup_group' => OrderShipment::pickupGroupFor($store),
                    'tracking_number' => OrderShipment::generateTrackingNumber(),
                    'status' => 'pending',
                    'shipping_fee' => $fees[$storeId] ?? 0,
                    'items' => $items->pluck('id')->toArray(),
                    'estimated_delivery_days' => $estimatedDays
                ]);

                DeliveryTrackingEvent::create([
                    'order_id' => $order->id,
                    'status' => DeliveryTrackingEvent::STATUS_ORDER_CONFIRMED,
                    'description' => 'Order confirmed',
                    'metadata' => ['shipment_id' => $shipment->id],
                    'created_by_type' => 'system'
                ]);

                $shipments[] = $shipment;
            }

            DB::commit();
            return $shipments;
        } catch (\Exception $e) {
            DB::rollBack();
            throw $e;
        }
    }

    /**
     * Divide the order's shipping fee between the pharmacies shipping it.
     *
     * By route, not by item count. The fee used to be split as
     * `(shipping / total items) * items on this parcel`, which prices a
     * delivery by how many boxes are in it — so a pharmacy sending one item
     * from Enugu to Lagos was allocated a tenth of the fee, and the nine-item
     * parcel travelling across the same city took the rest. Item count is not
     * what makes a delivery expensive; distance is, which is why shipping zones
     * exist at all.
     *
     * The customer is charged once per *pickup run* — see
     * OrderController::calculateMultiStoreShipping — so the division happens in
     * two steps. The order's fee is shared between the runs in proportion to
     * what each run's journey costs, and each run's share is then divided
     * evenly between the pharmacies collected on it, because they sit in the
     * same city and their journeys are the same journey.
     *
     * Weighting per shop rather than per run would be wrong now: two shops in
     * one city would pull twice the weight of a single shop the same distance
     * away, and each run's parcels would stop adding up to what that run was
     * actually charged.
     *
     * @param  Collection<int|string, Collection>  $itemsByStore
     * @return array<int|string, float>  fee per store id
     */
    private function allocateShippingFee(Order $order, $itemsByStore): array
    {
        $total = round((float) ($order->shipping_amount ?? 0), 2);
        $storeIds = $itemsByStore->keys()->all();

        if ($total <= 0 || count($storeIds) === 0) {
            return array_fill_keys($storeIds, 0.0);
        }

        // One pharmacy takes the whole fee — the common case, and the only
        // sensible answer for it.
        if (count($storeIds) === 1) {
            return [$storeIds[0] => $total];
        }

        $address = $order->shipping_address ?? [];
        $destination = $address['state'] ?? null;
        $city = $address['city'] ?? null;
        $postalCode = $address['postalCode'] ?? $address['postal_code'] ?? null;

        // Which shops travel together, and what each of those journeys costs.
        $runOf = [];
        $runCosts = [];

        foreach ($itemsByStore as $storeId => $items) {
            $store = $items->first()->product->store ?? null;
            $origin = $store->state ?? null;

            // A shop with no city is its own run, matching how it was priced.
            $run = OrderShipment::pickupGroupFor($store) ?? 'store:'.$storeId;
            $runOf[$storeId] = $run;

            if (array_key_exists($run, $runCosts)) {
                continue;
            }

            $zone = $origin && $destination
                ? ShippingZone::findByRoute($origin, $destination, $city, $postalCode)
                : null;

            $runCosts[$run] = $zone ? (float) $zone->calculateShippingFee() : 0.0;
        }

        // No route priced for any leg — nothing to weight by, so share it
        // evenly. Still better than item count, which would be actively wrong.
        if (array_sum($runCosts) <= 0) {
            $runCosts = array_fill_keys(array_keys($runCosts), 1.0);
        }

        $weightTotal = array_sum($runCosts);
        $shopsPerRun = array_count_values($runOf);
        $fees = [];
        $allocated = 0.0;

        foreach ($storeIds as $storeId) {
            $run = $runOf[$storeId];

            // The run's share of the order, divided between the shops on it.
            $fees[$storeId] = round(
                ($total * ($runCosts[$run] / $weightTotal)) / $shopsPerRun[$run],
                2
            );
            $allocated += $fees[$storeId];
        }

        /*
         * Rounding leaves a few kobo unaccounted for either way. It goes to the
         * dearest leg, so the parts always add back to the fee the customer
         * paid — couriers are paid out of these shares, and a split that does
         * not reconcile either overpays or underpays the platform by a little
         * on every split order.
         */
        $remainder = round($total - $allocated, 2);

        if (abs($remainder) >= 0.01) {
            $dearestRun = array_search(max($runCosts), $runCosts, true);
            $dearest = array_search($dearestRun, $runOf, true);
            $fees[$dearest] = round($fees[$dearest] + $remainder, 2);
        }

        return $fees;
    }
}
