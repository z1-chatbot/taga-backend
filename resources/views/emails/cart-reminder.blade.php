@php
    use App\Support\AppUrl;
    use App\Support\EmailStyle as S;

    $headings = [
        '1h' => 'You left something in your basket',
        '24h' => 'Your basket is still here',
        '3d' => 'Still want these?',
    ];

    $intros = [
        '1h' => 'These are still in your basket if you would like to finish up.',
        '24h' => 'Your basket is as you left it yesterday. Prices and stock can change, so it is worth checking before you order.',
        '3d' => 'This is the last reminder we will send about this basket.',
    ];

    $lines = collect($cartItems)->map(fn ($item) => [
        'title' => $item['name'],
        'meta' => $item['quantity'].' &times; &#8358;'.number_format($item['price'], 2),
        'value' => '&#8358;'.number_format($item['price'] * $item['quantity'], 2),
    ])->all();
@endphp

@extends('emails.layout')

@section('preheader', $intros[$reminderType] ?? 'Your basket is still waiting.')
@section('heading', $headings[$reminderType] ?? 'Your basket is still here')

@section('content')

    <p style="{!! S::LEAD !!}">Hello {{ $user->name }},</p>

    <p style="{!! S::BODY !!}">{{ $intros[$reminderType] ?? 'Your basket is still waiting.' }}</p>

    {{-- The old version closed with "Why shop with us? Premium quality phones
         and gadgets / 100% satisfaction guarantee" — and the HTML half said
         wigs while the plain text said phones. Two previous businesses in one
         email, and a guarantee nobody had agreed to offer, on a shop selling
         medicine. A basket reminder only needs to show the basket. --}}

    @include('emails.partials.lines', ['lines' => $lines])

    <table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" style="margin-top:14px;">
        <tr>
            <td align="left" style="{!! S::LABEL !!}">Basket total</td>
            <td align="right" style="{!! S::PRICE !!}">&#8358;{{ number_format($cartTotal, 2) }}</td>
        </tr>
    </table>

    @include('emails.partials.button', [
        'url' => AppUrl::storefront('/cart'),
        'label' => 'Go to my basket',
    ])

@endsection

@section('footnote')
    Prescription-only items cannot be dispensed until a pharmacist has reviewed your prescription.
    <a href="{{ AppUrl::storefront('/unsubscribe') }}" style="color:{{ S::INK_3 }};">Unsubscribe from these</a>.
@endsection
