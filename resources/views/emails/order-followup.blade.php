@php
    use App\Support\AppUrl;
    use App\Support\EmailStyle as S;

    $lines = collect($order->items)->map(fn ($item) => [
        'title' => $item->product_name,
        'meta' => 'Quantity '.$item->quantity,
    ])->all();
@endphp

@extends('emails.layout')

@section('preheader', 'Tell us how order ' . $order->order_number . ' went.')
@section('heading', 'How did your order go?')

@section('content')

    <p style="{!! S::LEAD !!}">Hello {{ $order->user->name ?? 'there' }},</p>

    <p style="{!! S::BODY !!}">
        Order {{ $order->order_number }} was delivered on
        {{ $order->updated_at->format('j F Y') }}. If you have a minute, a short review helps
        the next person choose — and tells the pharmacy how they did.
    </p>

    {{-- The old version promised "leave a review and get a special discount
         code for your next purchase". Nothing in the codebase issues a coupon
         for a review, so it was an offer that could not be honoured. --}}

    @include('emails.partials.lines', ['lines' => $lines])

    @include('emails.partials.button', [
        'url' => AppUrl::storefront('/orders/'.$order->id.'/review'),
        'label' => 'Write a review',
    ])

@endsection

@section('footnote')
    We send this once, a week after delivery.
    <a href="{{ AppUrl::storefront('/unsubscribe') }}" style="color:{{ S::INK_3 }};">Unsubscribe from these</a>.
@endsection
