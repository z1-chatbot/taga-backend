@php
    /*
     * Only what this courier is actually carrying — see the HTML version for
     * the reasoning. An order filled from two pharmacies is two parcels on two
     * journeys, and listing the whole basket gave a rider a manifest including
     * another shop's stock.
     */
    $parcel = $shipment ?? null;

    $items = collect($order->items);

    if ($parcel && $parcel->store_id) {
        $items = $items->filter(
            fn ($item) => $item->product && (int) $item->product->store_id === (int) $parcel->store_id
        );
    }

    $pharmacy = $parcel?->store;
    $collectFrom = $pharmacy ? implode(', ', array_filter([$pharmacy->name, $pharmacy->city, $pharmacy->state])) : null;

    $parcelCount = $order->shipments->count();
    $parcelTotal = $items->sum(fn ($item) => (float) $item->price * (int) $item->quantity);
@endphp
NEW DELIVERY ASSIGNMENT - Order #{{ $order->order_number }}

Hello {{ $recipientName }},

A new delivery order has been assigned to your {{ $recipientType === 'company' ? 'logistics company' : 'account' }}.
@if($parcelCount > 1)

This order ships from {{ $parcelCount }} pharmacies. You are carrying one parcel
of it — the items and the fee below are yours alone.
@endif

@if($trackingNumber)
TRACKING NUMBER: {{ $trackingNumber }}
@endif

DELIVERY DETAILS:
- Order Number: #{{ $order->order_number }}
@if($collectFrom)
- Collect from: {{ $collectFrom }}
@endif
- Customer: {{ $order->shipping_address['firstName'] ?? '' }} {{ $order->shipping_address['lastName'] ?? '' }}
- Phone: {{ $order->shipping_address['phone'] ?? 'N/A' }}
- Address: {{ $order->shipping_address['address'] ?? '' }}, {{ $order->shipping_address['city'] ?? '' }}, {{ $order->shipping_address['state'] ?? '' }}
- Shipping Fee (Your Earnings): ₦{{ number_format($shippingFeeAfterCommission ?? 0, 2) }}

ITEMS:
@foreach($items as $item)
- {{ $item->product_snapshot['name'] ?? 'Product' }} x{{ $item->quantity }} — ₦{{ number_format($item->price, 2) }}
@endforeach

{{ $parcelCount > 1 ? 'Value of this parcel' : 'Order Total' }}: ₦{{ number_format($parcelCount > 1 ? $parcelTotal : $order->total_amount, 2) }}

Please log in to your dashboard to view full order details and manage this delivery.

— Taga Delivery Management
