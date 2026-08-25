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

    {{-- No welcome code. This block printed a hardcoded WELCOME10 that was
         never created as a coupon, so it advertised a discount the basket then
         refused. There is no sign-up bonus on this platform. --}}

    @include('emails.partials.button', [
        'url' => \App\Support\AppUrl::storefront('/products'),
        'label' => 'Start shopping',
    ])

@endsection

@section('footnote')
    You are receiving this because an account was created with this email address.
@endsection
