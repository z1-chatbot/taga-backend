@php
    use App\Support\EmailStyle as S;

    $a = $order->shipping_address ?? [];
    $customer = trim(($a['firstName'] ?? '').' '.($a['lastName'] ?? '')) ?: 'Not given';
    $where = array_filter([$a['address'] ?? null, $a['city'] ?? null, $a['state'] ?? null]);
@endphp

@extends('emails.layout')

@section('preheader', 'Order ' . $order->order_number . ': ' . $statusLabel . '.')
@section('heading', 'There is an update on a delivery')

@section('content')

    <p style="{!! S::LEAD !!}">Hello {{ $recipientName }},</p>

    <p style="{!! S::BODY !!}">{{ $statusDescription }}</p>

    @include('emails.partials.rows', [
        'rows' => array_filter([
            'Order' => e($order->order_number),
            'Status' => e($statusLabel),
            'Tracking number' => $order->tracking_number ? e($order->tracking_number) : null,
            'Customer' => e($customer),
            'Phone' => e($a['phone'] ?? 'Not given'),
            'Deliver to' => $where ? e(implode(', ', $where)) : 'Not given',
            'Logistics company' => $order->logisticsCompany?->name ? e($order->logisticsCompany->name) : null,
            'Rider' => $order->deliveryAgent?->name ? e($order->deliveryAgent->name) : null,
        ]),
    ])

    @include('emails.partials.button', [
        'url' => \App\Support\AppUrl::agentPortal('/deliveries'),
        'label' => 'Open this delivery',
    ])

@endsection

@section('footnote')
    You are receiving this because this delivery is assigned to you.
@endsection
