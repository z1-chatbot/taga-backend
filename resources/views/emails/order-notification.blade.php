@php
    use App\Support\AppUrl;
    use App\Support\EmailStyle as S;

    $money = fn ($amount) => '&#8358;'.number_format((float) $amount, 2);
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
        'agent_assigned' => 'A rider will collect your order from the pharmacy. It is not out for delivery yet — we will write again once it is on its way.',
        'delivery_assigned' => 'Collect the items from the pharmacy, then mark the order picked up.',
        'order_picked_up', 'order_collected' => 'The order has left the pharmacy and is on its way.',
        'order_delivered' => 'The order reached the customer.',
        'delivery_completed' => 'Your earnings for this delivery have been added to your balance.',
        'order_cancelled', 'delivery_cancelled' => 'This order will not be going ahead.',
        'delivery_issue' => 'Our team is looking into it and will update you shortly.',
        default => 'There is an update on this order.',
    };

    // Type-specific detail, above the summary every one of these carries.
    $detail = array_filter(match ($notificationType) {
        'status_update' => [
            'Was' => $tidy($data['old_status'] ?? null),
            'Now' => $tidy($newStatus),
        ],
        'agent_assigned' => [
            'Rider' => $data['agent_name'] ?? null,
            'Phone' => $data['agent_phone'] ?? null,
        ],
        'ready_for_pickup' => [
            'Pharmacies' => $data['store_names'] ?? null,
            'Deliver to' => trim(($order->shipping_address['city'] ?? '').', '.($order->shipping_address['state'] ?? ''), ', ') ?: null,
        ],
        'order_picked_up', 'order_collected' => [
            'Rider' => $data['agent_name'] ?? null,
        ],
        'delivery_completed' => [
            'You earned' => isset($data['earnings']) ? $money($data['earnings']) : null,
        ],
        'order_cancelled', 'delivery_cancelled' => [
            'Reason' => $data['reason'] ?? 'No reason was given',
        ],
        'delivery_issue' => [
            'Problem' => $data['issue'] ?? 'Not described',
        ],
        default => [],
    });

    $cancelled = in_array($notificationType, ['order_cancelled', 'delivery_cancelled'], true);

    /*
     * Where "View this order" goes depends on who is reading.
     *
     * This was always the storefront, which is the customer's order page. A
     * pharmacy following it from a "You have a new order" email landed on a
     * page belonging to the customer, which it cannot open — so the one link
     * in the message a vendor most needs was a dead end. Their order lives in
     * the dashboard.
     *
     * (Before that it pointed at config('app.url') — the API's own host, which
     * serves no order page at all.)
     */
    $orderUrl = match ($notificationType) {
        'new_order' => AppUrl::admin('/orders/'.$order->id),
        default => AppUrl::storefront('/orders/'.$order->id),
    };

    /*
     * A vendor's own share, not the whole order.
     *
     * An order can be split across several pharmacies, and each of them gets
     * one of these. Showing the order total told a pharmacy supplying ₦3,000
     * of a ₦12,000 basket that they had a ₦12,000 order — and incidentally
     * disclosed what the other pharmacies were selling into it.
     *
     * `store_subtotal` is passed in when the notification is addressed to a
     * particular store. Without it (the customer's copy, an admin's copy) the
     * order-wide figures are the right ones.
     */
    $forOneStore = $notificationType === 'new_order' && isset($data['store_subtotal']);
@endphp

@extends('emails.layout')

@section('preheader', $intro . ' Order ' . $order->order_number . '.')
@section('heading', $headings[$notificationType] ?? 'There is an update on your order')

@section('content')

    <p style="{!! S::LEAD !!}">Hello {{ $recipientName }},</p>

    <p style="{!! S::BODY !!}">{{ $intro }}</p>

    @if ($detail)
        @include('emails.partials.rows', ['rows' => array_map('e', $detail)])
    @endif

    @if ($notificationType === 'delivery_assigned')
        @php
            $pickups = collect($data['pickup_address'] ?? [])->map(fn ($place) => [
                'title' => $place['store_name'] ?? 'Pharmacy',
                'meta' => e(is_array($place['address'] ?? null) ? implode(', ', $place['address']) : ($place['address'] ?? ''))
                    .(! empty($place['phone']) ? ' &middot; '.e($place['phone']) : ''),
            ])->all();
            $to = $data['delivery_address'] ?? [];
        @endphp

        @if ($pickups)
            <p style="{!! S::SUBTITLE !!} margin-top:30px;">Collect from</p>
            @include('emails.partials.lines', ['lines' => $pickups])
        @endif

        @if ($to)
            @include('emails.partials.rows', [
                'rows' => array_filter([
                    'Deliver to' => e(trim(($to['address'] ?? '').', '.($to['city'] ?? '').', '.($to['state'] ?? ''), ', ')) ?: null,
                    'Phone' => ! empty($to['phone']) ? e($to['phone']) : null,
                ]),
            ])
        @endif
    @endif

    <p style="{!! S::SUBTITLE !!} margin-top:30px;">Order summary</p>

    @include('emails.partials.rows', [
        'rows' => array_filter([
            'Order' => e($order->order_number),
            'Placed' => $order->created_at->format('j F Y, H:i'),
            'Status' => e($tidy($order->status)),
            'Your items' => $forOneStore ? e($data['store_item_count'].' of '.$data['order_item_count']) : null,
            'Subtotal' => $forOneStore ? null : $money($order->subtotal),
            'Shipping' => ! $forOneStore && ($order->shipping_amount ?? 0) > 0 ? $money($order->shipping_amount) : null,
            'Tax' => ! $forOneStore && ($order->tax_amount ?? 0) > 0 ? $money($order->tax_amount) : null,
            'Discount' => ! $forOneStore && ($order->discount_amount ?? 0) > 0 ? '&minus;'.$money($order->discount_amount) : null,
            'Total' => $forOneStore
                ? '<span style="'.S::PRICE.'">'.$money($data['store_subtotal']).'</span>'
                : '<span style="'.S::PRICE.'">'.$money($order->total_amount).'</span>',
        ]),
    ])

    @unless ($cancelled)
        @include('emails.partials.button', [
            'url' => $orderUrl,
            'label' => 'View this order',
        ])
    @endunless

@endsection

@section('footnote')
    @if ($cancelled)
        If you did not expect this cancellation, reply to this email and we will look into it.
    @else
        We send one of these at each stage, so nobody has to chase for an update.
    @endif
@endsection
