DELIVERY UPDATE - Order #{{ $order->order_number }}

Hello {{ $recipientName }},

There is an update on the delivery for order #{{ $order->order_number }}:

STATUS: {{ $statusLabel }}
{{ $statusDescription }}

@if($order->tracking_number)
TRACKING NUMBER: {{ $order->tracking_number }}
@endif

DELIVERY DETAILS:
- Address: {{ $order->shipping_address['address'] ?? '' }}, {{ $order->shipping_address['city'] ?? '' }}, {{ $order->shipping_address['state'] ?? '' }}
- Customer: {{ $order->shipping_address['firstName'] ?? '' }} {{ $order->shipping_address['lastName'] ?? '' }}
- Phone: {{ $order->shipping_address['phone'] ?? 'N/A' }}
@if($order->logisticsCompany)
- Logistics Company: {{ $order->logisticsCompany->name }}
@endif
@if($order->deliveryAgent)
- Delivery Agent: {{ $order->deliveryAgent->name }}
@endif

— Taga Delivery Management
