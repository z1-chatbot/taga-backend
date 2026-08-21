@php
    use App\Support\AppUrl;
    use App\Support\EmailStyle as S;

    // Read off the coupon rather than restated. The old copy hardcoded "10%
    // off" in five places, so changing the rate in FirstOrderCouponService
    // would have left five emails advertising the old one.
    $discount = $coupon->type === 'percentage'
        ? rtrim(rtrim(number_format((float) $coupon->value, 2, '.', ''), '0'), '.').'% off'
        : '&#8358;'.number_format((float) $coupon->value, 2).' off';
@endphp

@extends('emails.layout')

@section('preheader', 'A discount code for your next order, as thanks for your first.')
@section('heading', 'Thank you for your first order')

@section('content')

    <p style="{!! S::LEAD !!}">Hello {{ $user->name }},</p>

    <p style="{!! S::BODY !!}">
        Here is {!! $discount !!} your next order, as thanks for shopping with us the first time.
    </p>

    @include('emails.partials.well', [
        'label' => 'Your discount code',
        'value' => $coupon->code,
    ])

    @include('emails.partials.rows', [
        'rows' => array_filter([
            'Discount' => $discount,
            'Spend at least' => $coupon->min_purchase_amount
                ? '&#8358;'.number_format($coupon->min_purchase_amount, 2)
                : null,
            'Up to a maximum of' => $coupon->max_discount_amount
                ? '&#8358;'.number_format($coupon->max_discount_amount, 2)
                : null,
            'Use it before' => $coupon->expires_at?->format('j F Y'),
            'Can be used' => 'Once',
        ]),
    ])

    @include('emails.partials.button', [
        'url' => AppUrl::storefront('/products'),
        'label' => 'Use my code',
    ])

@endsection

@section('footnote')
    Enter the code at checkout. One use per account.
@endsection
