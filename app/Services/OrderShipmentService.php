<?php

namespace App\Services;

use App\Models\Order;
use App\Models\OrderShipment;
use App\Models\DeliveryTrackingEvent;
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
            $shipments = [];

            foreach ($itemsByStore as $storeId => $items) {
                $store = $items->first()->product->store;
                $shippingFee = $this->calculateShipmentFee($order, $items->count(), $order->items()->count());
                $estimatedDays = $this->shippingCalculator->getEstimatedDays(
                    $store->state ?? 'Lagos',
                    $order->shipping_address['state'] ?? 'Lagos'
                );

                $shipment = OrderShipment::create([
                    'order_id' => $order->id,
                    'store_id' => $storeId,
                    'tracking_number' => OrderShipment::generateTrackingNumber(),
                    'status' => 'pending',
                    'shipping_fee' => $shippingFee,
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

    protected function calculateShipmentFee($order, $shipmentItemCount, $totalItemCount)
    {
        if ($order->shipping_amount > 0) {
            return ($order->shipping_amount / $totalItemCount) * $shipmentItemCount;
        }
        return 0;
    }
}
