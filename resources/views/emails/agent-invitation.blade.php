@php use App\Support\EmailStyle as S; @endphp
@extends('emails.layout')

@section('preheader', 'Your rider account is ready. Sign in with the details inside.')
@section('heading', 'You can start taking deliveries')

@section('content')

    <p style="{!! S::LEAD !!}">Hello {{ $agentName }},</p>

    <p style="{!! S::BODY !!}">
        {{ $companyName }} has set you up as a delivery rider on Taga. Sign in with the details
        below to see the deliveries assigned to you.
    </p>

    @include('emails.partials.rows', [
        'rows' => [
            'Email' => e($agentEmail),
            'Temporary password' => e($defaultPassword),
        ],
    ])

    @include('emails.partials.button', [
        'url' => $loginUrl,
        'label' => 'Sign in to the rider portal',
    ])

    <p style="{!! S::SMALL !!} margin-top:18px;">
        Change this password once you are in — it was generated for you, and anyone who sees
        this email can read it. If you were not expecting this, ignore it and let us know.
    </p>

@endsection

@section('footnote')
    You are receiving this because {{ $companyName }} added you as a rider on Taga.
@endsection
