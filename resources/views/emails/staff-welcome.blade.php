@php use App\Support\EmailStyle as S; @endphp
@extends('emails.layout')

@section('preheader', 'Your Taga staff account is ready. Sign in with the details inside.')
@section('heading', 'Your staff account is ready')

@section('content')

    <p style="{!! S::LEAD !!}">Hello {{ $user->name }},</p>

@php
    // A practitioner's account is not a dashboard account. They sign into the
    // same portal but see one screen — the consultation queue — so telling them
    // they have "access to the admin dashboard" would be describing something
    // they will look for and not find.
    $isPractitioner = ($user->roleRelation->name ?? $user->role) === 'practitioner';
    $specialties = $isPractitioner
        ? $user->practitionerTypes->pluck('label')->implode(', ')
        : null;
@endphp

    <p style="{!! S::BODY !!}">
        @if ($isPractitioner)
            You have been set up to answer consultation requests on Taga. Sign in with the
            details below.
        @else
            You have been given access to the Taga admin dashboard. Sign in with the details below.
        @endif
    </p>

    @include('emails.partials.rows', [
        'rows' => array_filter([
            'Email' => e($user->email),
            'Temporary password' => e($password),
            'Role' => e($user->roleRelation->display_name ?? ucfirst($user->role)),
            'You answer for' => $specialties ? e($specialties) : null,
        ]),
    ])

    @include('emails.partials.button', [
        'url' => $loginUrl,
        'label' => $isPractitioner ? 'Open the consultation queue' : 'Sign in to the dashboard',
    ])

    @if ($isPractitioner)
        <p style="{!! S::BODY !!} margin-top:18px;">
            Requests in your specialties reach everyone who covers them, so you will see
            colleagues' requests alongside your own. The first person to reply takes a
            request on, and it then shows as theirs to the rest.
        </p>
    @endif

    <p style="{!! S::SMALL !!} margin-top:18px;">
        Change this password once you are in — it was generated for you, and anyone who sees
        this email can read it. Never share your sign-in details with anyone, including
        colleagues.
    </p>

@endsection

@section('footnote')
    You are receiving this because an administrator created a staff account for you.
@endsection
