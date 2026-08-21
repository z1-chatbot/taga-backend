NEW DELIVERY ASSIGNMENT - Order #{{ $order->order_number }}

Hello {{ $recipientName }},

A new delivery order has been assigned to your {{ $recipientType === 'company' ? 'logistics company' : 'account' }}.

@if($trackingNumber)
TRACKING NUMBER: {{ $trackingNumber }}
@endif

DELIVERY DETAILS:
- Order Number: #{{ $order->order_number }}
- Customer: {{ $order->shipping_address['firstName'] ?? '' }} {{ $order->shipping_address['lastName'] ?? '' }}
- Phone: {{ $order->shipping_address['phone'] ?? 'N/A' }}
- Address: {{ $order->shipping_address['address'] ?? '' }}, {{ $order->shipping_address['city'] ?? '' }}, {{ $order->shipping_address['state'] ?? '' }}
- Shipping Fee (Your Earnings): ₦{{ number_format($shippingFeeAfterCommission ?? 0, 2) }}
@if($order->is_pay_on_delivery)
- Payment: Cash on Delivery — ₦{{ number_format($order->total_amount, 2) }}
@endif

ITEMS:
@foreach($order->items as $item)
- {{ $item->product_snapshot['name'] ?? 'Product' }} x{{ $item->quantity }} — ₦{{ number_format($item->price, 2) }}
@endforeach

Order Total: ₦{{ number_format($order->total_amount, 2) }}

Please log in to your dashboard to view full order details and manage this delivery.

— Taga Delivery Management
