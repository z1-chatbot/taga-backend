@php use App\Support\EmailStyle as S; @endphp
@extends('emails.layout')

@section('preheader', $intro)
@section('heading', $heading)

@section('content')

    <p style="{!! S::LEAD !!}">{{ $intro }}</p>

    @if (! empty($rows))
        @include('emails.partials.rows', ['rows' => $rows])
    @endif

    @if ($note)
        <p style="{!! S::SMALL !!} margin-top:16px;">{{ $note }}</p>
    @endif

    @if ($actionUrl && $actionLabel)
        @include('emails.partials.button', [
            'url' => $actionUrl,
            'label' => $actionLabel,
        ])
    @endif

@endsection

@section('footnote')
    Sent to you because you administer this platform.
@endsection
