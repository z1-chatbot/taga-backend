@php
    use App\Support\EmailStyle as S;

    $a = $order->shipping_address ?? [];
    $customer = trim(($a['firstName'] ?? '').' '.($a['lastName'] ?? '')) ?: 'Not given';
    $where = array_filter([$a['address'] ?? null, $a['city'] ?? null, $a['state'] ?? null]);

    /*
     * Only what this courier is actually carrying — but all of it.
     *
     * An order filled from two pharmacies is two parcels, and this listed the
     * whole basket to whoever was assigned either one, so a rider was given a
     * manifest including another courier's stock with no way to tell which
     * boxes were theirs.
     *
     * Scoped to the *round* rather than the single parcel: shops in the same
     * city are assigned together and paid as one journey, so a rider collecting
     * from two of them needs both on the list.
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

    $lines = $items->map(fn ($item) => [
        'title' => $item->product_snapshot['name'] ?? $item->product_name ?? 'Product',
        'meta' => $item->quantity.' &times; &#8358;'.number_format($item->price, 2),
        'value' => '&#8358;'.number_format($item->price * $item->quantity, 2),
    ])->values()->all();

    // Where to collect it. The email named the delivery address and never the
    // pickup one, so the single fact a courier needs first was the one thing
    // missing — and on a split order "the pharmacy" is ambiguous besides.
    // Every shop on this round. On a single-pharmacy order that is one line,
    // exactly as before.
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

    // How many parcels on this order somebody *else* is carrying. The warning
    // below is about stock that is not theirs, so a round of two counts as one
    // journey, not two.
    $parcelCount = $order->shipments->count();
    $mine = max(1, $run->count());
    $othersCarry = max(0, $parcelCount - $mine);
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

    @if ($othersCarry > 0)
        <p style="{!! S::BODY !!}">
            This order ships from {{ $parcelCount }} pharmacies and another courier is carrying
            the rest of it. The items and the fee below are yours alone.
        </p>
    @endif

    @if ($pickups->count() > 1)
        <p style="{!! S::BODY !!}">
            There are {{ $pickups->count() }} pharmacies to collect from on this run, listed below.
            Both are on your way to the same address, and the fee covers the whole round.
        </p>
    @endif

    @include('emails.partials.rows', [
        'rows' => array_filter([
            'Order' => e($order->order_number),
            'Tracking number' => $trackingNumber ? e($trackingNumber) : null,
            'Collect from' => $pickups->isNotEmpty()
                ? $pickups->map(fn ($p) => e($p))->implode('<br>')
                : null,
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
