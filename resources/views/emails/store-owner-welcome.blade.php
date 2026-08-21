@php use App\Support\EmailStyle as S; @endphp
@extends('emails.layout')

@section('preheader', 'Your pharmacy dashboard is ready. Sign in with the details inside.')
@section('heading', 'Your pharmacy dashboard is ready')

@section('content')

    <p style="{!! S::LEAD !!}">Hello {{ $user->name }},</p>

    <p style="{!! S::BODY !!}">
        Your store account has been created. Sign in to add your products, take orders and
        request payouts.
    </p>

    @include('emails.partials.rows', [
        'rows' => [
            'Email' => e($user->email),
            'Temporary password' => e($password),
        ],
    ])

    @include('emails.partials.button', [
        'url' => $loginUrl,
        'label' => 'Sign in to your dashboard',
    ])

    <p style="{!! S::SMALL !!} margin-top:18px;">
        Change this password once you are in — it was generated for you, and anyone who sees
        this email can read it.
    </p>

    {{-- Stated plainly rather than as a feature grid. A pharmacy cannot list
         anything until its licence is approved, and finding that out only when
         the products fail to appear is a poor first day. --}}
    <p style="{!! S::BODY !!} margin-top:22px;">
        Your products go on sale once we have checked your premises licence. You can add them
        before then — nothing is lost while the licence is being reviewed.
    </p>

@endsection

@section('footnote')
    You are receiving this because a pharmacy account was created with this email address.
@endsection
