@php
    use App\Support\AppUrl;
    use App\Support\EmailStyle as S;

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

    $statusWords = [
        'confirmed' => 'Confirmed',
        'processing' => 'Being prepared',
        'shipped' => 'On its way',
        'delivered' => 'Delivered',
        'cancelled' => 'Cancelled',
    ];

    // Semantic colour used as a field value, not as decoration — the badge
    // tones in the storefront are text-only for exactly this reason. Anything
    // still in progress stays in the ordinary body colour.
    $statusColour = [
        'delivered' => S::SUCCESS,
        'cancelled' => S::DANGER,
    ][$statusType] ?? S::INK_2;

    // data_get rather than array access: shipping_address is null on some
    // orders, and null['firstName'] is a warning and an empty greeting.
    $customerName = $order->user->name
        ?? data_get($order->shipping_address, 'firstName')
        ?? 'there';

    $orderUrl = $order->user
        ? AppUrl::storefront('/orders/'.$order->id)
        : AppUrl::storefront('/track-order?order='.$order->order_number);

    $action = match ($statusType) {
        'shipped' => 'Track your order',
        'delivered' => $order->user ? 'Leave a review' : null,
        'cancelled' => null,
        default => 'View your order',
    };
@endphp

@extends('emails.layout')

@section('preheader', ($intros[$statusType] ?? 'There is an update on your order.') . ' Order ' . $order->order_number . '.')
@section('heading', $headings[$statusType] ?? 'There is an update on your order')

@section('content')

    <p style="{!! S::LEAD !!}">Hello {{ $customerName }},</p>

    <p style="{!! S::BODY !!}">{{ $intros[$statusType] ?? 'There is an update on your order.' }}</p>

    @php
        /*
         * One code per parcel.
         *
         * An order split across two pharmacies arrives as two deliveries on two
         * different days, and a single code shared between them lets either
         * rider close out the other's delivery. A single-pharmacy order — which
         * is most of them — has one parcel carrying the order's own code, and
         * reads exactly as it always has.
         */
        $codedParcels = $statusType === 'confirmed'
            ? $order->shipments->filter(fn ($parcel) => $parcel->delivery_code)
            : collect();
    @endphp

    @if ($codedParcels->count() > 1)
        <p style="{!! S::BODY !!}">
            Your order comes from more than one pharmacy, so it arrives as separate deliveries
            &mdash; each with its own code.
        </p>
        @foreach ($codedParcels as $parcel)
            @include('emails.partials.well', [
                'label' => 'Delivery code — '.($parcel->store->name ?? 'Parcel '.$loop->iteration),
                'value' => $parcel->delivery_code,
            ])
        @endforeach
        <p style="{!! S::SMALL !!}">
            Read each rider the code for the parcel they are carrying, when it arrives and not
            before. It is how we know the right person received it.
        </p>
    @elseif ($statusType === 'confirmed' && $order->delivery_code)
        @include('emails.partials.well', [
            'label' => 'Delivery code',
            'value' => $order->delivery_code,
        ])
        <p style="{!! S::SMALL !!}">
            Read this code to the rider when your order arrives, and not before. It is how we
            know the right person received it.
        </p>
    @endif

    @if ($statusType === 'cancelled' && $order->total_amount > 0)
        <p style="{!! S::BODY !!}">
            Your refund of &#8358;{{ number_format($order->total_amount, 2) }} is on its way back to you
            and should settle within 5&ndash;7 working days.
        </p>
    @endif

    @include('emails.partials.rows', [
        'rows' => array_filter([
            'Order' => e($order->order_number),
            'Status' => '<span style="color:'.$statusColour.'; font-weight:500;">'.e($statusWords[$statusType] ?? 'Updated').'</span>',
            'Tracking number' => $statusType === 'shipped' && $order->tracking_number
                ? e($order->tracking_number)
                : null,
        ]),
    ])

    {{-- index.css keeps .card for "the few genuinely panel-like surfaces
         (order summary, dispensing table)" — this is the first of those two. --}}
    <p style="{!! S::SUBTITLE !!} margin-top:32px; margin-bottom:14px;">What you ordered</p>

    <table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0">
        <tr>
            <td bgcolor="{{ S::CARD }}" style="background-color:{{ S::CARD }}; border:1px solid {{ S::LINE }}; border-radius:4px; padding:6px 20px 14px 20px;">

                <table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0">
                    @foreach ($order->items as $item)
                        <tr>
                            <td style="padding:13px 0; {{ $loop->first ? '' : 'border-top:1px solid '.S::LINE.';' }}">
                                <table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0">
                                    <tr>
                                        <td class="stack" align="left" valign="top" style="font-family:{!! S::FONT !!}; font-size:15px; line-height:22px; color:{{ S::INK }};">
                                            {{ $item->product_name }}
                                            <div style="{!! S::DATA !!} margin-top:2px;">{{ $item->quantity }} &times; &#8358;{{ number_format($item->price, 2) }}</div>
                                        </td>
                                        <td class="stack-r" align="right" valign="top" style="{!! S::PRICE !!}">
                                            &#8358;{{ number_format($item->price * $item->quantity, 2) }}
                                        </td>
                                    </tr>
                                </table>
                            </td>
                        </tr>
                    @endforeach
                </table>

                @if ($statusType !== 'cancelled')
                    <table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" style="border-top:1px solid {{ S::LINE }}; margin-top:2px;">
                        @foreach ([
                            'Subtotal' => $order->subtotal,
                            'Shipping' => $order->shipping_amount,
                            'Tax' => $order->tax_amount,
                            'Discount' => $order->discount_amount,
                        ] as $label => $amount)
                            @continue($label !== 'Subtotal' && ! ($amount > 0))
                            <tr>
                                <td style="padding:{{ $loop->first ? '13px' : '5px' }} 0 0 0;">
                                    <table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0">
                                        <tr>
                                            <td align="left" style="{!! S::LABEL !!}">{{ $label }}</td>
                                            <td align="right" style="{!! S::DATA !!}">{{ $label === 'Discount' ? '−' : '' }}&#8358;{{ number_format($amount, 2) }}</td>
                                        </tr>
                                    </table>
                                </td>
                            </tr>
                        @endforeach
                        <tr>
                            <td style="padding:13px 0 4px 0;">
                                <table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0">
                                    <tr>
                                        <td align="left" style="font-family:{!! S::FONT !!}; font-size:15px; line-height:22px; font-weight:600; color:{{ S::INK }};">Total</td>
                                        <td align="right" style="{!! S::PRICE !!}">&#8358;{{ number_format($order->total_amount, 2) }}</td>
                                    </tr>
                                </table>
                            </td>
                        </tr>
                    </table>
                @endif

            </td>
        </tr>
    </table>

    @if ($action)
        @include('emails.partials.button', ['url' => $orderUrl, 'label' => $action])
    @endif

@endsection

@section('footnote')
    @if ($statusType === 'cancelled')
        If you did not ask for this cancellation, reply to this email and we will look into it.
    @else
        We send one of these at each step, so you always know where your order has got to.
    @endif
@endsection
