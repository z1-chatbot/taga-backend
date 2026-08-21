@php
    use App\Support\EmailStyle as S;

    $bank = is_array($payout->bank_details ?? null) ? $payout->bank_details : [];
    $paidOn = $payout->approved_at ?? $payout->processed_at;
@endphp

@extends('emails.layout')

@section('preheader', 'Your payout of ₦' . number_format($payout->amount, 2) . ' has been sent.')
@section('heading', 'Your payout has been sent')

@section('content')

    <p style="{!! S::LEAD !!}">Hello {{ $recipientName }},</p>

    <p style="{!! S::BODY !!}">
        Your payout has been approved and sent. Banks usually take one to three working days
        to show it.
    </p>

    @include('emails.partials.rows', [
        'rows' => array_filter([
            'Request' => '#'.e($payout->id),
            'Amount' => '&#8358;'.number_format($payout->amount, 2),
            'Commission' => ($payout->commission_deducted ?? 0) > 0
                ? '&#8358;'.number_format($payout->commission_deducted, 2)
                : null,
            'Paid to you' => ($payout->commission_deducted ?? 0) > 0
                ? '<span style="color:'.S::SUCCESS.'; font-weight:600;">&#8358;'.number_format($payout->net_amount ?? $payout->amount, 2).'</span>'
                : null,
            'Requested' => $payout->created_at->format('j F Y'),
            'Sent' => $paidOn ? $paidOn->format('j F Y, H:i') : 'Just now',
            'Account' => ($bank['account_number'] ?? null)
                ? e($bank['account_number']).' &middot; '.e($bank['bank_name'] ?? 'Bank not recorded')
                : null,
            'Reference' => $payout->reference_number ?? null,
        ]),
    ])

@endsection

@section('footnote')
    If this does not reach your account within three working days, reply to this email and we
    will trace it.
@endsection
