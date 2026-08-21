@php use App\Support\EmailStyle as S; @endphp
@extends('emails.layout')

@section('preheader', 'Confirm your email address to finish setting up your Taga account.')
@section('heading', 'Confirm your email address')

@section('content')

    <p style="{!! S::LEAD !!}">Hello {{ $user->name }},</p>

    <p style="{!! S::BODY !!}">
        You are almost done. Confirm this address and your Taga account is ready to use.
    </p>

    @include('emails.partials.button', [
        'url' => $verificationUrl,
        'label' => 'Confirm my email address',
    ])

    <p style="{!! S::SMALL !!} margin-top:18px;">
        The link works for 24 hours. If the button does not open, copy this into your browser:<br />
        <span style="color:{{ S::INK_2 }}; word-break:break-all;">{{ $verificationUrl }}</span>
    </p>

@endsection

@section('footnote')
    If you did not create a Taga account, ignore this email — nothing will be set up.
@endsection
