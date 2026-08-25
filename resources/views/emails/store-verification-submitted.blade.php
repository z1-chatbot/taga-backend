@php use App\Support\EmailStyle as S; @endphp
@extends('emails.layout')

@section('preheader', $forReviewer
    ? $store->name . ' has submitted a pharmacy licence and is waiting on review.'
    : 'Your pharmacy licence is with our team. Nothing further is needed from you.')

@section('heading', $forReviewer
    ? 'A licence is waiting for review'
    : 'We have your pharmacy licence')

@section('content')

    @if ($forReviewer)

        <p style="{!! S::LEAD !!}">
            {{ $store->name }} has submitted a pharmacy licence for verification.
        </p>

        <p style="{!! S::BODY !!}">
            Nothing they list is purchasable until this is approved, so the wait is
            visible to them as an empty shopfront.
        </p>

        @include('emails.partials.rows', [
            'rows' => [
                'Pharmacy' => e($store->name),
                'Contact' => e($store->email ?: ($store->owner?->email ?? 'Not on file')),
                'Submitted' => $store->updated_at?->format('j F Y, g:ia') ?? 'Just now',
                'Status' => $isApplicant
                    ? 'New application'
                    : 'Renewal or resubmission',
            ],
        ])

        @include('emails.partials.button', [
            'url' => $queueUrl,
            'label' => 'Open the review queue',
        ])

    @else

        <p style="{!! S::LEAD !!}">
            Your licence has arrived and is with our team. There is nothing further for
            you to do.
        </p>

        <p style="{!! S::BODY !!}">
            We will email you as soon as it has been checked, whether it is approved or
            we need something corrected.
        </p>

        {{--
            The part a pharmacy will otherwise discover on its own, from its own
            catalogue: submitting a licence withdraws regulated-selling permission
            until the new one is approved. Saying it here is the difference between
            a known wait and an apparent fault.
        --}}
        <p style="{!! S::SUBTITLE !!} margin-top:30px;">While we check it</p>

        @include('emails.partials.rows', [
            'rows' => [
                'Over-the-counter and general products' => $isApplicant
                    ? 'Live once you are approved'
                    : '<span style="color:'.S::SUCCESS.'; font-weight:500;">Still on sale</span>',
                'Prescription-only medicines' => 'Paused until approval',
                'Controlled substances' => 'Paused until approval',
            ],
        ])

        <p style="{!! S::SMALL !!} margin-top:16px;">
            Prescription and controlled listings are held back whenever a licence is
            under review, including a straightforward renewal. They come back on as
            soon as the new licence is approved — you do not need to relist anything.
        </p>

        @if (! $isApplicant)
            @include('emails.partials.button', [
                'url' => $dashboardUrl,
                'label' => 'Open your dashboard',
            ])
        @endif

    @endif

@endsection

@section('footnote')
    @if ($forReviewer)
        Sent because a pharmacy licence is awaiting platform review.
    @else
        If anything about your licence changes before we get to it, reply to this
        email and our team will help.
    @endif
@endsection
