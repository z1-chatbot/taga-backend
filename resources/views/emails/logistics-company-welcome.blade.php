@php use App\Support\EmailStyle as S; @endphp
@extends('emails.layout')

@section('preheader', 'Your logistics account is ready. Sign in with the details inside.')
@section('heading', 'Your logistics account is ready')

@section('content')

    <p style="{!! S::LEAD !!}">Hello {{ $companyName }},</p>

    <p style="{!! S::BODY !!}">
        Your company has been set up on Taga. Sign in to add your riders and start taking
        deliveries.
    </p>

    @include('emails.partials.rows', [
        'rows' => [
            'Email' => e($adminEmail),
            'Temporary password' => e($defaultPassword),
        ],
    ])

    @include('emails.partials.button', [
        'url' => $loginUrl,
        'label' => 'Sign in to the logistics portal',
    ])

    <p style="{!! S::SMALL !!} margin-top:18px;">
        Change this password once you are in — it was generated for you, and anyone who sees
        this email can read it. If you were not expecting this, ignore it and let us know.
    </p>

@endsection

@section('footnote')
    You are receiving this because a logistics account was created for {{ $companyName }} on Taga.
@endsection
