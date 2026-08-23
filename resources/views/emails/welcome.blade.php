@php use App\Support\EmailStyle as S; @endphp
@extends('emails.layout')

@section('preheader', 'Your Taga account is ready. Here is how the pharmacy platform works.')
@section('heading', 'Welcome to Taga')

@section('content')

    <p style="{!! S::LEAD !!}">Hello {{ $user->name }},</p>

    {{-- The claims here are the ones the storefront actually makes about
         itself — see the footer in frontend/src/components/Layout.tsx. The old
         copy sold "premium quality gadgets" and "100% authentic gadgets from
         trusted suppliers", inherited from the business this codebase started
         as, on an email from a pharmacy. --}}
    <p style="{!! S::BODY !!}">
        Taga is a platform for licensed Nigerian pharmacies. Every store is verified against
        its premises licence before it can list anything, and a pharmacist reviews your
        prescription before any prescription-only medicine is released.
    </p>

    @if ($couponCode)
        {{-- Only ever shown for a coupon that exists and is still live. This
             block used to print a hardcoded WELCOME10 that was never created. --}}
        @include('emails.partials.well', [
            'label' => 'Your welcome code',
            'value' => $couponCode,
        ])
        <p style="{!! S::SMALL !!}">Enter it at checkout.</p>
    @endif

    @include('emails.partials.button', [
        'url' => \App\Support\AppUrl::storefront('/products'),
        'label' => 'Start shopping',
    ])

@endsection

@section('footnote')
    You are receiving this because an account was created with this email address.
@endsection
