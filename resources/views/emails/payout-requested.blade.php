@php
    use App\Support\EmailStyle as S;

    $bank = is_array($payout->bank_details ?? null) ? $payout->bank_details : [];
@endphp

@extends('emails.layout')

@section('preheader', $requesterName . ' has asked to withdraw ₦' . number_format($payout->amount, 2) . '.')
@section('heading', 'A payout is waiting for review')

@section('content')

    <p style="{!! S::LEAD !!}">
        {{ $requesterName }} has asked to withdraw
        &#8358;{{ number_format($payout->amount, 2) }}.
    </p>

    <p style="{!! S::BODY !!}">
        The amount has already left their available balance, so it cannot be claimed twice —
        declining the request puts it straight back.
    </p>

    @include('emails.partials.rows', [
        'rows' => array_filter([
            'Request' => '#'.e($payout->id),
            'Requested by' => e($requesterName).' ('.e(ucwords(str_replace('_', ' ', $requesterType))).')',
            'Email' => e($payout->requester_email ?? $requesterEmail),
            'Amount' => '&#8358;'.number_format($payout->amount, 2),
            'Commission' => ($payout->commission_deducted ?? 0) > 0
                ? '&#8358;'.number_format($payout->commission_deducted, 2)
                : null,
            'To pay out' => ($payout->commission_deducted ?? 0) > 0
                ? '&#8358;'.number_format($payout->net_amount ?? $payout->amount, 2)
                : null,
            'Requested' => $payout->created_at->format('j F Y, H:i'),
            'Bank' => $bank['bank_name'] ?? null,
            'Account number' => $bank['account_number'] ?? null,
            'Account name' => $bank['account_name'] ?? null,
        ]),
    ])

    @include('emails.partials.button', [
        'url' => \App\Support\AppUrl::admin('/payouts'),
        'label' => 'Review this request',
    ])

@endsection

@section('footnote')
    Sent to the admin team whenever a rider or logistics company requests a payout.
@endsection
