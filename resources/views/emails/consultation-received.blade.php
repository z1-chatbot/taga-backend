@php use App\Support\EmailStyle as S; @endphp
@extends('emails.layout')

@section('preheader', 'Your reference is ' . $consultation->reference . '. We will come back to you shortly.')

@section('heading', 'We have your request')

@section('content')

    <p style="{!! S::LEAD !!}">
        Thank you, {{ $consultation->name }}. Your request to speak to a
        {{ strtolower($practitioner) }} is with our team.
    </p>

    @include('emails.partials.well', [
        'label' => 'Your reference',
        'value' => $consultation->reference,
    ])

    <p style="{!! S::BODY !!}">
        Quote it if you get in touch about this request.
    </p>

    @include('emails.partials.rows', [
        'rows' => array_filter([
            'You asked to see' => e($practitioner),
            'Contact you by' => e(ucfirst($consultation->preferred_contact)),
            'Best time' => $consultation->preferred_time ? e($consultation->preferred_time) : null,
            'Raised on' => $consultation->created_at?->format('j F Y, g:ia'),
        ]),
    ])

    <p style="{!! S::SUBTITLE !!} margin-top:30px;">What happens next</p>

    <p style="{!! S::BODY !!}">
        Someone will read your request and reply with the next step — usually a time
        to talk, or a question or two first. Replies arrive by email and are also
        kept on this request, so the whole conversation stays in one place.
    </p>

    @include('emails.partials.button', [
        'url' => $trackUrl,
        'label' => 'View your request',
    ])

    <p style="{!! S::SMALL !!} margin-top:18px;">
        This is not a medical emergency service. If this is urgent, please go to your
        nearest hospital or call your local emergency number.
    </p>

@endsection

@section('footnote')
    You can reply to this email and it will reach the same team.
@endsection
