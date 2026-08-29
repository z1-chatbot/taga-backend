@php
    use App\Support\EmailStyle as S;

    $a = $order->shipping_address ?? [];
    $customer = trim(($a['firstName'] ?? '').' '.($a['lastName'] ?? '')) ?: 'Not given';
    $where = array_filter([$a['address'] ?? null, $a['city'] ?? null, $a['state'] ?? null]);

    /*
     * Only what this courier is actually carrying.
     *
     * An order filled from two pharmacies is two parcels on two journeys, and
     * this listed the whole basket to whoever was assigned either one — so a
     * rider collecting from one shop was given a manifest including another
     * shop's stock, with no way to tell which boxes were theirs.
     */
    $parcel = $shipment ?? null;

    $items = collect($order->items);

    if ($parcel && $parcel->store_id) {
        $items = $items->filter(
            fn ($item) => $item->product && (int) $item->product->store_id === (int) $parcel->store_id
        );
    }

    $lines = $items->map(fn ($item) => [
        'title' => $item->product_snapshot['name'] ?? $item->product_name ?? 'Product',
        'meta' => $item->quantity.' &times; &#8358;'.number_format($item->price, 2),
        'value' => '&#8358;'.number_format($item->price * $item->quantity, 2),
    ])->values()->all();

    // Where to collect it. The email named the delivery address and never the
    // pickup one, so the single fact a courier needs first was the one thing
    // missing — and on a split order "the pharmacy" is ambiguous besides.
    $pharmacy = $parcel?->store;
    $collectFrom = $pharmacy
        ? array_filter([$pharmacy->name, $pharmacy->city, $pharmacy->state])
        : [];

    $parcelCount = $order->shipments->count();
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

    @if ($parcelCount > 1)
        <p style="{!! S::BODY !!}">
            This order ships from {{ $parcelCount }} pharmacies. You are carrying one parcel of it
            &mdash; the items and the fee below are yours alone.
        </p>
    @endif

    @include('emails.partials.rows', [
        'rows' => array_filter([
            'Order' => e($order->order_number),
            'Tracking number' => $trackingNumber ? e($trackingNumber) : null,
            'Collect from' => $collectFrom ? e(implode(', ', $collectFrom)) : null,
            'Customer' => e($customer),
            'Phone' => e($a['phone'] ?? 'Not given'),
            'Deliver to' => $where ? e(implode(', ', $where)) : 'Not given',
            'You earn' => '&#8358;'.number_format($shippingFeeAfterCommission ?? 0, 2),
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
