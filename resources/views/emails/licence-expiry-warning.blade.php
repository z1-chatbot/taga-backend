@php
    use App\Support\EmailStyle as S;

    $heading = $expired
        ? $store->name . ' has stopped selling'
        : ($daysRemaining === 0
            ? 'Your pharmacy licence expires today'
            : 'Your pharmacy licence expires in ' . $daysRemaining . ' ' . ($daysRemaining === 1 ? 'day' : 'days'));
@endphp

@extends('emails.layout')

@section('preheader', $expired
    ? 'Your products are off sale until we have approved a current licence.'
    : 'Send your renewal before it expires and nothing will be interrupted.')

@section('heading', $heading)

@section('content')

    @if ($expired)

        <p style="{!! S::LEAD !!}">
            The pharmacy licence we hold for your store expired on
            {{ $store->pharmacy_license_expiry->format('j F Y') }}, so your products are no
            longer on sale.
        </p>

        <p style="{!! S::BODY !!}">
            Nothing has been deleted. Your shop, your products and your order history are all
            exactly as you left them — they go back on sale as soon as we have approved a
            current licence.
        </p>

    @else

        <p style="{!! S::LEAD !!}">
            The licence we hold for {{ $store->name }} expires on
            {{ $store->pharmacy_license_expiry->format('j F Y') }}.
        </p>

        <p style="{!! S::BODY !!}">
            When it expires your products stop being sold on Taga until we have approved a
            current one. Send us your renewal before then and nothing will be interrupted.
        </p>

    @endif

    @include('emails.partials.rows', [
        'rows' => [
            'Pharmacy' => e($store->name),
            'Licence expires' => $store->pharmacy_license_expiry->format('j F Y'),
            'Status' => $expired
                ? '<span style="color:'.S::DANGER.'; font-weight:500;">Expired — listings are down</span>'
                : '<span style="color:'.S::WARN.'; font-weight:500;">Renewal needed</span>',
        ],
    ])

    @include('emails.partials.button', [
        'url' => $dashboardUrl,
        'label' => 'Submit your renewed licence',
    ])

@endsection

@section('footnote')
    If you have already renewed, reply to this email and we will look into it.
@endsection
