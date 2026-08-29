@php use App\Support\EmailStyle as S; @endphp
@extends('emails.layout')

@section('preheader', 'A ' . strtolower($specialty) . ' request is waiting. Reference ' . $consultation->reference . '.')

@section('heading', 'Someone is waiting')

@section('content')

    <p style="{!! S::LEAD !!}">Hello {{ $practitionerName }},</p>

    <p style="{!! S::BODY !!}">
        A request to speak to a {{ strtolower($specialty) }} came in and is waiting for
        somebody to pick it up.
    </p>

    @include('emails.partials.rows', [
        'rows' => array_filter([
            'Reference' => e($consultation->reference),
            'Specialty' => e($specialty),
            'Subject' => e($consultation->subject),
            'Priority' => $consultation->priority === 'normal' ? null : e(ucfirst($consultation->priority)),
            'Raised' => $consultation->created_at?->format('j F Y, g:ia'),
        ]),
    ])

    @include('emails.partials.button', [
        'url' => $queueUrl,
        'label' => 'Open the request',
    ])

    {{--
        This lands in every inbox in the specialty at once, and only one of those
        people will end up handling it. Saying so is what stops two of them
        writing back to the same person with two different answers.
    --}}
    <p style="{!! S::BODY !!} margin-top:22px;">
        Everyone covering {{ strtolower($specialty) }} received this. The first to reply takes
        it on, and it will then show as yours to the rest of them — so if a colleague has
        already opened it, you will see their name against it rather than a second reply box.
    </p>

    <p style="{!! S::SMALL !!} margin-top:18px;">
        The person's message is not in this email. Only whoever takes the request on needs
        to read it.
    </p>

@endsection

@section('footnote')
    You are receiving this because you cover {{ strtolower($specialty) }} consultations on Taga.
@endsection
