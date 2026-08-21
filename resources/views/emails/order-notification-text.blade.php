@php
    use App\Support\AppUrl;

    $tidy = fn (?string $value) => $value ? ucfirst(str_replace('_', ' ', $value)) : null;

    $headings = [
        'order_placed' => 'Your order is confirmed',
        'new_order' => 'You have a new order',
        'status_update' => 'There is an update on your order',
        'ready_for_pickup' => 'An order is ready for collection',
        'agent_assigned' => 'A rider has been assigned',
        'delivery_assigned' => 'A delivery has been assigned to you',
        'order_picked_up' => 'Your order has been collected',
        'order_collected' => 'Your order has been collected',
        'order_delivered' => 'The order was delivered',
        'delivery_completed' => 'Delivery completed',
        'order_cancelled' => 'This order has been cancelled',
        'delivery_cancelled' => 'This delivery has been cancelled',
        'delivery_issue' => 'There is a problem with a delivery',
    ];

    $statusIntros = [
        'processing' => 'The pharmacy is preparing your order.',
        'ready_for_pickup' => 'Your order is packed and waiting for a rider.',
        'shipped' => 'A rider has been assigned and your order is on its way.',
        'picked_up' => 'A rider has collected your order from the pharmacy.',
        'arrived_at_hub' => 'Your order has reached our hub and is being prepared for the next leg.',
        'in_transit' => 'Your order is travelling between states with our logistics partner.',
        'out_for_delivery' => 'Your order is out for delivery. Someone should be there to receive it.',
        'delivered' => 'Your order has been delivered.',
    ];

    $newStatus = $data['new_status'] ?? null;

    $intro = match ($notificationType) {
        'order_placed' => 'We have your order and the pharmacy is putting it together.',
        'new_order' => 'A customer has placed an order with you. Prepare the items, then mark it ready for collection.',
        'status_update' => $statusIntros[$newStatus] ?? 'Your order has moved to the next stage.',
        'ready_for_pickup' => 'This order is packed and needs a rider assigned to it.',
        'agent_assigned' => 'A rider will collect your order from the pharmacy. It is not out for delivery yet.',
        'delivery_assigned' => 'Collect the items from the pharmacy, then mark the order picked up.',
        'order_picked_up', 'order_collected' => 'The order has left the pharmacy and is on its way.',
        'order_delivered' => 'The order reached the customer.',
        'delivery_completed' => 'Your earnings for this delivery have been added to your balance.',
        'order_cancelled', 'delivery_cancelled' => 'This order will not be going ahead.',
        'delivery_issue' => 'Our team is looking into it and will update you shortly.',
        default => 'There is an update on this order.',
    };

    $cancelled = in_array($notificationType, ['order_cancelled', 'delivery_cancelled'], true);
@endphp
{{ $headings[$notificationType] ?? 'There is an update on your order' }}

Hello {{ $recipientName }},

{{ $intro }}
@if($notificationType === 'status_update')

Was: {{ $tidy($data['old_status'] ?? null) }}
Now: {{ $tidy($newStatus) }}
@elseif($notificationType === 'agent_assigned')

Rider: {{ $data['agent_name'] ?? 'Not given' }}
Phone: {{ $data['agent_phone'] ?? 'Not given' }}
@elseif($notificationType === 'delivery_completed')

You earned: ₦{{ number_format($data['earnings'] ?? 0, 2) }}
@elseif($cancelled)

Reason: {{ $data['reason'] ?? 'No reason was given' }}
@elseif($notificationType === 'delivery_issue')

Problem: {{ $data['issue'] ?? 'Not described' }}
@endif

Order summary
Order: {{ $order->order_number }}
Placed: {{ $order->created_at->format('j F Y, H:i') }}
Status: {{ $tidy($order->status) }}
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
@unless($cancelled)

View this order: {{ AppUrl::storefront('/orders/'.$order->id) }}
@endunless

Questions? Reply to this email, or write to support@taga.ng.

Taga · {{ date('Y') }}
