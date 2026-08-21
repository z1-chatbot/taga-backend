@php
    use App\Support\AppUrl;

    $headings = [
        'confirmed' => 'Your order is confirmed',
        'processing' => 'We are preparing your order',
        'shipped' => 'Your order is on its way',
        'delivered' => 'Your order has arrived',
        'cancelled' => 'Your order has been cancelled',
    ];

    $intros = [
        'confirmed' => 'We have your payment and the pharmacy is putting your order together.',
        'processing' => 'The pharmacy is packing your items now. We will write again the moment they are collected.',
        'shipped' => 'Your order has left the pharmacy and is with a rider.',
        'delivered' => 'Your order was handed over. We hope everything is as it should be.',
        'cancelled' => 'This order will not be going ahead.',
    ];

    $customerName = $order->user->name
        ?? data_get($order->shipping_address, 'firstName')
        ?? 'there';

    $orderUrl = $order->user
        ? AppUrl::storefront('/orders/'.$order->id)
        : AppUrl::storefront('/track-order?order='.$order->order_number);
@endphp
{{ $headings[$statusType] ?? 'There is an update on your order' }}
Order {{ $order->order_number }}

Hello {{ $customerName }},

{{ $intros[$statusType] ?? 'There is an update on your order.' }}
@if($statusType === 'confirmed' && $order->delivery_code)

Delivery code: {{ $order->delivery_code }}

Read this code to the rider when your order arrives, and not before. It is how
we know the right person received it.
@endif
@if($statusType === 'shipped' && $order->tracking_number)

Tracking number: {{ $order->tracking_number }}
@endif
@if($statusType === 'cancelled' && $order->total_amount > 0)

Your refund of ₦{{ number_format($order->total_amount, 2) }} is on its way back to you and should
settle within 5-7 working days.
@endif

What you ordered
@foreach($order->items as $item)
- {{ $item->product_name }} — {{ $item->quantity }} × ₦{{ number_format($item->price, 2) }} = ₦{{ number_format($item->price * $item->quantity, 2) }}
@endforeach
@if($statusType !== 'cancelled')

Subtotal: ₦{{ number_format($order->subtotal, 2) }}
@if(($order->shipping_amount ?? 0) > 0)
Shipping: ₦{{ number_format($order->shipping_amount, 2) }}
@endif
@if(($order->tax_amount ?? 0) > 0)
Tax: ₦{{ number_format($order->tax_amount, 2) }}
@endif
@if(($order->discount_amount ?? 0) > 0)
Discount: -₦{{ number_format($order->discount_amount, 2) }}
@endif
Total: ₦{{ number_format($order->total_amount, 2) }}
@endif
@if($statusType === 'shipped')

Track your order: {{ $orderUrl }}
@elseif($statusType === 'delivered' && $order->user)

Leave a review: {{ $orderUrl }}
@elseif($statusType !== 'cancelled')

View your order: {{ $orderUrl }}
@endif

Questions? Reply to this email, or write to support@taga.ng.

Taga · {{ date('Y') }}
