@php use App\Support\EmailStyle as S; @endphp
@extends('emails.layout')

@section('preheader', 'Your payout request was declined and the amount is back in your balance.')
@section('heading', 'Your payout request was declined')

@section('content')

    <p style="{!! S::LEAD !!}">Hello {{ $recipientName }},</p>

    {{-- Said first, because it is the thing being worried about. The money is
         returned by rejectPayout() before this email is sent. --}}
    <p style="{!! S::BODY !!}">
        The &#8358;{{ number_format($payout->amount, 2) }} is already back in your available
        balance. Nothing has been lost, and you can request it again at any time.
    </p>

    @include('emails.partials.rows', [
        'rows' => [
            'Request' => '#'.e($payout->id),
            'Amount' => '&#8358;'.number_format($payout->amount, 2),
            'Status' => '<span style="color:'.S::DANGER.'; font-weight:500;">Declined</span>',
            'Reason' => e($rejectionReason ?: 'No reason was given'),
            'Requested' => $payout->created_at->format('j F Y'),
            'Declined' => now()->format('j F Y, H:i'),
        ],
    ])

    <p style="{!! S::BODY !!} margin-top:20px;">
        If the reason above is something you can correct, put it right and submit a new
        request. If you think this was a mistake, reply to this email.
    </p>

@endsection

@section('footnote')
    Declining a request never removes money from your balance.
@endsection
