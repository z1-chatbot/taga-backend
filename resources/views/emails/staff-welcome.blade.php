@php use App\Support\EmailStyle as S; @endphp
@extends('emails.layout')

@section('preheader', 'Your Taga staff account is ready. Sign in with the details inside.')
@section('heading', 'Your staff account is ready')

@section('content')

    <p style="{!! S::LEAD !!}">Hello {{ $user->name }},</p>

    <p style="{!! S::BODY !!}">
        You have been given access to the Taga admin dashboard. Sign in with the details below.
    </p>

    @include('emails.partials.rows', [
        'rows' => [
            'Email' => e($user->email),
            'Temporary password' => e($password),
            'Role' => e($user->roleRelation->display_name ?? ucfirst($user->role)),
        ],
    ])

    @include('emails.partials.button', [
        'url' => $loginUrl,
        'label' => 'Sign in to the dashboard',
    ])

    <p style="{!! S::SMALL !!} margin-top:18px;">
        Change this password once you are in — it was generated for you, and anyone who sees
        this email can read it. Never share your sign-in details with anyone, including
        colleagues.
    </p>

@endsection

@section('footnote')
    You are receiving this because an administrator created a staff account for you.
@endsection
