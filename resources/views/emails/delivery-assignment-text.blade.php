@php
    /*
     * Only what this courier is actually carrying, and all of it — see the HTML
     * version for the reasoning. Scoped to the whole pickup round rather than
     * one parcel: shops in the same city are assigned together and paid as one
     * journey, so a rider collecting from two of them needs both on the list.
     */
    $parcel = $shipment ?? null;
    $run = collect($runShipments ?? []);

    $storeIds = $run->pluck('store_id')->filter()->map(fn ($id) => (int) $id)->all();

    $items = collect($order->items);

    if ($storeIds) {
        $items = $items->filter(
            fn ($item) => $item->product && in_array((int) $item->product->store_id, $storeIds, true)
        );
    }

    $pickups = $run
        ->map(fn ($s) => $s->store)
        ->filter()
        ->unique('id')
        ->map(fn ($store) => implode(', ', array_filter([$store->name, $store->city, $store->state])))
        ->values();

    if ($pickups->isEmpty() && $parcel?->store) {
        $store = $parcel->store;
        $pickups = collect([implode(', ', array_filter([$store->name, $store->city, $store->state]))]);
    }

    $parcelCount = $order->shipments->count();
    $mine = max(1, $run->count());
    $othersCarry = max(0, $parcelCount - $mine);
    $parcelTotal = $items->sum(fn ($item) => (float) $item->price * (int) $item->quantity);
@endphp
NEW DELIVERY ASSIGNMENT - Order #{{ $order->order_number }}

Hello {{ $recipientName }},

A new delivery order has been assigned to your {{ $recipientType === 'company' ? 'logistics company' : 'account' }}.
@if($othersCarry > 0)

This order ships from {{ $parcelCount }} pharmacies and another courier is
carrying the rest of it. The items and the fee below are yours alone.
@endif
@if($pickups->count() > 1)

There are {{ $pickups->count() }} pharmacies to collect from on this run, listed
below. The fee covers the whole round.
@endif

@if($trackingNumber)
TRACKING NUMBER: {{ $trackingNumber }}
@endif

DELIVERY DETAILS:
- Order Number: #{{ $order->order_number }}
@foreach($pickups as $pickup)
- Collect from: {{ $pickup }}
@endforeach
- Customer: {{ $order->shipping_address['firstName'] ?? '' }} {{ $order->shipping_address['lastName'] ?? '' }}
- Phone: {{ $order->shipping_address['phone'] ?? 'N/A' }}
- Address: {{ $order->shipping_address['address'] ?? '' }}, {{ $order->shipping_address['city'] ?? '' }}, {{ $order->shipping_address['state'] ?? '' }}
- Shipping Fee (Your Earnings): ₦{{ number_format($shippingFeeAfterCommission ?? 0, 2) }}

ITEMS:
@foreach($items as $item)
- {{ $item->product_snapshot['name'] ?? 'Product' }} x{{ $item->quantity }} — ₦{{ number_format($item->price, 2) }}
@endforeach

{{ $othersCarry > 0 ? 'Value of your parcels' : 'Order Total' }}: ₦{{ number_format($othersCarry > 0 ? $parcelTotal : $order->total_amount, 2) }}

Please log in to your dashboard to view full order details and manage this delivery.

— Taga Delivery Management
