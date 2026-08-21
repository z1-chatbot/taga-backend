@php use App\Support\EmailStyle as S; @endphp
@extends('emails.layout')

@section('preheader', 'Choose a new password for your Taga account.')
@section('heading', 'Reset your password')

@section('content')

    <p style="{!! S::LEAD !!}">Hello {{ $user->name }},</p>

    <p style="{!! S::BODY !!}">
        We received a request to reset the password on your Taga account. Choose a new one here.
    </p>

    @include('emails.partials.button', [
        'url' => $resetUrl,
        'label' => 'Choose a new password',
    ])

    <p style="{!! S::SMALL !!} margin-top:18px;">
        The link expires in {{ $expiresInMinutes }} minutes and works once. If the button does
        not open, copy this into your browser:<br />
        <span style="color:{{ S::INK_2 }}; word-break:break-all;">{{ $resetUrl }}</span>
    </p>

@endsection

@section('footnote')
    If you did not ask for this, ignore the email — your password will not change.
@endsection
