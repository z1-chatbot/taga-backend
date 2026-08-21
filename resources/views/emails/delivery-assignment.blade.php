@php
    use App\Support\EmailStyle as S;

    $a = $order->shipping_address ?? [];
    $customer = trim(($a['firstName'] ?? '').' '.($a['lastName'] ?? '')) ?: 'Not given';
    $where = array_filter([$a['address'] ?? null, $a['city'] ?? null, $a['state'] ?? null]);

    $lines = collect($order->items)->map(fn ($item) => [
        'title' => $item->product_snapshot['name'] ?? $item->product_name ?? 'Product',
        'meta' => $item->quantity.' &times; &#8358;'.number_format($item->price, 2),
        'value' => '&#8358;'.number_format($item->price * $item->quantity, 2),
    ])->all();
@endphp

@extends('emails.layout')

@section('preheader', 'Order ' . $order->order_number . ' has been assigned to you for delivery.')
@section('heading', 'A delivery has been assigned to you')

@section('content')

    <p style="{!! S::LEAD !!}">Hello {{ $recipientName }},</p>

    <p style="{!! S::BODY !!}">
        Order {{ $order->order_number }} is ready to collect and has been assigned to your
        {{ $recipientType === 'company' ? 'company' : 'account' }}.
    </p>

    @include('emails.partials.rows', [
        'rows' => array_filter([
            'Order' => e($order->order_number),
            'Tracking number' => $trackingNumber ? e($trackingNumber) : null,
            'Customer' => e($customer),
            'Phone' => e($a['phone'] ?? 'Not given'),
            'Deliver to' => $where ? e(implode(', ', $where)) : 'Not given',
            'You earn' => '&#8358;'.number_format($shippingFeeAfterCommission ?? 0, 2),
            'Collect on delivery' => $order->is_pay_on_delivery
                ? '&#8358;'.number_format($order->total_amount, 2)
                : null,
        ]),
    ])

    <p style="{!! S::SUBTITLE !!} margin-top:30px;">What you are carrying</p>

    @include('emails.partials.lines', ['lines' => $lines])

    @include('emails.partials.button', [
        'url' => \App\Support\AppUrl::agentPortal('/deliveries'),
        'label' => 'Open this delivery',
    ])

@endsection

@section('footnote')
    Sign in to the portal for the full order and to update its progress.
@endsection
