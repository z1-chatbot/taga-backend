@php use App\Support\EmailStyle as S; @endphp
@extends('emails.layout')

@section('preheader', $approved
    ? $store->name . ' is verified and your listings are live.'
    : 'We could not approve the licence for ' . $store->name . ' as it stands.')

@section('heading', $approved ? $store->name . ' is verified' : 'We could not verify ' . $store->name)

@section('content')

    @if ($approved)

        <p style="{!! S::LEAD !!}">
            @if ($isApplicant)
                Your pharmacy licence has been checked and approved, and your dashboard is now open.
            @else
                Your pharmacy licence has been checked and approved. Your listings are live and
                customers can buy from you.
            @endif
        </p>

        @if ($isApplicant)
            <p style="{!! S::BODY !!}">
                Sign in with the same email and password you already use on Taga, add your
                products, and they go on sale straight away.
            </p>
        @endif

        <p style="{!! S::SUBTITLE !!} margin-top:30px;">What you can sell</p>

        @include('emails.partials.rows', [
            'rows' => [
                'Over-the-counter and general products' => '<span style="color:'.S::SUCCESS.'; font-weight:500;">Yes</span>',
                'Prescription-only medicines' => $store->can_sell_prescription
                    ? '<span style="color:'.S::SUCCESS.'; font-weight:500;">Yes</span>'
                    : 'Not yet enabled',
                'Controlled substances' => $store->can_sell_controlled
                    ? '<span style="color:'.S::SUCCESS.'; font-weight:500;">Yes</span>'
                    : 'Not enabled',
            ],
        ])

        @if ($store->pharmacy_license_expiry)
            <p style="{!! S::SMALL !!} margin-top:16px;">
                Your licence is on file until {{ $store->pharmacy_license_expiry->format('j F Y') }}.
                Send us a renewal before then so your listings stay up.
            </p>
        @endif

        @include('emails.partials.button', [
            'url' => $dashboardUrl,
            'label' => 'Open your dashboard',
        ])

    @else

        <p style="{!! S::LEAD !!}">
            @if ($isApplicant)
                We have reviewed the pharmacy licence you sent and cannot approve it as it stands.
            @else
                We have reviewed the pharmacy licence you submitted and cannot approve it as it stands.
            @endif
        </p>

        <p style="{!! S::BODY !!}">
            @if ($isApplicant)
                Nothing you entered has been lost — the form still has your details when you go
                back to it.
            @else
                Your store and its products are saved. Nothing has been deleted, but they are
                not on sale.
            @endif
        </p>

        @if ($reason)
            @include('emails.partials.rows', [
                'rows' => ['Reason' => e($reason)],
            ])
        @endif

        @include('emails.partials.button', [
            'url' => $isApplicant ? $applyUrl : $dashboardUrl,
            'label' => $isApplicant ? 'Send a corrected licence' : 'Submit a corrected licence',
        ])

    @endif

@endsection

@section('footnote')
    If you have questions about this decision, reply to this email and our team will help.
@endsection
